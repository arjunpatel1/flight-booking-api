<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\Supplier;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            Supplier::factory()
                ->count(rand(2, 4))
                ->create([
                    'branch_id' => $branch->id,
                ]);
        }
    }
}
