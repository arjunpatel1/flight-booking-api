<?php

namespace Tests\Unit\Setting;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Setting\Services\Delivery\DeliveryGeocoder;
use Tests\TestCase;

class DeliveryGeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app()->instance('setting', new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'delivery_map_provider' ? 'openstreetmap' : $default;
            }
        });
    }

    public function test_search_and_reverse_return_structured_locality_fields(): void
    {
        $result = [
            'display_name' => '12 Main Road, Hanamkonda, Warangal, Telangana 506001, India',
            'lat' => '17.9941', 'lon' => '79.5570',
            'address' => [
                'house_number' => '12', 'road' => 'Main Road', 'suburb' => 'Hanamkonda',
                'city' => 'Warangal', 'state' => 'Telangana', 'postcode' => '506001', 'country' => 'India',
            ],
        ];
        Http::fake([
            '*/search*' => Http::response([$result]),
            '*/reverse*' => Http::response($result),
        ]);

        $geocoder = app(DeliveryGeocoder::class);
        foreach ([$geocoder->search('Main Road Warangal')[0], $geocoder->reverse(17.9941, 79.5570)[0]] as $location) {
            $this->assertSame('12 Main Road', $location['address_line1']);
            $this->assertSame('Hanamkonda', $location['area']);
            $this->assertSame('Warangal', $location['city']);
            $this->assertSame('Telangana', $location['state']);
            $this->assertSame('506001', $location['postal_code']);
        }
    }

    public function test_locality_only_pin_never_becomes_the_customer_house_line(): void
    {
        Http::fake([
            '*/reverse*' => Http::response([
                'display_name' => 'Rayachoti Urban, Raju Colony, Rayachoti, Andhra Pradesh, 516269, India',
                'lat' => '14.0570', 'lon' => '78.7510',
                'address' => [
                    'locality' => 'Raju Colony', 'town' => 'Rayachoti',
                    'state' => 'Andhra Pradesh', 'postcode' => '516269', 'country' => 'India',
                ],
            ]),
        ]);

        $location = app(DeliveryGeocoder::class)->reverse(14.057, 78.751)[0];

        $this->assertSame('', $location['address_line1']);
        $this->assertNull($location['house_number']);
        $this->assertSame('Raju Colony', $location['area']);
        $this->assertSame('Rayachoti', $location['city']);
    }

    public function test_reverse_uses_place_and_locality_fallbacks_for_sparse_pins(): void
    {
        Http::fake([
            '*/reverse*' => Http::response([
                'display_name' => 'City Mall, Nakkalagutta, Hanamkonda, Telangana, India',
                'lat' => '17.9950', 'lon' => '79.5560',
                'address' => [
                    'amenity' => 'City Mall', 'locality' => 'Nakkalagutta',
                    'town' => 'Hanamkonda', 'state' => 'Telangana', 'country' => 'India',
                ],
            ]),
        ]);

        $location = app(DeliveryGeocoder::class)->reverse(17.995, 79.556)[0];

        $this->assertSame('City Mall', $location['address_line1']);
        $this->assertSame('Nakkalagutta', $location['area']);
        $this->assertSame('Hanamkonda', $location['city']);
    }
}
