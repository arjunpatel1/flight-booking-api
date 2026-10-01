<?php

namespace Modules\Saas\Services\Onboarding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Enums\OnboardingRequestStatus;
use Modules\Saas\Jobs\ProvisionOnboardingRequestJob;
use Modules\Saas\Models\SaasOnboardingRequest;
use Modules\Saas\Services\Lifecycle\CustomerLifecycleService;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Modules\User\Models\User;
use Throwable;

/**
 * The purchase → payment → approval → provisioning → onboarding workflow.
 *
 * This service is the *only* thing that moves an onboarding request between
 * states. Payment webhooks, the public signup form, the sales console and the
 * admin console all call in here; none of them calls provisioning directly.
 * That single choke point is what makes the flow auditable and what stops a
 * duplicate gateway event from provisioning a tenant twice.
 *
 * Provisioning itself is untouched — this dispatches the existing
 * SaasProvisioningService::provision().
 */
class OnboardingRequestService
{
    public function __construct(
        private readonly SaasProvisioningService $provisioning,
        private readonly CustomerLifecycleService $lifecycle,
    ) {
    }

    /**
     * Register intent to onboard. Does not provision.
     *
     * @param array $data validated request payload
     */
    public function create(array $data, ?User $actor = null): SaasOnboardingRequest
    {
        $requiresPayment = (bool) ($data['requires_payment'] ?? false);
        $approvalMode = $this->resolveApprovalMode($data['approval_mode'] ?? null);

        $request = SaasOnboardingRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'source' => $data['source'] ?? 'self_service',
            'approval_mode' => $approvalMode,
            'status' => $requiresPayment
                ? OnboardingRequestStatus::AwaitingPayment
                : OnboardingRequestStatus::AwaitingApproval,
            'restaurant_name' => $data['restaurant_name'] ?? $data['name'],
            'slug' => $data['slug'] ?? null,
            'domain' => $data['domain'] ?? null,
            'contact_name' => $data['contact_name'] ?? $data['admin_name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'payload' => $this->provisioningPayload($data),
            'plan_code' => $data['plan'] ?? $data['plan_code'] ?? config('saas.self_service.default_plan', 'starter'),
            'trial_days' => $data['trial_days'] ?? config('saas.self_service.trial_days'),
            'payment_status' => $requiresPayment
                ? SaasOnboardingRequest::PAYMENT_PENDING
                : SaasOnboardingRequest::PAYMENT_NOT_REQUIRED,
            'payment_gateway' => $data['payment_gateway'] ?? null,
            'payment_reference' => $data['payment_reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $request->recordEvent('created', 'Onboarding request registered.', [
            'source' => $request->source,
            'approval_mode' => $approvalMode,
            'requires_payment' => $requiresPayment,
        ]);

        $this->lifecycle->recordForRequest(
            $request,
            $requiresPayment ? CustomerLifecycleStage::PaymentPending : CustomerLifecycleStage::Lead,
        );

        // Nothing to wait for: let the auto path run straight through.
        return $this->advance($request);
    }

    /**
     * Called when a gateway confirms payment. Never provisions directly — it
     * marks the request paid and lets {@see advance()} decide what happens next.
     */
    public function markPaid(SaasOnboardingRequest $request, array $context = []): SaasOnboardingRequest
    {
        if ($request->payment_status === SaasOnboardingRequest::PAYMENT_PAID) {
            // Gateways retry. A duplicate event must be a no-op, not a second tenant.
            $request->recordEvent('payment_duplicate', 'Duplicate payment notification ignored.', $context);

            return $request;
        }

        $request->forceFill([
            'payment_status' => SaasOnboardingRequest::PAYMENT_PAID,
            'paid_at' => now(),
            'payment_gateway' => $context['gateway'] ?? $request->payment_gateway,
            'payment_reference' => $context['reference'] ?? $request->payment_reference,
        ])->save();

        $request->recordEvent('payment_received', 'Payment confirmed.', $context);
        $this->lifecycle->recordForRequest($request, CustomerLifecycleStage::Paid);

        if ($request->status === OnboardingRequestStatus::AwaitingPayment) {
            $request->forceFill(['status' => OnboardingRequestStatus::AwaitingApproval])->save();
        }

        return $this->advance($request);
    }

