<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Modules\Saas\Models\SaasDeliveryJob;
use Throwable;

class TriggerWaiterAppBuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public array $backoff = [30, 120];

    public function __construct(public readonly int $deliveryJobId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $job = SaasDeliveryJob::query()->findOrFail($this->deliveryJobId);
        $job->processing();

        try {
            $webhook = config('saas.waiter_app_build.webhook_url');
            if (! $webhook) {
                $job->complete([
                    'mode' => 'runtime_config_app',
                    'message' => 'White-label build skipped. Generic runtime-configurable app is ready.',
                ]);
                return;
            }

            $response = Http::withToken((string) config('saas.waiter_app_build.webhook_token'))
                ->timeout(30)
                ->post($webhook, $job->payload ?? []);

            if (! $response->successful()) {
                $job->failWith($response->body() ?: 'Build webhook failed.');
                return;
            }

            $job->complete([
                'webhook_status' => $response->status(),
                'artifact_url' => $this->artifactUrl($response->json() ?? []),
                'response' => $response->json() ?? ['body' => $response->body()],
            ]);
        } catch (Throwable $exception) {
            $job->failWith($exception->getMessage());
            throw $exception;
        }
    }

    private function artifactUrl(array $payload): ?string
    {
        return data_get($payload, 'artifact_url')
            ?: data_get($payload, 'download_url')
            ?: data_get($payload, 'artifacts.waiter_app')
            ?: data_get($payload, 'data.artifact_url')
            ?: data_get($payload, 'data.download_url');
    }
}
