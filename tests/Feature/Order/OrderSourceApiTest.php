<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Aggregator\Models\PartnerApiOrderMapping;
use Illuminate\Support\Str;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class OrderSourceApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_order_list_includes_source_fields_and_supports_aggregator_source_filter(): void
    {
        $this->actingAsUserWithPermissions([
            'admin.orders.index',
        ]);

        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);
        $integration = $this->makeIntegration();

        AggregatorOrderMapping::create([
            'aggregator_integration_id' => $integration->id,
            'order_id' => $order->id,
            'external_order_id' => 'SWIGGY-1',
            'external_order_number' => 'SW-1',
            'external_status' => 'created',
            'payload' => [],
            'synced_at' => now(),
        ]);

        $this->getJson('/api/v1/orders?filters[source]=swiggy')
            ->assertOk()
            ->assertJsonPath('body.data.0.id', $order->id)
            ->assertJsonPath('body.data.0.source_type', 'aggregator')
            ->assertJsonPath('body.data.0.aggregator_provider', 'swiggy');
    }

    public function test_partner_api_order_is_presented_and_filterable_as_partner_source(): void
    {
        $this->actingAsUserWithPermissions(['admin.orders.index']);

        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);

        PartnerApiOrderMapping::query()->create([
            'uuid' => (string) Str::uuid(),
            'partner_id' => 1,
            'tenant_id' => 1,
            'credential_id' => 1,
            'branch_id' => $branch->id,
            'order_id' => $order->id,
            'external_order_id' => 'PARTNER-ORDER-1',
            'status' => 'created',
        ]);

        $this->getJson('/api/v1/orders?filters[source]=partner')
            ->assertOk()
            ->assertJsonPath('body.data.0.id', $order->id)
            ->assertJsonPath('body.data.0.source_type', 'partner')
            ->assertJsonPath('body.data.0.source_label', 'Partner');
    }
}
