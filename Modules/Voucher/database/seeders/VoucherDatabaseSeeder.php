<?php

namespace Modules\Voucher\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Voucher\Models\Voucher;

class VoucherDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Voucher::factory()->count(10)->create();
    }
}
