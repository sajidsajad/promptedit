<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // Exactly one wallet per user.
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // USD amounts with nine decimal places.
            // Balance includes both available and reserved funds.
            $table->decimal('balance', 18, 9)->default('0');

            // Funds temporarily locked for pending generations.
            $table->decimal('reserved_balance', 18, 9)->default('0');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
