<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderType;
use Modules\Pricing\Enums\PriceTypeRuleType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('orders') && DB::getDriverName() === 'mysql') {
            $values = collect(OrderType::values())
                ->map(fn(string $value) => "'" . str_replace("'", "''", $value) . "'")
                ->implode(',');

            DB::statement("ALTER TABLE orders MODIFY type ENUM($values) NOT NULL");
        }

        if (Schema::hasTable('branches')) {
            DB::table('branches')
                ->select('id', 'order_types')
                ->orderBy('id')
                ->get()
                ->each(function (object $branch) {
                    $orderTypes = json_decode($branch->order_types ?: '[]', true);

                    if (! is_array($orderTypes)) {
                        $orderTypes = [];
                    }

                    $orderTypes = collect($orderTypes)
                        ->merge([OrderType::SelfService->value, OrderType::Delivery->value])
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update(['order_types' => json_encode($orderTypes)]);
                });
        }

        if (Schema::hasTable('price_types')) {
            DB::table('price_types')->updateOrInsert(
                ['code' => 'SELF_SERVICE'],
                [
                    'name' => json_encode(['en' => 'Self Service', 'ar' => 'خدمة ذاتية']),
                    'rule_type' => PriceTypeRuleType::Fixed->value,
                    'rule_value' => 0,
                    'description' => json_encode([
                        'en' => 'Product-level self-service pricing used by POS self-service orders.',
                        'ar' => 'تسعير المنتجات للخدمة الذاتية المستخدم في طلبات نقاط البيع.',
                    ]),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
