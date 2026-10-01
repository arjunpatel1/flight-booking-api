<?php

namespace Modules\Discount\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Discount\Models\Discount;

class DiscountDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Discount::factory()->count(20)->create();
    }
}
