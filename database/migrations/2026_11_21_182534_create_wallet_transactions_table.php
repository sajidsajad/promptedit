<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')
                ->constrained()
                ->cascadeOnDelete();

            // A transaction may be associated with a generation.
            $table->foreignId('generation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // credit, reserve, settle, release, refund
            $table->string('type', 20);

            // Always store positive action amounts.
            $table->decimal('amount', 18, 9);

            // Wallet snapshots immediately after this transaction.
            $table->decimal('balance_after', 18, 9);
            $table->decimal('reserved_balance_after', 18, 9);

            // Unique reference makes repeated operations idempotent.
            $table->string('reference', 191)->unique();

            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
