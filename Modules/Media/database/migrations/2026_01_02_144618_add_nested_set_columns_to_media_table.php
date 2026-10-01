<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kalnoy\Nestedset\NestedSet;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedInteger(NestedSet::LFT)->default(0)->after('disk');
            $table->unsignedInteger(NestedSet::RGT)->default(0)->after(NestedSet::LFT);
            $table->index([NestedSet::LFT, NestedSet::RGT]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $columns = [NestedSet::LFT, NestedSet::RGT];

            $table->dropIndex($columns);
            $table->dropColumn($columns);
        });
    }
};
