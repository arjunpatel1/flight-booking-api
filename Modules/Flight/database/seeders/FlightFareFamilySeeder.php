<?php

namespace Modules\Flight\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Flight\Models\FlightFareFamily;

if (! class_exists('Modules\Flight\Database\Seeders\FlightFareFamilySeeder')) {
    class FlightFareFamilySeeder extends Seeder
    {
    public function run(): void
    {
        $families = [
            [
                'code' => 'BASIC',
                'name' => 'Basic Economy',
                'cabin_class' => 'ECONOMY',
                'description' => 'Most economical option. Standard cabin seat, carry-on only, restricted changes.',
                'features' => [
                    'Standard seat selection (fee applies)',
                    'Non-refundable',
                    'Reschedule with fee',
                    'Cabin baggage only',
                ],
                'baggage_allowance' => [
                    'cabin_kg' => 7,
                    'checkin_kg' => 0,
                    'pieces' => 1,
                ],
                'changeability' => [
                    'allowed' => true,
                    'fee' => 75.00,
                ],
                'refundability' => [
                    'allowed' => false,
                    'fee' => 0.00,
                ],
                'is_active' => true,
            ],
            [
                'code' => 'STANDARD',
                'name' => 'Standard Economy',
                'cabin_class' => 'ECONOMY',
                'description' => 'Standard economy with 1 check-in bag, standard seat selection and moderate flexibility.',
                'features' => [
                    'Free standard seat selection',
                    '1 Check-in bag (15-20kg) included',
                    'Reschedule allowed with reduced fee',
                    'Complimentary snack/meal',
                ],
                'baggage_allowance' => [
                    'cabin_kg' => 7,
                    'checkin_kg' => 20,
                    'pieces' => 1,
                ],
                'changeability' => [
                    'allowed' => true,
                    'fee' => 35.00,
                ],
                'refundability' => [
                    'allowed' => true,
                    'fee' => 50.00,
                ],
                'is_active' => true,
            ],
            [
                'code' => 'FLEX',
                'name' => 'Flex Economy',
                'cabin_class' => 'ECONOMY',
                'description' => 'Maximum economy flexibility. Free changes, fully refundable option, extra baggage.',
                'features' => [
                    'Free seat selection including extra legroom',
                    '2 Check-in bags (up to 30kg total)',
                    'Free date changes up to 24h before departure',
                    'Refundable with low cancellation fee',
                    'Priority check-in',
                ],
                'baggage_allowance' => [
                    'cabin_kg' => 10,
                    'checkin_kg' => 30,
                    'pieces' => 2,
                ],
                'changeability' => [
                    'allowed' => true,
                    'fee' => 0.00,
                ],
                'refundability' => [
                    'allowed' => true,
                    'fee' => 25.00,
                ],
                'is_active' => true,
            ],
            [
                'code' => 'BUSINESS',
                'name' => 'Business Class',
                'cabin_class' => 'BUSINESS',
                'description' => 'Premium business class experience with lie-flat seating, gourmet dining and lounge access.',
                'features' => [
                    'Lie-flat or wide recliner seat',
                    'Lounge access',
                    'Priority boarding & baggage delivery',
                    'Free changes & cancellations',
                    'Gourmet multi-course dining',
                ],
                'baggage_allowance' => [
                    'cabin_kg' => 14,
                    'checkin_kg' => 40,
                    'pieces' => 2,
                ],
                'changeability' => [
                    'allowed' => true,
                    'fee' => 0.00,
                ],
                'refundability' => [
                    'allowed' => true,
                    'fee' => 0.00,
                ],
                'is_active' => true,
            ],
            [
                'code' => 'FIRST',
                'name' => 'First Class Suite',
                'cabin_class' => 'FIRST',
                'description' => 'Ultra-luxury private suite with personal concierge, premium champagne, and limousine transfers.',
                'features' => [
                    'Private closed suite',
                    'Dedicated airport concierge & chauffeur',
                    'Access to flagship first class lounges',
                    'Unrestricted flexibility',
                    'Maximum baggage allowance',
                ],
                'baggage_allowance' => [
                    'cabin_kg' => 18,
                    'checkin_kg' => 50,
                    'pieces' => 3,
                ],
                'changeability' => [
                    'allowed' => true,
                    'fee' => 0.00,
                ],
                'refundability' => [
                    'allowed' => true,
                    'fee' => 0.00,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($families as $data) {
            FlightFareFamily::updateOrCreate(
                ['code' => $data['code'], 'tenant_id' => null],
                $data
            );
        }
    }
}
}
