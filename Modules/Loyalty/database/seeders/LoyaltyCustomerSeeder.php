<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class LoyaltyCustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $program = LoyaltyProgram::active()->first();

        foreach (User::query()
                     ->role(DefaultRole::Customer)
                     ->withoutGlobalActive()->get() as $user) {
            LoyaltyCustomer::factory()->create([
                "loyalty_program_id" => $program->id,
                "customer_id" => $user->id,
                "loyalty_tier_id" => $program->tiers()->inRandomOrder()->first()->id,
            ]);
        }
    }
}
