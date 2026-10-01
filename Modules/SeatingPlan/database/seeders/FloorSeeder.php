<?php

namespace Modules\SeatingPlan\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\SeatingPlan\Models\Floor;

class FloorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = Branch::query()->get();

        foreach ($branches as $branch) {
            foreach ([1, 2] as $i) {
                $floor = Floor::query()
                    ->withOutGlobalBranchPermission()
                    ->withoutGlobalActive()
                    ->where('branch_id', $branch->id)
                    ->where('name->en', "Floor $i")
                    ->first();

                $floor ??= new Floor(['branch_id' => $branch->id]);

                $floor->fill([
                    'branch_id' => $branch->id,
                    'name' => ['en' => "Floor $i", 'ar' => "الطابق $i"],
                    'is_active' => true,
                ]);
                $floor->save();
            }
        }
    }
}
