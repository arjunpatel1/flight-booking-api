<?php

namespace Modules\Aggregator\Services\StatusSync;

use Throwable;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;

class AggregatorStatusSyncService implements AggregatorStatusSyncServiceInterface
{
    public function __construct(protected AggregatorProviderFactory $factory)
    {
    }

    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog
    {
        $integration = AggregatorIntegration::query()->findOrFail($integrationId);
        $log = AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Status,
            'status' => AggregatorSyncStatus::Processing,
            'reference' => $payload['reference'] ?? null,
            'request_payload' => $payload,
            'attempts' => 1,
            'max_attempts' => $payload['max_attempts'] ?? 3,
            'started_at' => now(),
        ]);

        try {
            if (!$integration->is_active || (!($payload['manual'] ?? false) && !($integration->settings['auto_status_sync'] ?? true))) {
                $log->update([
                    'status' => AggregatorSyncStatus::Ignored,
                    'message' => 'Status sync ignored by integration settings.',
                    'finished_at' => now(),
                ]);
                return $log;
            }

            $payload = $this->withExternalOrderMapping($integration, $payload);
            $result = $this->factory->make($integration)->statusSync($integration, $payload);
            $log->update([
                'status' => ($result['success'] ?? false) ? AggregatorSyncStatus::Success : AggregatorSyncStatus::Skipped,
                'message' => $result['message'] ?? null,
                'response_payload' => $result,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => AggregatorSyncStatus::Failed,
                'message' => $exception->getMessage(),
                'error_message' => $exception->getMessage(),
                'next_retry_at' => now()->addSeconds(60),
                'finished_at' => now(),
            ]);
        }

        return $log;
    }

    private function withExternalOrderMapping(AggregatorIntegration $integration, array $payload): array
    {
        if (filled($payload['external_order_id'] ?? null) || blank($payload['order_id'] ?? null)) {
            return $payload;
        }

        $mapping = AggregatorOrderMapping::query()
            ->where('aggregator_integration_id', $integration->id)
            ->where('order_id', $payload['order_id'])
            ->first();

        if (!$mapping) {
            return $payload;
        }

        return [
            ...$payload,
            'external_order_id' => $mapping->external_order_id,
            'external_order_number' => $mapping->external_order_number,
            'external_status' => $mapping->external_status,
        ];
    }
}
