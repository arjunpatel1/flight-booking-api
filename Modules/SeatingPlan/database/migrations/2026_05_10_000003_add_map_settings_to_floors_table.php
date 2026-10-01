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
        Schema::table('floors', function (Blueprint $table) {
            $table->unsignedSmallInteger('layout_width')->default(1600)->after('name');
            $table->unsignedSmallInteger('layout_height')->default(1000)->after('layout_width');
            $table->boolean('show_grid')->default(true)->after('layout_height');
            $table->boolean('show_guide_lines')->default(true)->after('show_grid');
            $table->boolean('show_zone_labels')->default(true)->after('show_guide_lines');
            $table->boolean('show_table_labels')->default(true)->after('show_zone_labels');
            $table->boolean('compact_tables')->default(false)->after('show_table_labels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropColumn([
                'layout_width',
                'layout_height',
                'show_grid',
                'show_guide_lines',
                'show_zone_labels',
                'show_table_labels',
                'compact_tables',
            ]);
        });
    }
};
