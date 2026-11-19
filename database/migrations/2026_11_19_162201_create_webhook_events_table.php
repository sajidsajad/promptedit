<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Provider event ID prevents duplicate processing
            $table->string('event_id')->unique();

            // Nullable because webhooks may arrive before generation mapping
            $table->foreignId('generation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('provider_generation_id')->nullable()->index();

            $table->string('event_type', 100);
            $table->json('payload');

            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();

            $table->timestamps();

            $table->index(['generation_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
