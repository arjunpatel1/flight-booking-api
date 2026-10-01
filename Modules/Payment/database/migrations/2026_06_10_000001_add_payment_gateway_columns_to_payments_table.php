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
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'gateway')) {
                $table->string('gateway')->nullable();
            }
            if (!Schema::hasColumn('payments', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id')->nullable();
            }
            if (!Schema::hasColumn('payments', 'gateway_response')) {
                $table->json('gateway_response')->nullable();
            }
            if (!Schema::hasColumn('payments', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
            if (!Schema::hasColumn('payments', 'refund_reason')) {
                $table->text('refund_reason')->nullable();
            }
            if (!Schema::hasColumn('payments', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'gateway',
                'gateway_transaction_id',
                'gateway_response',
                'processed_at',
                'refund_reason',
                'refunded_at',
            ]);
        });
    }
};
