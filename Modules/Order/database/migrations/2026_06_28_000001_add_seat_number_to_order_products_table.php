<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            if (!Schema::hasColumn('order_products', 'seat_number')) {
                $table->unsignedTinyInteger('seat_number')->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            if (Schema::hasColumn('order_products', 'seat_number')) {
                $table->dropColumn('seat_number');
            }
        });
    }
};
