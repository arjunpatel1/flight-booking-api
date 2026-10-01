<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('kot_finalized_at')->nullable()->after('payment_at');
            $table->foreignId('kot_finalized_by')->nullable()->constrained('users')->nullOnDelete()->after('kot_finalized_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['kot_finalized_by']);
            $table->dropColumn(['kot_finalized_at', 'kot_finalized_by']);
        });
    }
};
