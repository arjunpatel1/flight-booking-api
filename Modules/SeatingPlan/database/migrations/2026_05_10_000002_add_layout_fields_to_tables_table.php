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
        Schema::table('tables', function (Blueprint $table) {
            $table->decimal('pos_x', 8, 2)->nullable()->after('shape');
            $table->decimal('pos_y', 8, 2)->nullable()->after('pos_x');
            $table->decimal('rotation', 6, 2)->default(0)->after('pos_y');
            $table->decimal('scale', 4, 2)->default(1)->after('rotation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y', 'rotation', 'scale']);
        });
    }
};
