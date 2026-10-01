<?php

namespace Modules\Aggregator\Services\MenuSync;

use Throwable;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;
use Modules\Aggregator\Services\Validation\AggregatorMappingValidator;

class AggregatorMenuSyncService implements AggregatorMenuSyncServiceInterface
{
    public function __construct(protected AggregatorProviderFactory $factory, protected AggregatorMappingValidator $validator)
    {
    }

    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog
    {
        $integration = AggregatorIntegration::query()->findOrFail($integrationId);
        $log = $this->createLog($integration, $payload);

        try {
            if (!$integration->is_active || (!($payload['manual'] ?? false) && !($integration->settings['auto_menu_sync'] ?? true))) {
                $log->update([
                    'status' => AggregatorSyncStatus::Ignored,
                    'message' => 'Menu sync ignored by integration settings.',
                    'finished_at' => now(),
                ]);
                return $log;
            }

            if ($message = $this->validator->validateMenu($integration)) {
                $log->update([
                    'status' => AggregatorSyncStatus::Failed,
                    'message' => $message,
                    'error_message' => $message,
                    'finished_at' => now(),
                ]);
                return $log;
            }

            $result = $this->factory->make($integration)->menuSync($integration, $payload);

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

    private function createLog(AggregatorIntegration $integration, array $payload): AggregatorSyncLog
    {
        return AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Menu,
            'status' => AggregatorSyncStatus::Processing,
            'reference' => $payload['reference'] ?? null,
            'request_payload' => $payload,
            'attempts' => 1,
            'max_attempts' => $payload['max_attempts'] ?? 3,
            'started_at' => now(),
        ]);
    }
}
