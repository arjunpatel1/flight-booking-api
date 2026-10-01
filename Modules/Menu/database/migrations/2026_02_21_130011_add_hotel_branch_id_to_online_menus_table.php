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
        Schema::table('online_menus', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_branch_id')->nullable()->after('branch_id');
            $table->index('hotel_branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('online_menus', function (Blueprint $table) {
            $table->dropIndex(['hotel_branch_id']);
            $table->dropColumn('hotel_branch_id');
        });
    }
};
