<?php

namespace Modules\Product\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;

class ProductDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (NexDine::seedDemoData()) {
            $this->call([
                ProductSeeder::class,
            ]);
        }
    }
}
