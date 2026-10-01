<?php

namespace Tests\Feature\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Jobs\ProcessAggregatorWebhookEventJob;
use Modules\Aggregator\Models\AggregatorWebhookEvent;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AggregatorWebhookApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_webhook_receive_stores_event_and_dispatches_processing_job(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $payload = $this->webhookPayload([
            'event_type' => 'order.created',
            'event_id' => 'evt_order_created',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
            ->withHeader('x-nexdine-signature', $this->nexdineSignature($payload))
            ->postJson("/api/v1/aggregator-webhook-events/{$integration->id}/receive", $payload)
            ->assertOk();

        $event = AggregatorWebhookEvent::query()->firstOrFail();
        $this->assertSame('order.created', $event->event_type);
        $this->assertSame('evt_order_created', $event->external_event_id);
        $this->assertSame(AggregatorSyncStatus::Pending, $event->status);

        Queue::assertPushed(ProcessAggregatorWebhookEventJob::class, function (ProcessAggregatorWebhookEventJob $job) use ($event) {
            return $job->eventId === $event->id
                && $job->queue === 'aggregator-webhooks';
        });
    }

    public function test_webhook_duplicate_event_is_deduplicated(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $payload = $this->webhookPayload([
            'event_id' => 'evt_duplicate',
        ]);

        $this->withHeader('x-nexdine-signature', $this->nexdineSignature($payload))
            ->postJson("/api/v1/aggregator-webhook-events/{$integration->id}/receive", $payload)
            ->assertOk();

        $this->withHeader('x-nexdine-signature', $this->nexdineSignature($payload))
            ->postJson("/api/v1/aggregator-webhook-events/{$integration->id}/receive", $payload)
            ->assertOk();

        $this->assertDatabaseCount('aggregator_webhook_events', 1);
    }

    public function test_webhook_processing_rejects_invalid_signature(): void
    {
        $integration = $this->makeIntegration();
        $payload = $this->webhookPayload([
            'event_id' => 'evt_bad_signature',
        ]);

        $event = AggregatorWebhookEvent::query()->create([
            'aggregator_integration_id' => $integration->id,
            'event_type' => 'order.created',
            'external_event_id' => 'evt_bad_signature',
            'status' => AggregatorSyncStatus::Pending,
            'headers' => [
                'x-nexdine-signature' => ['bad-signature'],
            ],
            'payload' => [
                'version' => 'internal.webhook.v1',
                'event_type' => 'order.created',
                'external_event_id' => 'evt_bad_signature',
                'external_order_id' => data_get($payload, 'data.external_order_id'),
                'raw' => $payload,
            ],
        ]);

        ProcessAggregatorWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(AggregatorSyncStatus::Failed, $event->status);
        $this->assertSame('Webhook verification failed.', $event->error_message);
    }

    public function test_webhook_processing_rejects_unauthorized_source_ip(): void
    {
        $integration = $this->makeIntegration([
            'settings' => [
                'webhook_processing' => true,
                'webhook_ip_whitelist' => ['192.168.1.10'],
            ],
        ]);

        $event = AggregatorWebhookEvent::query()->create([
            'aggregator_integration_id' => $integration->id,
            'event_type' => 'order.cancelled',
            'external_event_id' => 'evt_source_ip',
            'status' => AggregatorSyncStatus::Pending,
            'source_ip' => '10.10.10.10',
            'headers' => [],
            'payload' => [
                'version' => 'internal.webhook.v1',
                'event_type' => 'order.cancelled',
                'external_event_id' => 'evt_source_ip',
                'raw' => [],
            ],
        ]);

        ProcessAggregatorWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(AggregatorSyncStatus::Failed, $event->status);
        $this->assertSame('Webhook source IP is not allowed.', $event->error_message);
    }

    public function test_admin_can_reprocess_webhook_event_with_permission(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();
        $event = AggregatorWebhookEvent::query()->create([
            'aggregator_integration_id' => $integration->id,
            'event_type' => 'order.ready',
            'external_event_id' => 'evt_reprocess',
            'status' => AggregatorSyncStatus::Failed,
            'headers' => [],
            'payload' => ['raw' => []],
        ]);

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('sync'),
        ]);

        $this->postJson("/api/v1/aggregator-webhook-events/{$event->id}/reprocess")
            ->assertOk();

        Queue::assertPushed(ProcessAggregatorWebhookEventJob::class, fn (ProcessAggregatorWebhookEventJob $job) => $job->eventId === $event->id);
    }

    public function test_webhook_processing_can_be_disabled_per_integration(): void
    {
        $integration = $this->makeIntegration([
            'settings' => [
                'webhook_processing' => false,
            ],
        ]);

        $event = AggregatorWebhookEvent::query()->create([
            'aggregator_integration_id' => $integration->id,
            'event_type' => 'order.delivered',
            'external_event_id' => 'evt_disabled',
            'status' => AggregatorSyncStatus::Pending,
            'headers' => [],
            'payload' => ['raw' => []],
        ]);

        ProcessAggregatorWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(AggregatorSyncStatus::Ignored, $event->status);
        $this->assertSame('Webhook processing is disabled for this integration.', $event->error_message);
    }
}
