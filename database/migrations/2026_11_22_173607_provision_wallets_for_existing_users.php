<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();

                $wallets = $users->map(fn ($user) => [
                    'user_id' => $user->id,
                    'balance' => '0.000000000',
                    'reserved_balance' => '0.000000000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                // Avoid duplicate wallets if one already exists.
                DB::table('wallets')->insertOrIgnore($wallets);
            });
    }

    public function down(): void
    {
        // Intentionally preserve wallets and financial records.
    }
};
