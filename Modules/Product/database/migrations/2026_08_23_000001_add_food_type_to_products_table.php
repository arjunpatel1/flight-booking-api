<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Product\Enums\ProductFoodType;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'food_type')) {
                // Nullable means "unclassified" — the client renders no dot.
                $table->string('food_type', 16)->nullable()->after('dietary_labels');
                $table->index('food_type');
            }
        });

        // Products already tagged vegetarian carry that claim over. Everything
        // else stays null rather than being guessed as non-veg.
        DB::table('products')
            ->whereNull('food_type')
            ->whereNotNull('dietary_labels')
            ->where('dietary_labels', 'like', '%vegetarian%')
            ->update(['food_type' => ProductFoodType::Veg->value]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'food_type')) {
                $table->dropIndex(['food_type']);
                $table->dropColumn('food_type');
            }
        });
    }
};
