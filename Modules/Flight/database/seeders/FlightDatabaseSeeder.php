<?php

namespace Modules\Flight\Database\Seeders;

use Illuminate\Database\Seeder;

class FlightDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            FlightFoundationSeeder::class,
            FlightFareFamilySeeder::class,
            FlightDemoSeeder::class,
        ]);
    }
}
