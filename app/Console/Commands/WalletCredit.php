<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('wallet:credit {user_id} {amount} {reference}')]
#[Description('Credit a wallet in a non-production environment')]
class WalletCredit extends Command
{
    public function handle(WalletCreditService $creditService): int
    {
        // Never allow development funding in production.
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Wallet funding is only available in local or testing environments.');

            return self::FAILURE;
        }
        $userId = $this->argument('user_id');

        if (! ctype_digit((string) $userId) || (int) $userId < 1) {
            $this->error('A valid user ID is required.');

            return self::FAILURE;
        }

        $user = User::query()
            ->with('wallet')
            ->find((int) $userId);

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $wallet = $user->wallet;

        if (! $wallet) {
            $this->error('The user does not have a wallet.');

            return self::FAILURE;
        }

        $amount = (string) $this->argument('amount');
        $reference = (string) $this->argument('reference');

        if (! $this->confirm(
            "Confirm adding {$amount} USD credits to user {$user->id}?"
        )) {
            $this->info('Wallet funding cancelled.');

            return self::SUCCESS;
        }

        try {
            $creditService->credit(
                $wallet,
                $amount,
                $reference,
                'Local development test credits',
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $wallet->refresh();

        $this->info('Wallet credited successfully.');
        $this->line("User ID: {$user->id}");
        $this->line("New balance: {$wallet->balance} USD");
        $this->line("Reserved balance: {$wallet->reserved_balance} USD");

        return self::SUCCESS;
    }
}
