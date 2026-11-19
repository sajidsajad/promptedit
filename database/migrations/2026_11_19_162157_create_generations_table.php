<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Pixelforge reference and duplicate-request protection
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key', 255);
            $table->string('provider_idempotency_key', 255)->nullable()->unique();
            $table->char('request_hash', 64)->nullable();

            // Original generation request
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 20);
            $table->string('status', 20)->default('queued');

            $table->json('reference_images')->nullable();
            $table->json('metadata')->nullable();

            // Immutable pricing snapshot and actual usage
            $table->json('pricing_rates');
            $table->json('usage')->nullable();

            // Exact monetary values: USD, nine decimal places
            $table->decimal('provider_cost', 18, 9)->default('0');
            $table->decimal('customer_price', 18, 9)->default('0');

            // Generated image
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            // Failure information
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Lifecycle timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Idempotency keys are unique per user
            $table->unique(
                ['user_id', 'idempotency_key'],
                'generations_user_idempotency_unique'
            );

            // Useful for filtering a user's generation history
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
