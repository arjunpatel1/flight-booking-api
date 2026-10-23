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
        if (Schema::hasTable('flight_airports') && ! Schema::hasColumn('flight_airports', 'terminals')) {
            Schema::table('flight_airports', function (Blueprint $table) {
                $table->json('terminals')->nullable()->after('timezone');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('flight_airports') && Schema::hasColumn('flight_airports', 'terminals')) {
            Schema::table('flight_airports', function (Blueprint $table) {
                $table->dropColumn('terminals');
            });
        }
    }
};
