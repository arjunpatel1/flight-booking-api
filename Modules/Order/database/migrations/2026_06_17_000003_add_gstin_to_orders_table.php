<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gstin', 20)->nullable()->after('reference_no');
            $table->string('customer_gstin_name', 255)->nullable()->after('gstin');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'customer_gstin_name']);
        });
    }
};
