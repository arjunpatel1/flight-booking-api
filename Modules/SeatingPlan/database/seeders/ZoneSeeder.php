<?php

namespace Modules\SeatingPlan\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Zone;

class ZoneSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $zoneNames = [
            ['en' => 'Indoor', 'ar' => 'الداخلية'],
            ['en' => 'Outdoor', 'ar' => 'الخارجية'],
            ['en' => 'VIP', 'ar' => 'كبار الشخصيات'],
            ['en' => 'Terrace', 'ar' => 'الشرفة'],
            ['en' => 'Smoking Area', 'ar' => 'منطقة التدخين'],
            ['en' => 'Family Area', 'ar' => 'منطقة العائلات'],
            ['en' => 'Quiet Zone', 'ar' => 'منطقة الهدوء'],
            ['en' => 'Kids Zone', 'ar' => 'منطقة الأطفال'],
        ];

        $floors = Floor::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->with('branch')
            ->get();

        foreach ($floors as $floor) {
            $usedNames = collect($zoneNames)->take(3);

            foreach ($usedNames as $name) {
                $zone = Zone::query()
                    ->withOutGlobalBranchPermission()
                    ->withoutGlobalActive()
                    ->where('branch_id', $floor->branch_id)
                    ->where('floor_id', $floor->id)
                    ->where('name->en', $name['en'])
                    ->first();

                $zone ??= new Zone([
                    'branch_id' => $floor->branch_id,
                    'floor_id' => $floor->id,
                ]);

                $zone->fill([
                    'floor_id' => $floor->id,
                    'branch_id' => $floor->branch_id,
                    'name' => $name,
                    'is_active' => true,
                ]);
                $zone->save();
            }
        }
    }
}
