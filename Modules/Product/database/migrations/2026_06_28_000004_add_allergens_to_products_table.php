<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds allergen + dietary metadata to products so POS clients can warn a waiter
 * before adding an item that contains allergens, and advertise dietary labels.
 * Both are JSON arrays of stable string keys (see ProductAllergen /
 * ProductDietaryLabel enums).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'allergens')) {
                $table->json('allergens')->nullable();
            }
            if (!Schema::hasColumn('products', 'dietary_labels')) {
                $table->json('dietary_labels')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['allergens', 'dietary_labels'],
                fn (string $column): bool => Schema::hasColumn('products', $column),
            ));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
