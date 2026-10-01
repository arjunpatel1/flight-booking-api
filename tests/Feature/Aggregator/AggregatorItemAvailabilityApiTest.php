<?php

namespace Tests\Feature\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Aggregator depth — item availability ("86") push. Marking an item out of stock
 * must reach the aggregator so it's hidden on the storefront, and be recorded as an
 * Availability sync log.
 *
 * Ungated (no #[RequiresPhpExtension('pdo_sqlite')]) so it runs on the MySQL scratch DB.
 */
class AggregatorItemAvailabilityApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function verifiedIntegration()
    {
        return $this->makeIntegration([
            'settings' => [
                'auto_availability_sync' => true,
                'contract_status' => 'official_contract_verified',
                'official_contract_verified' => true,
                'api_endpoints' => [
                    'item_availability_sync' => [
                        'enabled' => true,
                        'method' => 'POST',
                        'url' => '/items/availability',
                    ],
                ],
            ],
        ]);
    }

    public function test_item_availability_push_reaches_provider_and_is_logged(): void
    {
        Http::fake(['partner.example.test/*' => Http::response(['ok' => true], 200)]);
        $integration = $this->verifiedIntegration();

        $this->actingAsUserWithPermissions([$this->aggregatorPermission('sync')]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/item-availability", [
            'items' => [
                ['product_id' => 101, 'available' => false],
                ['product_id' => 102, 'available' => true],
            ],
        ])->assertOk();

        // The change was pushed to the provider's configured endpoint.
        Http::assertSent(fn($request) => str_contains($request->url(), '/items/availability'));

        // …and recorded as an Availability sync log that succeeded.
        $this->assertDatabaseHas('aggregator_sync_logs', [
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Availability->value,
            'status' => AggregatorSyncStatus::Success->value,
        ]);
    }

    public function test_item_availability_requires_items(): void
    {
        $integration = $this->verifiedIntegration();
        $this->actingAsUserWithPermissions([$this->aggregatorPermission('sync')]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/item-availability", [
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_item_availability_requires_permission(): void
    {
        $integration = $this->verifiedIntegration();
        $this->actingAsUserWithPermissions([$this->aggregatorPermission('index')]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/item-availability", [
            'items' => [['product_id' => 101, 'available' => false]],
        ])->assertForbidden();
    }

    // ── Hardening: bad / hard cases ─────────────────────────────────────────

    public function test_item_availability_unknown_integration_returns_404(): void
    {
        $this->actingAsUserWithPermissions([$this->aggregatorPermission('sync')]);

        $this->postJson('/api/v1/aggregator-integrations/999999999/item-availability', [
            'items' => [['product_id' => 1, 'available' => false]],
        ])->assertNotFound();
    }

    public function test_item_availability_rejects_malformed_items(): void
    {
        $integration = $this->verifiedIntegration();
        $this->actingAsUserWithPermissions([$this->aggregatorPermission('sync')]);

        // Missing product_id / wrong available type.
        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/item-availability", [
            'items' => [['available' => 'maybe']],
        ])->assertStatus(422);
    }

    public function test_provider_failure_is_recorded_not_thrown(): void
    {
        // Provider returns 500 — the endpoint must stay graceful (200) and log a
        // non-success sync result rather than bubbling a 500 to the caller.
        Http::fake(['partner.example.test/*' => Http::response(['error' => 'upstream down'], 500)]);
        $integration = $this->verifiedIntegration();
        $this->actingAsUserWithPermissions([$this->aggregatorPermission('sync')]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/item-availability", [
            'items' => [['product_id' => 1, 'available' => false]],
        ])->assertOk();

        $this->assertDatabaseHas('aggregator_sync_logs', [
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Availability->value,
            'status' => AggregatorSyncStatus::Skipped->value,
        ]);
    }
}
