<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class OperationalMetricsApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        setting(['default_currency' => 'INR']);
    }

    public function test_operational_metrics_endpoint_returns_live_aggregator_and_operations_sections(): void
    {
        $this->actingAsUserWithPermissions([
            'admin.dashboards.live',
            'admin.dashboards.analytics',
        ]);

        $this->getJson('/api/v1/dashboards/operational-metrics')
            ->assertOk()
            ->assertJsonStructure([
                'body' => [
                    'live_orders' => [
                        'active_orders',
                        'pending_aggregator_orders',
                        'failed_sync_count',
                        'processing_queue_count',
                        'delivery_in_progress',
                    ],
                    'aggregator' => [
                        'platform_sales',
                        'top_selling_platform',
                    ],
                    'operations' => [
                        'failed_webhooks',
                        'retry_queue_count',
                        'sync_success_percentage',
                        'provider_health',
                    ],
                ],
            ]);
    }
}
