<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Payment\Models\Payment;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table) {
            $table->foreignIdFor(Payment::class)->nullable()->after("order_id")->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_cash_movements', function (Blueprint $table) {
            if (Schema::hasTable('pos_cash_movements')) {
                $table->dropForeign(['payment_id']);
                $table->dropColumn('payment_id');
            }
        });
    }
};
