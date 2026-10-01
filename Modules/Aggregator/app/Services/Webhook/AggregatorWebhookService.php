<?php

namespace Modules\Aggregator\Services\Webhook;

use Illuminate\Database\QueryException;
use Modules\Aggregator\DTO\AggregatorWebhookPayload;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorWebhookEvent;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;
use Throwable;

class AggregatorWebhookService implements AggregatorWebhookServiceInterface
{
    public function __construct(protected AggregatorProviderFactory $factory) {}

    public function store(int $integrationId, array $headers, array $payload): AggregatorWebhookEvent
    {
        $normalized = AggregatorWebhookPayload::normalize($payload);
        $integration = AggregatorIntegration::query()->findOrFail($integrationId);
        $allowedIps = $integration->settings['webhook_ip_whitelist'] ?? [];

        abort_if(
            ! empty($allowedIps) && ! in_array(request()?->ip(), $allowedIps, true),
            403,
            'Webhook source IP is not allowed.'
        );

        abort_unless(
            $this->factory->make($integration)->verifyWebhook($integration, $headers, $payload),
            401,
            'Invalid webhook signature.'
        );

        try {
            return AggregatorWebhookEvent::query()->create([
                'aggregator_integration_id' => $integrationId,
                'event_type' => $normalized['event_type'],
                'external_event_id' => $normalized['external_event_id'],
                'status' => AggregatorSyncStatus::Pending,
                'signature' => $headers['x-nexdine-signature'][0] ?? $headers['x-swiggy-signature'][0] ?? $headers['x-zomato-signature'][0] ?? null,
                'source_ip' => request()?->ip(),
                'headers' => $headers,
                'payload' => $normalized,
            ]);
        } catch (QueryException) {
            return AggregatorWebhookEvent::query()
                ->where('aggregator_integration_id', $integrationId)
                ->where('external_event_id', $normalized['external_event_id'])
                ->firstOrFail();
        }
    }

    public function process(int $eventId): void
    {
        $event = AggregatorWebhookEvent::query()->findOrFail($eventId);

        try {
            $integration = AggregatorIntegration::query()->findOrFail($event->aggregator_integration_id);
            if (! ($integration->settings['webhook_processing'] ?? true)) {
                $event->update([
                    'status' => AggregatorSyncStatus::Ignored,
                    'error_message' => 'Webhook processing is disabled for this integration.',
                    'processed_at' => now(),
                ]);

                return;
            }

            $allowedIps = $integration->settings['webhook_ip_whitelist'] ?? [];
            if (! empty($allowedIps) && ! in_array($event->source_ip, $allowedIps)) {
                $event->update([
                    'status' => AggregatorSyncStatus::Failed,
                    'error_message' => 'Webhook source IP is not allowed.',
                    'processed_at' => now(),
                ]);

                return;
            }

            $payload = $event->payload ?? [];
            $verified = $this->factory->make($integration)->verifyWebhook(
                $integration,
                $event->headers ?? [],
                data_get($payload, 'raw', $payload)
            );

            $event->update([
                'status' => $verified ? AggregatorSyncStatus::Skipped : AggregatorSyncStatus::Failed,
                'error_message' => $verified ? 'Webhook stored. Processing awaits official provider payload contract.' : 'Webhook verification failed.',
                'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $event->update([
                'status' => AggregatorSyncStatus::Failed,
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ]);
        }
    }
}
