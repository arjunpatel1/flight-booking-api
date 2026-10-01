<?php

namespace Modules\Aggregator\Services\ItemAvailability;

use Throwable;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;

/**
 * Pushes item availability ("86") changes to an aggregator so out-of-stock items
 * are hidden on the storefront. Mirrors AggregatorMenuSyncService: one sync log per
 * push, honouring integration active/settings flags, with retry scheduling on error.
 */
class AggregatorItemAvailabilityService implements AggregatorItemAvailabilityServiceInterface
{
    public function __construct(protected AggregatorProviderFactory $factory)
    {
    }

    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog
    {
        $integration = AggregatorIntegration::query()->findOrFail($integrationId);
        $log = $this->createLog($integration, $payload);

        try {
            $items = $payload['items'] ?? [];
            if (empty($items)) {
                return $this->finish($log, AggregatorSyncStatus::Ignored, 'No items supplied for availability sync.');
            }

            $autoEnabled = $integration->settings['auto_availability_sync'] ?? true;
            if (!$integration->is_active || (!($payload['manual'] ?? false) && !$autoEnabled)) {
                return $this->finish($log, AggregatorSyncStatus::Ignored, 'Availability sync ignored by integration settings.');
            }

            $result = $this->factory->make($integration)->itemAvailabilitySync($integration, $payload);

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

    private function finish(AggregatorSyncLog $log, AggregatorSyncStatus $status, string $message): AggregatorSyncLog
    {
        $log->update(['status' => $status, 'message' => $message, 'finished_at' => now()]);

        return $log;
    }

    private function createLog(AggregatorIntegration $integration, array $payload): AggregatorSyncLog
    {
        return AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Availability,
            'status' => AggregatorSyncStatus::Processing,
            'reference' => $payload['reference'] ?? null,
            'request_payload' => $payload,
            'attempts' => 1,
            'max_attempts' => $payload['max_attempts'] ?? 3,
            'started_at' => now(),
        ]);
    }
}
