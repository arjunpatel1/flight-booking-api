<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->index('gst_type', 'taxes_gst_type_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('gstin', 'orders_gstin_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index('hsn_code', 'products_hsn_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('taxes_gst_type_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_gstin_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_hsn_code_idx');
        });
    }
};
