<?php

namespace Modules\Category\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;

class CategoryDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            $this->call([
                CategorySeeder::class,
            ]);
        }
    }
}
