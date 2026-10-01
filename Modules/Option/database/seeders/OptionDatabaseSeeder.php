<?php

namespace Modules\Option\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;

class OptionDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            $this->call([
                OptionSeeder::class,
            ]);
        }
    }
}
