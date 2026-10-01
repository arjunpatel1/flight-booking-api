<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyPromotion;

class LoyaltyPromotionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** @var LoyaltyProgram $program */
        foreach (LoyaltyProgram::query()->withoutGlobalActive()->get() as $program) {
            LoyaltyPromotion::factory()->count(3)
                ->create([
                    "loyalty_program_id" => $program->id,
                ]);
        }
    }
}
