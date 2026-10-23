<?php

namespace Modules\Flight\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Flight\Models\FlightAircraft;
use Modules\Flight\Models\FlightAirline;
use Modules\Flight\Models\FlightAirport;

class FlightFoundationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Major International & Domestic Airports
        $airports = [
            [
                'iata_code' => 'DEL',
                'icao_code' => 'VIDP',
                'name' => 'Indira Gandhi International Airport',
                'city' => 'New Delhi',
                'city_code' => 'DEL',
                'country' => 'India',
                'country_code' => 'IN',
                'latitude' => 28.5561600,
                'longitude' => 77.1002800,
                'timezone' => 'Asia/Kolkata',
            ],
            [
                'iata_code' => 'BOM',
                'icao_code' => 'VABB',
                'name' => 'Chhatrapati Shivaji Maharaj International Airport',
                'city' => 'Mumbai',
                'city_code' => 'BOM',
                'country' => 'India',
                'country_code' => 'IN',
                'latitude' => 19.0895600,
                'longitude' => 72.8656100,
                'timezone' => 'Asia/Kolkata',
            ],
            [
                'iata_code' => 'BLR',
                'icao_code' => 'VOBL',
                'name' => 'Kempegowda International Airport',
                'city' => 'Bengaluru',
                'city_code' => 'BLR',
                'country' => 'India',
                'country_code' => 'IN',
                'latitude' => 13.1986400,
                'longitude' => 77.7066100,
                'timezone' => 'Asia/Kolkata',
            ],
            [
                'iata_code' => 'DXB',
                'icao_code' => 'OMDB',
                'name' => 'Dubai International Airport',
                'city' => 'Dubai',
                'city_code' => 'DXB',
                'country' => 'United Arab Emirates',
                'country_code' => 'AE',
                'latitude' => 25.2531700,
                'longitude' => 55.3656700,
                'timezone' => 'Asia/Dubai',
            ],
            [
                'iata_code' => 'LHR',
                'icao_code' => 'EGLL',
                'name' => 'London Heathrow Airport',
                'city' => 'London',
                'city_code' => 'LON',
                'country' => 'United Kingdom',
                'country_code' => 'GB',
                'latitude' => 51.4700200,
                'longitude' => -0.4542950,
                'timezone' => 'Europe/London',
            ],
            [
                'iata_code' => 'SIN',
                'icao_code' => 'WSSS',
                'name' => 'Singapore Changi Airport',
                'city' => 'Singapore',
                'city_code' => 'SIN',
                'country' => 'Singapore',
                'country_code' => 'SG',
                'latitude' => 1.3644200,
                'longitude' => 103.9915300,
                'timezone' => 'Asia/Singapore',
            ],
        ];

        foreach ($airports as $airport) {
            FlightAirport::updateOrCreate(['iata_code' => $airport['iata_code']], $airport);
        }

        // Major Airlines
        $airlines = [
            ['iata_code' => '6E', 'icao_code' => 'IGO', 'name' => 'IndiGo', 'logo_url' => '/images/airlines/6E.png', 'alliance' => 'none'],
            ['iata_code' => 'AI', 'icao_code' => 'AIC', 'name' => 'Air India', 'logo_url' => '/images/airlines/AI.png', 'alliance' => 'star_alliance'],
            ['iata_code' => 'UK', 'icao_code' => 'VTI', 'name' => 'Vistara', 'logo_url' => '/images/airlines/UK.png', 'alliance' => 'none'],
            ['iata_code' => 'EK', 'icao_code' => 'UAE', 'name' => 'Emirates', 'logo_url' => '/images/airlines/EK.png', 'alliance' => 'none'],
            ['iata_code' => 'BA', 'icao_code' => 'BAW', 'name' => 'British Airways', 'logo_url' => '/images/airlines/BA.png', 'alliance' => 'oneworld'],
            ['iata_code' => 'SQ', 'icao_code' => 'SIA', 'name' => 'Singapore Airlines', 'logo_url' => '/images/airlines/SQ.png', 'alliance' => 'star_alliance'],
        ];

        foreach ($airlines as $airline) {
            FlightAirline::updateOrCreate(['iata_code' => $airline['iata_code']], $airline);
        }

        // Standard Aircraft Types
        $aircrafts = [
            ['iata_code' => '32N', 'model' => 'Airbus A320neo', 'manufacturer' => 'Airbus', 'total_seats' => 186],
            ['iata_code' => '321', 'model' => 'Airbus A321neo', 'manufacturer' => 'Airbus', 'total_seats' => 222],
            ['iata_code' => '738', 'model' => 'Boeing 737-800', 'manufacturer' => 'Boeing', 'total_seats' => 189],
            ['iata_code' => '77W', 'model' => 'Boeing 777-300ER', 'manufacturer' => 'Boeing', 'total_seats' => 360],
            ['iata_code' => '788', 'model' => 'Boeing 787-8 Dreamliner', 'manufacturer' => 'Boeing', 'total_seats' => 256],
        ];

        foreach ($aircrafts as $aircraft) {
            FlightAircraft::updateOrCreate(['iata_code' => $aircraft['iata_code']], $aircraft);
        }
    }
}
