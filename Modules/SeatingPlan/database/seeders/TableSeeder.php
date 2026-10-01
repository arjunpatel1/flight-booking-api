<?php

namespace Modules\SeatingPlan\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zones = Zone::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->get();

        foreach ($zones as $zone) {
            for ($i = 1; $i <= 6; $i++) {
                $number = str_pad($i, 2, '0', STR_PAD_LEFT);

                $table = Table::query()
                    ->withOutGlobalBranchPermission()
                    ->withoutGlobalActive()
                    ->where('branch_id', $zone->branch_id)
                    ->where('zone_id', $zone->id)
                    ->where('name->en', "T$number")
                    ->first();

                $attributes = [
                    'floor_id' => $zone->floor_id,
                    'zone_id' => $zone->id,
                    'branch_id' => $zone->branch_id,
                    'name' => [
                        'en' => "T$number",
                        'ar' => "T$number",
                    ],
                    'is_active' => true,
                ];

                if ($table) {
                    $table->fill($attributes);
                    $table->save();

                    continue;
                }

                Table::factory()->create($attributes);
            }
        }
    }
}
