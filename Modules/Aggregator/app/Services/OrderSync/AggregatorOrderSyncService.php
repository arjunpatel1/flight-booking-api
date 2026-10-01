<?php

namespace Modules\Aggregator\Services\OrderSync;

use Throwable;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Enums\AggregatorSyncType;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;
use Modules\Aggregator\Services\Validation\AggregatorMappingValidator;

class AggregatorOrderSyncService implements AggregatorOrderSyncServiceInterface
{
    public function __construct(protected AggregatorProviderFactory $factory, protected AggregatorMappingValidator $validator)
    {
    }

    public function sync(int $integrationId, array $payload = []): AggregatorSyncLog
    {
        $integration = AggregatorIntegration::query()->findOrFail($integrationId);
        $log = AggregatorSyncLog::query()->create([
            'aggregator_integration_id' => $integration->id,
            'type' => AggregatorSyncType::Order,
            'status' => AggregatorSyncStatus::Processing,
            'reference' => $payload['reference'] ?? null,
            'request_payload' => $payload,
            'attempts' => 1,
            'max_attempts' => $payload['max_attempts'] ?? 3,
            'started_at' => now(),
        ]);

        try {
            if (!$integration->is_active || (!($payload['manual'] ?? false) && !($integration->settings['auto_sync'] ?? true))) {
                $log->update([
                    'status' => AggregatorSyncStatus::Ignored,
                    'message' => 'Order sync ignored by integration settings.',
                    'finished_at' => now(),
                ]);
                return $log;
            }

            if ($message = $this->validator->validateOutlet($integration, $payload['branch_id'] ?? null)) {
                $log->update([
                    'status' => AggregatorSyncStatus::Failed,
                    'message' => $message,
                    'error_message' => $message,
                    'finished_at' => now(),
                ]);
                return $log;
            }

            $result = $this->factory->make($integration)->orderSync($integration, $payload);
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
}
