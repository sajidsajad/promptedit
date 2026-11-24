<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Customer-facing estimate shown before the generation ran.
            // The final charge is always recorded in customer_price.
            $table->decimal('estimated_price', 18, 9)->nullable()->after('pricing_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('estimated_price');
        });
    }
};
