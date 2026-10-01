<?php

namespace Tests\Feature\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Jobs\AggregatorOrderSyncJob;
use Modules\Aggregator\Jobs\AggregatorStatusSyncJob;
use Modules\Aggregator\Jobs\SyncAggregatorIntegrationJob;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AggregatorSyncLifecycleTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_manual_menu_sync_without_mapping_is_logged_as_failed(): void
    {
        $integration = $this->makeIntegration();

        SyncAggregatorIntegrationJob::dispatchSync($integration->id, 'menu');

        $this->assertDatabaseHas('aggregator_sync_logs', [
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Menu->value,
            'status' => AggregatorSyncStatus::Failed->value,
            'error_message' => 'No enabled menu mapping exists for this integration.',
        ]);
    }

    public function test_retry_failed_sync_dispatches_new_job_and_tracks_attempts(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $log = AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Menu,
            'status' => AggregatorSyncStatus::Failed,
            'reference' => 'MENU-1',
            'request_payload' => ['menu_id' => 1],
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('sync'),
        ]);

        $this->postJson("/api/v1/aggregator-sync-logs/{$log->id}/retry")
            ->assertOk();

        $log->refresh();
        $this->assertSame(AggregatorSyncStatus::Retrying, $log->status);
        $this->assertSame(1, $log->attempts);

        Queue::assertPushed(SyncAggregatorIntegrationJob::class, function (SyncAggregatorIntegrationJob $job) use ($integration) {
            return $job->integrationId === $integration->id
                && $job->type === AggregatorSyncType::Menu->value
                && $job->payload['reference'] === 'MENU-1';
        });
    }

    public function test_retry_respects_disabled_retry_toggle_and_dead_letter_limit(): void
    {
        $integration = $this->makeIntegration([
            'settings' => [
                'retry_enabled' => false,
            ],
        ]);

        $disabledRetryLog = AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Menu,
            'status' => AggregatorSyncStatus::Failed,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('sync'),
        ]);

        $this->postJson("/api/v1/aggregator-sync-logs/{$disabledRetryLog->id}/retry")
            ->assertUnprocessable();

        $deadLetterLog = AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $this->makeIntegration()->id,
            'type' => AggregatorSyncType::Order,
            'status' => AggregatorSyncStatus::Failed,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $this->postJson("/api/v1/aggregator-sync-logs/{$deadLetterLog->id}/retry")
            ->assertUnprocessable();
    }


    public function test_live_provider_traffic_is_blocked_until_official_contract_is_verified(): void
    {
        Http::fake();
        $integration = $this->makeIntegration([
            'settings' => [
                'auto_menu_sync' => true,
                'retry_enabled' => true,
                'contract_status' => 'pending_official_contract',
                'official_contract_verified' => false,
                'api_endpoints' => [
                    'menu_sync' => [
                        'enabled' => true,
                        'method' => 'POST',
                        'url' => '/menu/sync',
                    ],
                ],
            ],
        ]);
        $this->mapMenu($integration, $this->makeMenu());

        SyncAggregatorIntegrationJob::dispatchSync($integration->id, 'menu');

        Http::assertNothingSent();
        $this->assertDatabaseHas('aggregator_sync_logs', [
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Menu->value,
            'status' => AggregatorSyncStatus::Skipped->value,
            'message' => __('aggregator::aggregator.provider_contract_pending'),
        ]);
    }

    public function test_order_lifecycle_events_dispatch_aggregator_jobs_for_mapped_integrations(): void
    {
        Queue::fake();
        Event::fakeExcept([
            OrderCreated::class,
            OrderUpdateStatus::class,
            OrderVoided::class,
        ]);

        $integration = $this->makeIntegration([
            'settings' => [
                'auto_sync' => true,
                'auto_status_sync' => true,
                'sync_direct_orders' => true,
                'contract_status' => 'official_contract_verified',
                'official_contract_verified' => true,
            ],
        ]);
        $branch = $this->makeBranch();
        $this->mapOutlet($integration, $branch);
        $order = $this->makeOrder($branch);

        event(new OrderCreated($order));
        event(new OrderUpdateStatus($order, OrderStatus::Ready, note: 'ready for pickup'));
        event(new OrderVoided($order, OrderStatus::Cancelled, note: 'customer cancelled'));

        Queue::assertPushed(AggregatorOrderSyncJob::class, function (AggregatorOrderSyncJob $job) use ($integration, $order) {
            return $job->integrationId === $integration->id
                && $job->payload['event'] === 'order_created'
                && $job->payload['order_id'] === $order->id;
        });

        Queue::assertPushed(AggregatorStatusSyncJob::class, function (AggregatorStatusSyncJob $job) use ($integration, $order) {
            return $job->integrationId === $integration->id
                && $job->payload['order_id'] === $order->id
                && $job->payload['status'] === OrderStatus::Ready->value;
        });

        Queue::assertPushed(AggregatorStatusSyncJob::class, function (AggregatorStatusSyncJob $job) use ($integration, $order) {
            return $job->integrationId === $integration->id
                && $job->payload['event'] === 'order_cancelled'
                && $job->payload['order_id'] === $order->id;
        });
    }

    public function test_order_lifecycle_ignores_unmapped_integrations(): void
    {
        Queue::fake();
        $this->makeIntegration();
        $order = $this->makeOrder($this->makeBranch());

        event(new OrderCreated($order));

        Queue::assertNotPushed(AggregatorOrderSyncJob::class);
    }
}
