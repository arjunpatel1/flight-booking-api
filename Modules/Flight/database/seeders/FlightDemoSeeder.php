<?php

namespace Modules\Flight\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Flight\Enums\AncillaryCategory;
use Modules\Flight\Models\FlightAircraft;
use Modules\Flight\Models\FlightAirline;
use Modules\Flight\Models\FlightAirport;
use Modules\Flight\Models\FlightAncillary;
use Modules\Flight\Models\FlightFare;
use Modules\Flight\Models\FlightFareFamily;
use Modules\Flight\Models\FlightInventory;
use Modules\Flight\Models\FlightRoute;
use Modules\Flight\Models\FlightSchedule;
use Modules\Saas\Models\Tenant;

class FlightDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();
        $tenantId = $tenant?->id ?? 1;

        // 1. Ensure Airports
        $del = FlightAirport::firstOrCreate(['iata_code' => 'DEL'], [
            'name' => 'Indira Gandhi International Airport',
            'city' => 'New Delhi',
            'city_code' => 'DEL',
            'country' => 'India',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'terminals' => ['T1', 'T2', 'T3'],
            'is_active' => true,
        ]);

        $bom = FlightAirport::firstOrCreate(['iata_code' => 'BOM'], [
            'name' => 'Chhatrapati Shivaji Maharaj International Airport',
            'city' => 'Mumbai',
            'city_code' => 'BOM',
            'country' => 'India',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'terminals' => ['T1', 'T2'],
            'is_active' => true,
        ]);

        $blr = FlightAirport::firstOrCreate(['iata_code' => 'BLR'], [
            'name' => 'Kempegowda International Airport',
            'city' => 'Bengaluru',
            'city_code' => 'BLR',
            'country' => 'India',
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'terminals' => ['T1', 'T2'],
            'is_active' => true,
        ]);

        // 2. Ensure Airlines
        $ai = FlightAirline::firstOrCreate(['iata_code' => 'AI'], [
            'name' => 'Air India',
            'icao_code' => 'AIC',
            'is_active' => true,
        ]);

        $igo = FlightAirline::firstOrCreate(['iata_code' => '6E'], [
            'name' => 'IndiGo',
            'icao_code' => 'IGO',
            'is_active' => true,
        ]);

        // 3. Ensure Aircraft
        $a320 = FlightAircraft::firstOrCreate(['iata_code' => '32N'], [
            'model' => 'Airbus A320neo',
            'manufacturer' => 'Airbus',
            'total_seats' => 180,
            'is_active' => true,
        ]);

        $a321 = FlightAircraft::firstOrCreate(['iata_code' => '321'], [
            'model' => 'Airbus A321neo',
            'manufacturer' => 'Airbus',
            'total_seats' => 220,
            'is_active' => true,
        ]);

        // 4. Ensure Routes
        $routeDelBom = FlightRoute::firstOrCreate([
            'tenant_id' => $tenantId,
            'origin_airport_id' => $del->id,
            'destination_airport_id' => $bom->id,
        ], [
            'distance_km' => 1148,
            'flight_duration_minutes' => 135,
            'is_active' => true,
        ]);

        $routeDelBlr = FlightRoute::firstOrCreate([
            'tenant_id' => $tenantId,
            'origin_airport_id' => $del->id,
            'destination_airport_id' => $blr->id,
        ], [
            'distance_km' => 1740,
            'flight_duration_minutes' => 165,
            'is_active' => true,
        ]);

        // 5. Ensure Schedules
        $schedAi505 = FlightSchedule::firstOrCreate([
            'tenant_id' => $tenantId,
            'route_id' => $routeDelBom->id,
            'flight_number' => 'AI-505',
        ], [
            'airline_id' => $ai->id,
            'departure_time' => '06:00:00',
            'arrival_time' => '08:15:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => Carbon::today(),
            'valid_until' => Carbon::today()->addYear(),
            'is_active' => true,
        ]);

        $sched6e202 = FlightSchedule::firstOrCreate([
            'tenant_id' => $tenantId,
            'route_id' => $routeDelBlr->id,
            'flight_number' => '6E-202',
        ], [
            'airline_id' => $igo->id,
            'departure_time' => '09:30:00',
            'arrival_time' => '12:15:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => Carbon::today(),
            'valid_until' => Carbon::today()->addYear(),
            'is_active' => true,
        ]);

        // 6. Ensure Inventories (Flight Inventory 1 and 2)
        $inv1 = FlightInventory::updateOrCreate(['id' => 1], [
            'tenant_id' => $tenantId,
            'inventory_id' => 'INV-AI505-001',
            'airline_id' => $ai->id,
            'aircraft_id' => $a320->id,
            'flight_number' => 'AI-505',
            'origin_airport_id' => $del->id,
            'destination_airport_id' => $bom->id,
            'departure_time' => Carbon::now()->addDays(2)->setTime(6, 0)->toDateTimeString(),
            'arrival_time' => Carbon::now()->addDays(2)->setTime(8, 15)->toDateTimeString(),
            'operating_days' => [1, 2, 3, 4, 5, 6, 7],
            'cabin_class' => 'ECONOMY',
            'total_seats' => 180,
            'available_seats' => 165,
            'status' => 'active',
            'base_price' => 4500.00,
            'is_refundable' => true,
        ]);

        $inv2 = FlightInventory::updateOrCreate(['id' => 2], [
            'tenant_id' => $tenantId,
            'inventory_id' => 'INV-6E202-002',
            'airline_id' => $igo->id,
            'aircraft_id' => $a321->id,
            'flight_number' => '6E-202',
            'origin_airport_id' => $del->id,
            'destination_airport_id' => $blr->id,
            'departure_time' => Carbon::now()->addDays(3)->setTime(9, 30)->toDateTimeString(),
            'arrival_time' => Carbon::now()->addDays(3)->setTime(12, 15)->toDateTimeString(),
            'operating_days' => [1, 2, 3, 4, 5, 6, 7],
            'cabin_class' => 'ECONOMY',
            'total_seats' => 220,
            'available_seats' => 198,
            'status' => 'active',
            'base_price' => 5200.00,
            'is_refundable' => true,
        ]);

        // 7. Ensure Fare Families & Fares
        $standardFamily = FlightFareFamily::firstOrCreate(['code' => 'STANDARD'], [
            'tenant_id' => $tenantId,
            'name' => 'Standard Economy',
            'cabin_class' => 'ECONOMY',
            'is_active' => true,
        ]);

        FlightFare::firstOrCreate(['flight_inventory_id' => $inv1->id, 'fare_basis_code' => 'AI505STD'], [
            'tenant_id' => $tenantId,
            'fare_family_id' => $standardFamily->id,
            'cabin_class' => 'ECONOMY',
            'supplier_fare' => 4200.00,
            'base_fare' => 4500.00,
            'taxes' => 450.00,
            'platform_markup' => 150.00,
            'tenant_markup' => 200.00,
            'final_customer_price' => 5300.00,
            'currency' => 'INR',
            'is_refundable' => true,
            'is_changeable' => true,
            'status' => 'active',
            'valid_from' => Carbon::now()->subDay(),
            'valid_until' => Carbon::now()->addMonths(2),
        ]);

        FlightFare::firstOrCreate(['flight_inventory_id' => $inv2->id, 'fare_basis_code' => '6E202STD'], [
            'tenant_id' => $tenantId,
            'fare_family_id' => $standardFamily->id,
            'cabin_class' => 'ECONOMY',
            'supplier_fare' => 4900.00,
            'base_fare' => 5200.00,
            'taxes' => 520.00,
            'platform_markup' => 180.00,
            'tenant_markup' => 250.00,
            'final_customer_price' => 6150.00,
            'currency' => 'INR',
            'is_refundable' => true,
            'is_changeable' => true,
            'status' => 'active',
            'valid_from' => Carbon::now()->subDay(),
            'valid_until' => Carbon::now()->addMonths(2),
        ]);

        // 8. Seed Standard Ancillary Catalog
        $ancillaries = [
            // Baggage
            [
                'code' => 'BAG-5KG',
                'category' => AncillaryCategory::BAGGAGE,
                'name' => 'Extra 5kg Check-in Baggage',
                'description' => 'Pre-purchase additional 5kg baggage allowance with standard luggage handling.',
                'price' => 1500.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 2,
                'available_quantity' => 100,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'BAG-10KG',
                'category' => AncillaryCategory::BAGGAGE,
                'name' => 'Extra 10kg Check-in Baggage',
                'description' => 'Pre-purchase additional 10kg baggage allowance at discounted tier rates.',
                'price' => 2800.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 2,
                'available_quantity' => 100,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'BAG-15KG',
                'category' => AncillaryCategory::BAGGAGE,
                'name' => 'Extra 15kg Check-in Baggage',
                'description' => 'Heavy piece allowance, ideal for long holidays and extra equipment.',
                'price' => 3900.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 50,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            // Meals
            [
                'code' => 'MEAL-VGML',
                'category' => AncillaryCategory::MEAL,
                'name' => 'Vegetarian Hindu Meal (AVML)',
                'description' => 'Spiced vegetarian hot meal prepared with seasonal vegetables, lentils and rice.',
                'price' => 450.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 150,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'MEAL-VGN',
                'category' => AncillaryCategory::MEAL,
                'name' => 'Vegan Gourmet Box (VGML)',
                'description' => '100% plant-based meal with organic grains, roasted seasonal veggies and hummus dip.',
                'price' => 480.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 80,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'MEAL-JNML',
                'category' => AncillaryCategory::MEAL,
                'name' => 'Jain Vegetarian Meal (VJML)',
                'description' => 'Strict vegetarian meal prepared without root vegetables, onions, or garlic.',
                'price' => 450.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 100,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'MEAL-CHML',
                'category' => AncillaryCategory::MEAL,
                'name' => 'Continental Breakfast Tray',
                'description' => 'Fresh croissants, artisan fruit preserves, yoghurt, cut fruits and orange juice.',
                'price' => 400.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 120,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            // Lounge & Priority Services
            [
                'code' => 'SRV-LNG',
                'category' => AncillaryCategory::LOUNGE,
                'name' => 'Executive Airport Lounge Access',
                'description' => '3-hour access to airport lounge with complimentary buffet, beverages and shower facilities.',
                'price' => 1200.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 50,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
            [
                'code' => 'SRV-PRBD',
                'category' => AncillaryCategory::SPECIAL_ASSISTANCE,
                'name' => 'Priority Boarding & Express Security',
                'description' => 'Dedicated priority check-in counter and Zone 1 boarding sequence.',
                'price' => 400.00,
                'currency' => 'INR',
                'max_quantity_per_pax' => 1,
                'available_quantity' => 40,
                'status' => 'ACTIVE',
                'supplier_code' => 'INTERNAL',
                'tenant_id' => $tenantId,
            ],
        ];

        foreach ($ancillaries as $ancillary) {
            FlightAncillary::updateOrCreate(
                ['code' => $ancillary['code']],
                $ancillary
            );
        }
    }
}
