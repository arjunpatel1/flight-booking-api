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
        Schema::table('order_discounts', function (Blueprint $table) {
            $table->foreignId('loyalty_gift_id')
                ->nullable()
                ->after('order_id')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            $table->dropColumn('loyalty_gift_id');
        });
    }
};
