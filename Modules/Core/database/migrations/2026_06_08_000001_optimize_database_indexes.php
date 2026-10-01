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
        if (Schema::hasTable('order_products')) {
            // Order products table - optimize for order lookups
            Schema::table('order_products', function (Blueprint $table) {
                if (!Schema::hasIndex('order_products', 'idx_order_products_order_created')) {
                    $table->index(['order_id', 'created_at'], 'idx_order_products_order_created');
                }
                if (!Schema::hasIndex('order_products', 'idx_order_products_product_created')) {
                    $table->index(['product_id', 'created_at'], 'idx_order_products_product_created');
                }
            });
        }

        if (Schema::hasTable('order_taxes')) {
            // Order taxes table - optimize for order lookups
            Schema::table('order_taxes', function (Blueprint $table) {
                if (!Schema::hasIndex('order_taxes', 'idx_order_taxes_order_product')) {
                    $table->index(['order_id', 'order_product_id'], 'idx_order_taxes_order_product');
                }
                if (!Schema::hasIndex('order_taxes', 'idx_order_taxes_tax_created')) {
                    $table->index(['tax_id', 'created_at'], 'idx_order_taxes_tax_created');
                }
            });
        }

        if (Schema::hasTable('categories')) {
            // Categories table - optimize for menu lookups
            Schema::table('categories', function (Blueprint $table) {
                if (!Schema::hasIndex('categories', 'idx_categories_menu_active_order')) {
                    $table->index(['menu_id', 'is_active', 'order'], 'idx_categories_menu_active_order');
                }
                if (!Schema::hasIndex('categories', 'idx_categories_parent_active_order')) {
                    $table->index(['parent_id', 'is_active', 'order'], 'idx_categories_parent_active_order');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_products')) {
            Schema::table('order_products', function (Blueprint $table) {
                if (Schema::hasIndex('order_products', 'idx_order_products_order_created')) {
                    $table->dropIndex('idx_order_products_order_created');
                }
                if (Schema::hasIndex('order_products', 'idx_order_products_product_created')) {
                    $table->dropIndex('idx_order_products_product_created');
                }
            });
        }

        if (Schema::hasTable('order_taxes')) {
            Schema::table('order_taxes', function (Blueprint $table) {
                if (Schema::hasIndex('order_taxes', 'idx_order_taxes_order_product')) {
                    $table->dropIndex('idx_order_taxes_order_product');
                }
                if (Schema::hasIndex('order_taxes', 'idx_order_taxes_tax_created')) {
                    $table->dropIndex('idx_order_taxes_tax_created');
                }
            });
        }

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (Schema::hasIndex('categories', 'idx_categories_menu_active_order')) {
                    $table->dropIndex('idx_categories_menu_active_order');
                }
                if (Schema::hasIndex('categories', 'idx_categories_parent_active_order')) {
                    $table->dropIndex('idx_categories_parent_active_order');
                }
            });
        }
    }
};
