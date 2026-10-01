<?php

namespace Modules\Inventory\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;

class InventoryDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            ...(NexDine::seedDemoData()
                ? [
                    SupplierSeeder::class,
                    IngredientSeeder::class,
                    PurchaseSeeder::class,
                    StockMovementSeeder::class,
                ] :
                [])
        ]);
    }
}
