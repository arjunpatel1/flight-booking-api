<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Loyalty\Models\LoyaltyProgram;

class LoyaltyProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        LoyaltyProgram::factory()->count(3)->create();
        LoyaltyProgram::factory()->active()->create();
    }
}
