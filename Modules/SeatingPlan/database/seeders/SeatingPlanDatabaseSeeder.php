<?php

namespace Modules\SeatingPlan\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;

class SeatingPlanDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            $this->call([
                FloorSeeder::class,
                ZoneSeeder::class,
                TableSeeder::class,
            ]);
        }
    }
}
