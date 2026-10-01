<?php

namespace Tests\Unit\Aggregator;

use Modules\Aggregator\Services\Providers\ZomatoContract;
use Tests\TestCase;

class ZomatoContractTest extends TestCase
{
    public function test_internal_statuses_map_to_official_zomato_status_endpoints(): void
    {
        $this->assertSame('confirm', ZomatoContract::statusEndpointKey('confirmed'));
        $this->assertSame('reject', ZomatoContract::statusEndpointKey('cancelled'));
        $this->assertSame('ready', ZomatoContract::statusEndpointKey('ready'));
        $this->assertSame('picked_up', ZomatoContract::statusEndpointKey('served'));
        $this->assertSame('delivered', ZomatoContract::statusEndpointKey('completed'));
        $this->assertNull(ZomatoContract::statusEndpointKey('pending'));
    }

    public function test_default_status_endpoints_stay_disabled_until_partner_activation(): void
    {
        $endpoints = ZomatoContract::statusEndpointDefaults();

        $this->assertSame('/online-ordering/v1/order/confirm', $endpoints['confirm']['url']);
        $this->assertSame('/online-ordering/v1/order/reject', $endpoints['reject']['url']);
        $this->assertSame('/online-ordering/v1/order/ready', $endpoints['ready']['url']);
        $this->assertFalse($endpoints['confirm']['enabled']);
        $this->assertSame('{{external_order_id}}', $endpoints['confirm']['body']['order_id']);
    }
}
