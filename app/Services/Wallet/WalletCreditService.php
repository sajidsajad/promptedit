<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletCreditService
{
    private const MAX_BALANCE = '999999999.999999999';

    /**
     * Credit a wallet and record the operation in the ledger.
     */
    public function credit(
        Wallet $wallet,
        string $amount,
        string $reference,
        string $description
    ): void {
        $amount = $this->normalizeAmount($amount);

        // References must be non-empty and fit the database column.
        if (trim($reference) === '' || strlen($reference) > 191) {
            throw new RuntimeException(
                'The credit reference must contain between 1 and 191 characters.'
            );
        }

        DB::transaction(function () use (
            $wallet,
            $amount,
            $reference,
            $description
        ): void {
            // Lock the wallet to serialize concurrent credits.
            $lockedWallet = Wallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedWallet) {
                throw new RuntimeException(
                    'The wallet does not exist.'
                );
            }

            // References are globally unique across all wallets.
            $existingTransaction = WalletTransaction::query()
                ->where('reference', $reference)
                ->first();

            if ($existingTransaction) {
                if (
                    $existingTransaction->wallet_id !== $lockedWallet->id
                    || $existingTransaction->type !== 'credit'
                    || bccomp($existingTransaction->amount, $amount, 9) !== 0
                ) {
                    throw new RuntimeException(
                        'This credit reference has already been used differently.'
                    );
                }

                // Identical retries must never credit twice.
                return;
            }

            // Calculate using BCMath to avoid floating-point errors.
            $newBalance = bcadd(
                $lockedWallet->balance,
                $amount,
                9
            );

            // DECIMAL(18,9) supports at most nine integer digits.
            if (bccomp($newBalance, self::MAX_BALANCE, 9) > 0) {
                throw new RuntimeException(
                    'The credit would exceed the maximum wallet balance.'
                );
            }

            $lockedWallet->balance = $newBalance;
            $lockedWallet->save();

            // Record the immutable financial ledger entry.
            $lockedWallet->transactions()->create([
                'generation_id' => null,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reserved_balance_after' => $lockedWallet->reserved_balance,
                'reference' => $reference,
                'description' => $description,
            ]);
        }, 3);
    }

    /**
     * Validate and normalize a positive monetary amount.
     */
    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,9})?$/', $amount)) {
            throw new RuntimeException(
                'The credit amount must be a valid positive decimal.'
            );
        }

        $normalizedAmount = bcadd($amount, '0', 9);

        if (bccomp($normalizedAmount, '0', 9) <= 0) {
            throw new RuntimeException(
                'The credit amount must be greater than zero.'
            );
        }

        // Reject values that cannot fit DECIMAL(18,9).
        if (bccomp($normalizedAmount, self::MAX_BALANCE, 9) > 0) {
            throw new RuntimeException(
                'The credit amount exceeds the maximum wallet balance.'
            );
        }

        return $normalizedAmount;
    }
}
