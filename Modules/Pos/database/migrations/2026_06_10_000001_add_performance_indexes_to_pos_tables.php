<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasTable('order_products') && !Schema::hasIndex('order_products', 'idx_order_product_status')) {
            // Add index to order_products table for performance
            Schema::table('order_products', function (Blueprint $table) {
                $table->index(['order_id', 'product_id', 'status'], 'idx_order_product_status');
            });
        }

        if (Schema::hasTable('pos_terminal_devices') && !Schema::hasIndex('pos_terminal_devices', 'idx_device_last_seen')) {
            // Add index to pos_terminal_devices table for performance
            Schema::table('pos_terminal_devices', function (Blueprint $table) {
                $table->index(['device_id', 'last_seen_at'], 'idx_device_last_seen');
            });
        }

        if (Schema::hasTable('orders') && !Schema::hasIndex('orders', 'idx_branch_date_status')) {
            // Add index to orders table for performance
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['branch_id', 'order_date', 'status'], 'idx_branch_date_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('order_products') && Schema::hasIndex('order_products', 'idx_order_product_status')) {
            Schema::table('order_products', function (Blueprint $table) {
                $table->dropIndex('idx_order_product_status');
            });
        }

        if (Schema::hasTable('pos_terminal_devices') && Schema::hasIndex('pos_terminal_devices', 'idx_device_last_seen')) {
            Schema::table('pos_terminal_devices', function (Blueprint $table) {
                $table->dropIndex('idx_device_last_seen');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasIndex('orders', 'idx_branch_date_status')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('idx_branch_date_status');
            });
        }
    }
};
