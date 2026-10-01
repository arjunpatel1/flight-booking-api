<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('branch_daily_business_summaries', function (Blueprint $table) {
            $table->unsignedInteger('completed_paid_orders')->default(0)->after('total_orders');
            $table->unsignedInteger('pending_orders')->default(0)->after('completed_paid_orders');
            $table->decimal('payment_ledger_total', 18, 4)->default(0)->after('net_sales');
            $table->decimal('reconciliation_difference', 18, 4)->default(0)->after('payment_ledger_total');
        });
    }

    public function down(): void
    {
        Schema::table('branch_daily_business_summaries', function (Blueprint $table) {
            $table->dropColumn(['completed_paid_orders', 'pending_orders', 'payment_ledger_total', 'reconciliation_difference']);
        });
    }
};
