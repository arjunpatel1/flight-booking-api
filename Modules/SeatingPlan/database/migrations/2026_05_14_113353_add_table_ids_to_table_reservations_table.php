<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('table_reservations', function (Blueprint $table) {
            if (!Schema::hasColumn('table_reservations', 'table_ids')) {
                $table->json('table_ids')->nullable()->after('table_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('table_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('table_reservations', 'table_ids')) {
                $table->dropColumn('table_ids');
            }
        });
    }
};
