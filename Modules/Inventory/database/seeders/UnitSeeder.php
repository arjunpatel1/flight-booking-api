<?php

namespace Modules\Inventory\Database\Seeders;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Seeder;
use Modules\Inventory\Enums\UnitType;

class UnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $units = [
            // Mass
            ['name' => ['en' => 'Gram', 'ar' => 'غرام'], 'symbol' => ['en' => 'g', 'ar' => 'غ'], 'type' => UnitType::Mass->value],
            ['name' => ['en' => 'Kilogram', 'ar' => 'كيلوغرام'], 'symbol' => ['en' => 'kg', 'ar' => 'كغ'], 'type' => UnitType::Mass->value],
            ['name' => ['en' => 'Milligram', 'ar' => 'مليغرام'], 'symbol' => ['en' => 'mg', 'ar' => 'ملغ'], 'type' => UnitType::Mass->value],
            ['name' => ['en' => 'Ounce', 'ar' => 'أونصة'], 'symbol' => ['en' => 'oz', 'ar' => 'أونصة'], 'type' => UnitType::Mass->value],
            ['name' => ['en' => 'Pound', 'ar' => 'رطل'], 'symbol' => ['en' => 'lb', 'ar' => 'رطل'], 'type' => UnitType::Mass->value],

            // Volume
            ['name' => ['en' => 'Milliliter', 'ar' => 'ملليلتر'], 'symbol' => ['en' => 'ml', 'ar' => 'مل'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Liter', 'ar' => 'لتر'], 'symbol' => ['en' => 'L', 'ar' => 'ل'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Teaspoon', 'ar' => 'ملعقة صغيرة'], 'symbol' => ['en' => 'tsp', 'ar' => 'ص.م'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Tablespoon', 'ar' => 'ملعقة كبيرة'], 'symbol' => ['en' => 'tbsp', 'ar' => 'ك.م'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Cup', 'ar' => 'كوب'], 'symbol' => ['en' => 'cup', 'ar' => 'كوب'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Pint', 'ar' => 'باينت'], 'symbol' => ['en' => 'pt', 'ar' => 'باينت'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Quart', 'ar' => 'كوارت'], 'symbol' => ['en' => 'qt', 'ar' => 'كوارت'], 'type' => UnitType::Volume->value],
            ['name' => ['en' => 'Gallon', 'ar' => 'جالون'], 'symbol' => ['en' => 'gal', 'ar' => 'جالون'], 'type' => UnitType::Volume->value],

            // Count
            ['name' => ['en' => 'Piece', 'ar' => 'قطعة'], 'symbol' => ['en' => 'pc', 'ar' => 'قط'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Pack', 'ar' => 'علبة'], 'symbol' => ['en' => 'pack', 'ar' => 'ع'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Box', 'ar' => 'صندوق'], 'symbol' => ['en' => 'box', 'ar' => 'ص'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Bottle', 'ar' => 'زجاجة'], 'symbol' => ['en' => 'bottle', 'ar' => 'ز'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Can', 'ar' => 'علبة معدنية'], 'symbol' => ['en' => 'can', 'ar' => 'ع.م'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Slice', 'ar' => 'شريحة'], 'symbol' => ['en' => 'slice', 'ar' => 'ش'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Roll', 'ar' => 'لفافة'], 'symbol' => ['en' => 'roll', 'ar' => 'ل'], 'type' => UnitType::Count->value],
            ['name' => ['en' => 'Dozen', 'ar' => 'درزن'], 'symbol' => ['en' => 'doz', 'ar' => 'درز'], 'type' => UnitType::Count->value],

            // Custom
            ['name' => ['en' => 'Serving', 'ar' => 'حصة'], 'symbol' => ['en' => 'srv', 'ar' => 'ح'], 'type' => UnitType::Custom->value],
            ['name' => ['en' => 'Unit', 'ar' => 'وحدة'], 'symbol' => ['en' => 'unit', 'ar' => 'و'], 'type' => UnitType::Custom->value],
            ['name' => ['en' => 'Tray', 'ar' => 'صينية'], 'symbol' => ['en' => 'tray', 'ar' => 'ص.ي'], 'type' => UnitType::Custom->value],
            ['name' => ['en' => 'Bag', 'ar' => 'كيس'], 'symbol' => ['en' => 'bag', 'ar' => 'ك'], 'type' => UnitType::Custom->value],
        ];

        foreach ($units as $unit) {
            DB::table('units')->insert([
                'name' => json_encode($unit['name'], JSON_UNESCAPED_UNICODE),
                'symbol' => json_encode($unit['symbol'], JSON_UNESCAPED_UNICODE),
                'type' => $unit['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