    public function markPaymentFailed(SaasOnboardingRequest $request, string $reason): SaasOnboardingRequest
    {
        $request->forceFill(['payment_status' => SaasOnboardingRequest::PAYMENT_FAILED])->save();
        $request->recordEvent('payment_failed', $reason);

        return $request->refresh();
    }

    /**
     * Operator decision. Manual and sales requests sit at awaiting_approval
     * until this is called.
     */
    public function approve(SaasOnboardingRequest $request, User $actor, ?string $reason = null): SaasOnboardingRequest
    {
        abort_if(
            $request->status->isTerminal(),
            422,
            'This onboarding request has already been closed.'
        );

        abort_unless(
            $request->isPaid(),
            422,
            'This onboarding request cannot be approved until payment is confirmed.'
        );

        $request->forceFill([
            'status' => OnboardingRequestStatus::Approved,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $request->recordEvent('approved', 'Approved by operator.', [
            'by' => $actor->id,
            'reason' => $reason,
        ]);

        return $this->dispatchProvisioning($request);
    }

    public function reject(SaasOnboardingRequest $request, User $actor, string $reason): SaasOnboardingRequest
    {
        abort_if($request->status->isTerminal(), 422, 'This onboarding request has already been closed.');

        $request->forceFill([
            'status' => OnboardingRequestStatus::Rejected,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $request->recordEvent('rejected', 'Rejected by operator.', ['by' => $actor->id, 'reason' => $reason]);
        $this->lifecycle->recordForRequest($request, CustomerLifecycleStage::Cancelled);

        return $request->refresh();
    }

    /**
     * Operator retry after a failed provisioning attempt. The request keeps its
     * approval — a failure is an infrastructure problem, not a decision to redo.
     */
    public function retry(SaasOnboardingRequest $request, User $actor): SaasOnboardingRequest
    {
        abort_unless(
            $request->status->isRetryable(),
            422,
            'Only a failed onboarding request can be retried.'
        );

        $request->recordEvent('retry', 'Retry requested by operator.', ['by' => $actor->id]);

        return $this->dispatchProvisioning($request);
    }

    /**
     * Move the request as far forward as it can go unattended.
     */
    public function advance(SaasOnboardingRequest $request): SaasOnboardingRequest
    {
        if ($request->status->isTerminal() || ! $request->isPaid()) {
            return $request;
        }

        if ($request->status === OnboardingRequestStatus::AwaitingApproval && $request->isAutoApproved()) {
            $request->forceFill([
                'status' => OnboardingRequestStatus::Approved,
                'approved_at' => now(),
                'decision_reason' => 'Auto-approved by policy.',
            ])->save();

            $request->recordEvent('auto_approved', 'Auto-approved: approval mode is automatic.');

            return $this->dispatchProvisioning($request);
        }

        return $request->refresh();
    }

    /**
     * Hand off to the queue. Kept separate from provisioning itself so an HTTP
     * request never waits on tenant creation, SSL and asset generation.
     */
    private function dispatchProvisioning(SaasOnboardingRequest $request): SaasOnboardingRequest
    {
        $request->forceFill(['status' => OnboardingRequestStatus::Provisioning])->save();
        $request->recordEvent('provisioning_queued', 'Provisioning dispatched to the queue.');
        $this->lifecycle->recordForRequest($request, CustomerLifecycleStage::Provisioning);

        ProvisionOnboardingRequestJob::dispatch($request->id)
            ->onQueue(config('saas.queues.provisioning', 'provisioning'));

        return $request->refresh();
    }

    /**
     * Runs inside the queued job. Reuses the existing provisioning service
     * unchanged; this only records the outcome against the request.
     */
    public function provision(SaasOnboardingRequest $request): SaasOnboardingRequest
    {
        if ($request->tenant_id) {
            // Already provisioned — a re-delivered job must not create a second tenant.
            $request->recordEvent('provision_skipped', 'Request already has a tenant.');

            return $request;
        }

        $request->increment('attempts');

        try {
            $result = DB::transaction(fn () => $this->provisioning->provision([
                ...$request->payload ?? [],
                'name' => $request->restaurant_name,
                'slug' => $request->slug,
                'domain' => $request->domain,
                'email' => $request->email,
                'phone' => $request->phone,
                'admin_name' => $request->contact_name,
                'plan' => $request->plan_code,
                'trial_days' => $request->trial_days,
            ]));

            $request->forceFill([
                'status' => OnboardingRequestStatus::Completed,
                'tenant_id' => $result['tenant']->id,
                'provisioning_run_id' => $result['provisioning_run']->id,
                'completed_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ])->save();

            $request->recordEvent('provisioned', 'Tenant provisioned successfully.', [
                'tenant_id' => $result['tenant']->id,
                'run_uuid' => $result['provisioning_run']->uuid,
            ]);

            $this->lifecycle->transitionTenant(
                $result['tenant'],
                CustomerLifecycleStage::Onboarding,
                'Tenant provisioned from onboarding request.',
            );

            return $request->refresh();
        } catch (Throwable $exception) {
            $request->forceFill([
                'status' => OnboardingRequestStatus::Failed,
                'failure_reason' => Str::limit($exception->getMessage(), 1000),
                'failed_at' => now(),
            ])->save();

            $request->recordEvent('provision_failed', 'Provisioning failed.', [
                'error' => $exception->getMessage(),
                'attempt' => $request->attempts,
            ]);

            Log::error('Onboarding request provisioning failed.', [
                'request_uuid' => $request->uuid,
                'attempt' => $request->attempts,
                'error' => $exception->getMessage(),
            ]);

            // Surfaced as a failed request with a retry action rather than
            // rethrown: the operator queue is the recovery path, not the
            // queue worker's own retry budget.
            return $request->refresh();
        }
    }

    /**
     * Find the request a gateway event belongs to. Returns null rather than
     * throwing so an unrelated webhook is ignored, not turned into a 500.
     */
    public function findByPaymentReference(string $gateway, ?string $reference): ?SaasOnboardingRequest
    {
        if (blank($reference)) {
            return null;
        }

        return SaasOnboardingRequest::query()
            ->where('payment_reference', $reference)
            ->where(fn ($query) => $query->where('payment_gateway', $gateway)->orWhereNull('payment_gateway'))
            ->open()
            ->latest('id')
            ->first();
    }

    private function resolveApprovalMode(?string $mode): string
    {
        $mode ??= (string) config('saas.onboarding.default_approval_mode', SaasOnboardingRequest::APPROVAL_MANUAL);

        return in_array($mode, SaasOnboardingRequest::approvalModes(), true)
            ? $mode
            : SaasOnboardingRequest::APPROVAL_MANUAL;
    }

    /**
     * Everything the provisioning service accepts, minus the fields stored as
     * first-class columns. Keeps the payload forward-compatible without this
     * service needing to know every provisioning option.
     */
    private function provisioningPayload(array $data): array
    {
        return collect($data)->except([
            'requires_payment', 'approval_mode', 'source',
            'restaurant_name', 'name', 'slug', 'domain', 'email', 'phone',
            'contact_name', 'admin_name', 'plan', 'plan_code', 'trial_days',
            'payment_gateway', 'payment_reference', 'amount', 'currency',
            'captcha_token', 'terms', 'website',
        ])->all();
    }
}
