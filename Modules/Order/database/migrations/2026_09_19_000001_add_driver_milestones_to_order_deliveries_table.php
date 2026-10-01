<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            $table->timestamp('arrived_at_pickup_at')->nullable()->after('rider_assigned_at');
            $table->timestamp('arrived_at_customer_at')->nullable()->after('picked_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['arrived_at_pickup_at', 'arrived_at_customer_at']);
        });
    }
};
