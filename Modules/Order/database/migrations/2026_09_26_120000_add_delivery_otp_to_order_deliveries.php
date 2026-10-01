<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_deliveries', 'delivery_otp')) {
                $table->text('delivery_otp')->nullable()->after('rider_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            if (Schema::hasColumn('order_deliveries', 'delivery_otp')) {
                $table->dropColumn('delivery_otp');
            }
        });
    }
};
