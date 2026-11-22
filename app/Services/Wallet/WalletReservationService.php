<?php

namespace App\Services\Wallet;

use App\Exceptions\InsufficientWalletCreditsException;
use App\Models\Generation;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletReservationService
{
    /**
     * Reserve customer credits for a generation.
     *
     * The wallet's total balance remains unchanged.
     * Only the reserved balance increases.
     */
    public function reserve(Generation $generation, string $amount): void
    {
        $amount = $this->normalizeAmount($amount);

        DB::transaction(function () use ($generation, $amount): void {
            // Lock the wallet so concurrent reservations cannot
            // spend the same available credits.
            $wallet = Wallet::query()
                ->where('user_id', $generation->user_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException(
                    'The user does not have a wallet.'
                );
            }

            // One deterministic reference per generation reservation.
            $reference = "generation:{$generation->id}:reserve";

            // Check whether this generation has already been reserved.
            $existingTransaction = WalletTransaction::query()
                ->where('reference', $reference)
                ->first();

            if ($existingTransaction) {
                if (
                    $existingTransaction->wallet_id !== $wallet->id
                    || $existingTransaction->generation_id !== $generation->id
                    || $existingTransaction->type !== 'reserve'
                    || bccomp($existingTransaction->amount, $amount, 9) !== 0
                ) {
                    throw new RuntimeException(
                        'This generation already has a different reservation.'
                    );
                }

                // An identical retry must not reserve credits again.
                return;
            }

            // Available = total balance - already reserved balance.
            $availableBalance = bcsub(
                $wallet->balance,
                $wallet->reserved_balance,
                9
            );

            if (bccomp($availableBalance, $amount, 9) < 0) {
                throw new InsufficientWalletCreditsException(
                    'Insufficient available wallet credits.'
                );
            }
            $newReservedBalance = bcadd(
                $wallet->reserved_balance,
                $amount,
                9
            );

            // Reserve credits without deducting the total balance.
            $wallet->reserved_balance = $newReservedBalance;
            $wallet->save();

            // Record the reservation in the immutable transaction ledger.
            $wallet->transactions()->create([
                'generation_id' => $generation->id,
                'type' => 'reserve',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'reserved_balance_after' => $newReservedBalance,
                'reference' => $reference,
                'description' => 'Credits reserved for generation.',
            ]);
        }, 3);
    }

    /**
     * Validate and normalize a monetary amount to nine decimal places.
     */
    private function normalizeAmount(string $amount): string
    {
        // Accept ordinary decimal notation only.
        // Reject negatives, scientific notation and excess precision.
        if (! preg_match('/^\d+(?:\.\d{1,9})?$/', $amount)) {
            throw new RuntimeException(
                'The reservation amount must be a valid positive decimal.'
            );
        }

        $normalizedAmount = bcadd($amount, '0', 9);

        if (bccomp($normalizedAmount, '0', 9) <= 0) {
            throw new RuntimeException(
                'The reservation amount must be greater than zero.'
            );
        }

        return $normalizedAmount;
    }
}
