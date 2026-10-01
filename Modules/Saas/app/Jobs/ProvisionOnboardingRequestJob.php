<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Saas\Models\SaasOnboardingRequest;
use Modules\Saas\Services\Onboarding\OnboardingRequestService;

/**
 * Provisions an approved onboarding request off the HTTP path.
 *
 * `ShouldBeUnique` keyed on the request id: a re-delivered queue message, an
 * operator double-clicking Retry, or a gateway resending a webhook must not
 * start two provisioning runs for the same customer. The service also
 * short-circuits if a tenant already exists, so uniqueness is belt and braces.
 *
 * `tries = 1` on purpose. Provisioning failure is recorded on the request with
 * a reason and an operator Retry action — that queue is the recovery path.
 * Silent worker retries would hide a systemic failure behind eventual success.
 */
class ProvisionOnboardingRequestJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public readonly int $requestId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "onboarding-request:{$this->requestId}";
    }

    /** Long enough to cover the timeout, so a crashed worker cannot deadlock the lock. */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(OnboardingRequestService $service): void
    {
        $request = SaasOnboardingRequest::query()->find($this->requestId);

        if (! $request) {
            Log::warning('Onboarding request disappeared before provisioning.', ['id' => $this->requestId]);

            return;
        }

        $service->provision($request);
    }

    /**
     * Reached only when the job itself dies (timeout, worker kill) rather than
     * provisioning throwing — the service handles that case internally.
     */
    public function failed(\Throwable $exception): void
    {
        $request = SaasOnboardingRequest::query()->find($this->requestId);

        $request?->forceFill([
            'status' => \Modules\Saas\Enums\OnboardingRequestStatus::Failed,
            'failure_reason' => 'Provisioning worker failed: ' . $exception->getMessage(),
            'failed_at' => now(),
        ])->save();

        $request?->recordEvent('worker_failed', 'Provisioning worker failed.', [
            'error' => $exception->getMessage(),
        ]);
    }
}
