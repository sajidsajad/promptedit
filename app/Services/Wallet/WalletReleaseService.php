<?php

namespace App\Services\Wallet;

use App\Models\Generation;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletReleaseService
{
    /**
     * Release the full reservation after a confirmed generation failure.
     */
    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            // Lock the wallet to prevent concurrent settlement or release.
            $wallet = Wallet::query()
                ->where('user_id', $generation->user_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException(
                    'The user does not have a wallet.'
                );
            }

            $reservationReference = "generation:{$generation->id}:reserve";
            $releaseReference = "generation:{$generation->id}:release";
            $settlementReference = "generation:{$generation->id}:settle";

            // An identical repeated release must be harmless.
            $existingRelease = WalletTransaction::query()
                ->where('reference', $releaseReference)
                ->first();

            if ($existingRelease) {
                $originalReservation = WalletTransaction::query()
                    ->where('reference', $reservationReference)
                    ->where('wallet_id', $wallet->id)
                    ->where('generation_id', $generation->id)
                    ->where('type', 'reserve')
                    ->first();

                if (
                    $existingRelease->wallet_id !== $wallet->id
                    || $existingRelease->generation_id !== $generation->id
                    || $existingRelease->type !== 'release'
                    || ! $originalReservation
                    || bccomp(
                        $existingRelease->amount,
                        $originalReservation->amount,
                        9
                    ) !== 0
                ) {
                    throw new RuntimeException(
                        'An inconsistent release transaction already exists.'
                    );
                }

                return;
            }

            // A settled generation cannot also be released.
            $existingSettlement = WalletTransaction::query()
                ->where('reference', $settlementReference)
                ->exists();

            if ($existingSettlement) {
                throw new RuntimeException(
                    'Cannot release a reservation after settlement.'
                );
            }

            // Retrieve the original reservation from the immutable ledger.
            $reservation = WalletTransaction::query()
                ->where('reference', $reservationReference)
                ->where('wallet_id', $wallet->id)
                ->where('generation_id', $generation->id)
                ->where('type', 'reserve')
                ->first();

            if (! $reservation) {
                throw new RuntimeException(
                    'Cannot release credits without an existing reservation.'
                );
            }

            $reservedAmount = $reservation->amount;

            // Never release more credits than the wallet currently holds.
            if (bccomp($wallet->reserved_balance, $reservedAmount, 9) < 0) {
                throw new RuntimeException(
                    'The wallet does not contain the expected reserved credits.'
                );
            }

            // Releasing does not deduct from the total wallet balance.
            $newReservedBalance = bcsub(
                $wallet->reserved_balance,
                $reservedAmount,
                9
            );

            $wallet->reserved_balance = $newReservedBalance;
            $wallet->save();

            // Record the release in the transaction ledger.
            $wallet->transactions()->create([
                'generation_id' => $generation->id,
                'type' => 'release',
                'amount' => $reservedAmount,
                'balance_after' => $wallet->balance,
                'reserved_balance_after' => $newReservedBalance,
                'reference' => $releaseReference,
                'description' => 'Reserved credits released after generation failure.',
            ]);
        }, 3);
    }
}
