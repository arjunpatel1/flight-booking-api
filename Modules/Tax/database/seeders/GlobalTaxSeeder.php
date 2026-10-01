<?php

namespace Modules\Tax\Database\Seeders;


use Illuminate\Database\Seeder;
use Modules\Order\Enums\OrderType;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;

class GlobalTaxSeeder extends Seeder
{
    public function run(): void
    {
        $globalTaxes = [
            [
                'name' => [
                    "en" => 'VAT 15%',
                    'ar' => 'ضريبة القيمة المضافة 15%'
                ],
                'code' => 'VAT_15',
                'rate' => 15.0,
                'type' => TaxType::Exclusive,
                'compound' => false,
                'is_global' => true
            ],
            [
                'name' => [
                    "en" => 'Service Charge 10%',
                    'ar' => 'رسوم الخدمة 10%'
                ],
                'code' => 'SERVICE_10_GLOBAL',
                'rate' => 10.0,
                'type' => TaxType::Exclusive,
                'compound' => true,
                'is_global' => true,
                'order_types' => [OrderType::DineIn->value],
            ],
        ];

        foreach ($globalTaxes as $tax) {
            Tax::query()
                ->firstOrCreate(
                    ['code' => $tax['code']],
                    [...$tax, 'is_active' => true]
                );
        }
    }
}
