<?php

namespace Tests\Unit\Setting;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Modules\Setting\Http\Requests\Api\V1\SaveSettingRequest;
use PHPUnit\Framework\TestCase;

class DeliverySettingValidationTest extends TestCase
{
    public function test_delivery_slabs_must_cover_radius_without_gaps_or_overlap(): void
    {
        $valid = $this->payload([
            ['min_km' => 0, 'max_km' => 2, 'charge' => 20],
            ['min_km' => 2, 'max_km' => 8, 'charge' => 50],
        ]);
        $validResult = $this->validate($valid);
        $this->assertTrue($validResult->passes(), json_encode($validResult->errors()->toArray()));

        $gap = $this->payload([
            ['min_km' => 0, 'max_km' => 2, 'charge' => 20],
            ['min_km' => 3, 'max_km' => 8, 'charge' => 50],
        ]);
        $this->assertTrue($this->validate($gap)->fails());

        $short = $this->payload([['min_km' => 0, 'max_km' => 5, 'charge' => 35]]);
        $this->assertTrue($this->validate($short)->fails());

        $beyond = $this->payload([['min_km' => 0, 'max_km' => 10, 'charge' => 65]]);
        $this->assertTrue($this->validate($beyond)->fails());
    }

    public function test_tenant_cannot_enable_contract_blocked_booking_or_fallback(): void
    {
        $automatic = $this->payload([
            ['min_km' => 0, 'max_km' => 8, 'charge' => 20],
        ]);
        $automatic['automatic_partner_assignment_enabled'] = true;
        $result = $this->validate($automatic);
        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('automatic_partner_assignment_enabled', $result->errors()->toArray());

        $fallback = $this->payload([
            ['min_km' => 0, 'max_km' => 8, 'charge' => 20],
        ]);
        $fallback['auto_fallback_partner_enabled'] = true;
        $result = $this->validate($fallback);
        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('auto_fallback_partner_enabled', $result->errors()->toArray());
    }

    public function test_tenant_cannot_change_platform_provider_configuration(): void
    {
        $data = $this->payload([['min_km' => 0, 'max_km' => 8, 'charge' => 20]]);
        $data['delivery_uengage_store_id'] = 'foreign-store';
        $data['encryptable'] = ['delivery_uengage_api_key' => 'injected-secret'];
        $data['maximum_provider_delivery_cost'] = 999;
        $result = $this->validate($data);
        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('delivery_uengage_store_id', $result->errors()->toArray());
        $this->assertArrayHasKey('encryptable.delivery_uengage_api_key', $result->errors()->toArray());
        $this->assertArrayHasKey('maximum_provider_delivery_cost', $result->errors()->toArray());
    }

    public function test_tenant_cannot_change_platform_map_or_courier_configuration(): void
    {
        $data = $this->payload([['min_km' => 0, 'max_km' => 8, 'charge' => 20]]);
        $data['delivery_map_provider'] = 'google';
        $data['encryptable'] = ['delivery_map_api_key' => 'tenant-map-key'];
        $result = $this->validate($data);

        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('delivery_map_provider', $result->errors()->toArray());
        $this->assertArrayHasKey('encryptable.delivery_map_api_key', $result->errors()->toArray());

        $data['encryptable']['delivery_uengage_api_key'] = 'courier-secret';
        $result = $this->validate($data);
        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('encryptable.delivery_uengage_api_key', $result->errors()->toArray());
    }

    public function test_delivery_schedule_requires_valid_days_and_hours(): void
    {
        $payload = $this->payload([['min_km' => 0, 'max_km' => 8, 'charge' => 20]]);
        $payload['delivery_ordering_enabled'] = true;
        $payload['delivery_schedule_enabled'] = true;
        $payload['delivery_hours'] = ['mon' => ['open' => '10:00', 'close' => '22:00'], 'tue' => []];
        $this->assertTrue($this->validate($payload)->passes());

        foreach ([
            ['mon' => ['open' => '10:00', 'close' => '10:00']],
            ['mon' => ['open' => '25:00', 'close' => '22:00']],
            ['mon' => ['open' => '10:00', 'close' => '22:00', 'secret' => 'unexpected']],
            ['unknown' => ['open' => '10:00', 'close' => '22:00']],
            ['mon' => []],
        ] as $hours) {
            $payload['delivery_hours'] = $hours;
            $this->assertTrue($this->validate($payload)->fails(), json_encode($hours));
        }
    }

    public function test_partner_api_radius_policy_accepts_only_explicit_modes(): void
    {
        $payload = $this->payload([['min_km' => 0, 'max_km' => 8, 'charge' => 20]]);
        $payload['partner_api_delivery_radius_policy'] = 'bypass';
        $this->assertTrue($this->validate($payload)->passes());

        $payload['partner_api_delivery_radius_policy'] = 'unsafe';
        $this->assertTrue($this->validate($payload)->fails());
    }

    private function validate(array $data)
    {
        $request = SaveSettingRequest::create('/api/v1/settings/delivery/update', 'PUT', $data);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))
            ->make($request->validationData(), $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    private function payload(array $slabs): array
    {
        return [
            'delivery_enabled' => true,
            'third_party_delivery_enabled' => false,
            'delivery_cod_enabled' => true,
            'delivery_prepaid_enabled' => true,
            'maximum_delivery_radius_km' => 8,
            'delivery_pricing_method' => 'slabs',
            'delivery_charge_slabs' => $slabs,
            'base_delivery_charge' => 0,
            'base_distance_km' => 0,
            'per_additional_km_charge' => 0,
        ];
    }
}
