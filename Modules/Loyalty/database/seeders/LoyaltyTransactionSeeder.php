<?php

namespace Modules\Loyalty\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTransaction;

class LoyaltyTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $program = LoyaltyProgram::active()->with("customers")->first();

        /** @var LoyaltyCustomer $loyaltyCustomer */
        foreach ($program->customers as $loyaltyCustomer) {
            LoyaltyTransaction::factory()->count(8)->create(["loyalty_customer_id" => $loyaltyCustomer->id]);
            LoyaltyTransaction::factory()->count(3)->earn()->create(["loyalty_customer_id" => $loyaltyCustomer->id]);
            LoyaltyTransaction::factory()->count(2)->redeem()->create(["loyalty_customer_id" => $loyaltyCustomer->id]);
        }
    }
}
