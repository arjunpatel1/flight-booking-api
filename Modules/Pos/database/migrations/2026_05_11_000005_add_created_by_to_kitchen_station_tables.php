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
        Schema::table('kitchen_stations', function (Blueprint $table) {
            if (!Schema::hasColumn('kitchen_stations', 'created_by')) {
                $table->createdBy()->after('id');
            }
        });

        Schema::table('kitchen_station_order_products', function (Blueprint $table) {
            if (!Schema::hasColumn('kitchen_station_order_products', 'created_by')) {
                $table->createdBy()->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kitchen_station_order_products', function (Blueprint $table) {
            if (Schema::hasColumn('kitchen_station_order_products', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });

        Schema::table('kitchen_stations', function (Blueprint $table) {
            if (Schema::hasColumn('kitchen_stations', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};
