<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds course/fire-timing to order line items: which course an item belongs to
 * (`course_number`, null = un-coursed) and when it was fired to the kitchen
 * (`fired_at`, null = held). A coursed item with `fired_at` null is "held".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            if (!Schema::hasColumn('order_products', 'course_number')) {
                $table->unsignedTinyInteger('course_number')->nullable();
            }
            if (!Schema::hasColumn('order_products', 'fired_at')) {
                $table->timestamp('fired_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['course_number', 'fired_at'],
                fn (string $column): bool => Schema::hasColumn('order_products', $column),
            ));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
