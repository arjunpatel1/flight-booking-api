<?php

namespace Modules\Currency\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Currency\Models\CurrencyRate;
use Modules\Setting\Models\Setting;

class CurrencyDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currency = Setting::get('default_currency') ?: config('app.currency', 'INR');

        CurrencyRate::query()
            ->updateOrCreate(
                ['currency' => $currency],
                ['rate' => 1]
            );
    }
}
