<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_recommended')->default(false)->after('is_available');
            $table->boolean('is_best_seller')->default(false)->after('is_recommended');
            $table->unsignedSmallInteger('display_priority')->default(0)->after('is_best_seller');
            $table->index(['menu_id', 'is_recommended'], 'products_menu_recommended_index');
            $table->index(['menu_id', 'is_best_seller'], 'products_menu_best_seller_index');
            $table->index(['menu_id', 'display_priority'], 'products_menu_priority_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_menu_recommended_index');
            $table->dropIndex('products_menu_best_seller_index');
            $table->dropIndex('products_menu_priority_index');
            $table->dropColumn(['is_recommended', 'is_best_seller', 'display_priority']);
        });
    }
};
