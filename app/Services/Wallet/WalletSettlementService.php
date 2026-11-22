<?php

namespace App\Services\Wallet;

use App\Models\Generation;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletSettlementService
{
    /**
     * Settle a successful generation using its actual customer charge.
     *
     * Deducts the actual charge from the wallet balance and releases
     * the full amount that was originally reserved.
     */
    public function settle(Generation $generation, string $amount): void
    {
        $amount = $this->normalizeAmount($amount);

        DB::transaction(function () use ($generation, $amount): void {
            $wallet = Wallet::query()
                ->where('user_id', $generation->user_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException(
                    'The user does not have a wallet.'
                );
            }

            $settlementReference = "generation:{$generation->id}:settle";

            // An identical retry must never charge the customer twice.
            $existingSettlement = WalletTransaction::query()
                ->where('reference', $settlementReference)
                ->first();

            if ($existingSettlement) {
                if (
                    $existingSettlement->wallet_id !== $wallet->id
                    || $existingSettlement->generation_id !== $generation->id
                    || $existingSettlement->type !== 'settle'
                    || bccomp($existingSettlement->amount, $amount, 9) !== 0
                ) {
                    throw new RuntimeException(
                        'This generation has already been settled with a different amount.'
                    );
                }

                return;
            }

            // A released reservation can never be settled afterward.
            $existingRelease = WalletTransaction::query()
                ->where('reference', "generation:{$generation->id}:release")
                ->where('wallet_id', $wallet->id)
                ->where('generation_id', $generation->id)
                ->where('type', 'release')
                ->exists();

            if ($existingRelease) {
                throw new RuntimeException(
                    'Cannot settle a generation after its reservation has been released.'
                );
            }

            // A settlement is only valid if a reservation exists.
            $reservation = WalletTransaction::query()
                ->where('reference', "generation:{$generation->id}:reserve")
                ->where('wallet_id', $wallet->id)
                ->where('generation_id', $generation->id)
                ->where('type', 'reserve')
                ->first();

            if (! $reservation) {
                throw new RuntimeException(
                    'Cannot settle a generation without an existing reservation.'
                );
            }

            $reservedAmount = $reservation->amount;

            // The actual charge must not exceed the reserved amount.
            if (bccomp($amount, $reservedAmount, 9) > 0) {
                throw new RuntimeException(
                    'The actual charge exceeds the reserved amount.'
                );
            }

            // Confirm the reservation is still held by the wallet.
            if (bccomp($wallet->reserved_balance, $reservedAmount, 9) < 0) {
                throw new RuntimeException(
                    'The wallet does not contain the expected reserved credits.'
                );
            }

            // Deduct only the actual charge.
            $newBalance = bcsub(
                $wallet->balance,
                $amount,
                9
            );

            // Release the entire original reservation.
            $newReservedBalance = bcsub(
                $wallet->reserved_balance,
                $reservedAmount,
                9
            );

            if (
                bccomp($newBalance, '0', 9) < 0
                || bccomp($newReservedBalance, '0', 9) < 0
            ) {
                throw new RuntimeException(
                    'Settlement would create an invalid wallet balance.'
                );
            }

            $wallet->balance = $newBalance;
            $wallet->reserved_balance = $newReservedBalance;
            $wallet->save();

            // Record the actual charge in the transaction ledger.
            $wallet->transactions()->create([
                'generation_id' => $generation->id,
                'type' => 'settle',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reserved_balance_after' => $newReservedBalance,
                'reference' => $settlementReference,
                'description' => 'Actual generation cost settled.',
            ]);
        }, 3);
    }

    /**
     * Validate and normalize a monetary amount to nine decimal places.
     */
    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,9})?$/', $amount)) {
            throw new RuntimeException(
                'The settlement amount must be a valid positive decimal.'
            );
        }

        $normalizedAmount = bcadd($amount, '0', 9);

        if (bccomp($normalizedAmount, '0', 9) <= 0) {
            throw new RuntimeException(
                'The settlement amount must be greater than zero.'
            );
        }

        return $normalizedAmount;
    }
}
