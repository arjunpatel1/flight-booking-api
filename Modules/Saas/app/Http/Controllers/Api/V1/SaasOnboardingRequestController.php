<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Enums\OnboardingRequestStatus;
use Modules\Saas\Models\SaasOnboardingRequest;
use Modules\Saas\Services\Lifecycle\CustomerLifecycleService;
use Modules\Saas\Services\Onboarding\OnboardingRequestService;
use Modules\Support\ApiResponse;
use Modules\Support\InputLimit;

/**
 * Operator console for the purchase → provisioning pipeline.
 *
 * Every action here is an operator decision on a request; none of them talks to
 * provisioning directly. Approval and rejection require `admin.saas.manage`
 * because they commit the platform to creating (or refusing) a tenant.
 */
class SaasOnboardingRequestController extends Controller
{
    public function __construct(
        private readonly OnboardingRequestService $service,
        private readonly CustomerLifecycleService $lifecycle,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(OnboardingRequestStatus::values())],
            'approval_mode' => ['nullable', Rule::in(SaasOnboardingRequest::approvalModes())],
            'awaiting' => ['nullable', 'boolean'],
            'assigned_to' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SaasOnboardingRequest::query()
            ->with([
                'tenant:id,name,slug',
                'approver:id,name',
                'assignee:id,name',
                'provisioningRun:id,uuid,status,progress',
            ])
            ->when($filters['assigned_to'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['approval_mode'] ?? null, fn ($q, $mode) => $q->where('approval_mode', $mode))
            ->when($filters['awaiting'] ?? false, fn ($q) => $q->awaitingOperator())
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($inner) => $inner
                    ->where('restaurant_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
            ))
            ->latest('id');

        return ApiResponse::success([
            'requests' => $query->paginate($filters['per_page'] ?? 25),
            'summary' => $this->summary(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $request = SaasOnboardingRequest::query()
            ->with(['tenant:id,name,slug,domain', 'approver:id,name', 'creator:id,name', 'provisioningRun'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return ApiResponse::success($request);
    }

    /**
     * Register a request from the sales or admin console. Self-service signups
     * arrive through PublicTenantSignupController and land in the same queue.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'plan_code' => ['nullable', 'string', 'max:60'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'approval_mode' => ['nullable', Rule::in(SaasOnboardingRequest::approvalModes())],
            'requires_payment' => ['nullable', 'boolean'],
            'payment_gateway' => ['nullable', 'string', 'max:32'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', ...InputLimit::money()],
            'currency' => ['nullable', 'string', 'size:3'],
            'source' => ['nullable', Rule::in(['self_service', 'sales', 'admin', 'payment'])],
        ]);

        return ApiResponse::created(
            $this->service->create([...$data, 'source' => $data['source'] ?? 'admin'], $request->user()),
            message: 'Onboarding request created.'
        );
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::updated(
            $this->service->approve($this->find($uuid), $request->user(), $data['reason'] ?? null),
            message: 'Onboarding request approved and queued for provisioning.'
        );
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::updated(
            $this->service->reject($this->find($uuid), $request->user(), $data['reason']),
            message: 'Onboarding request rejected.'
        );
    }

    /**
     * Operator recovery for a failed provisioning run.
     */
    public function retry(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::updated(
            $this->service->retry($this->find($uuid), $request->user()),
            message: 'Provisioning retry queued.'
        );
    }

    /**
     * Manual settlement, for payments taken outside a wired-up gateway
     * (bank transfer, cash, an offline sales deal).
     */
    public function markPaid(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:255'],
            'gateway' => ['nullable', 'string', 'max:32'],
        ]);

        return ApiResponse::updated(
            $this->service->markPaid($this->find($uuid), [
                'gateway' => $data['gateway'] ?? 'manual',
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $request->user()?->id,
            ]),
            message: 'Payment recorded.'
        );
    }

    /**
     * Assign an engineer. Assignment is ownership only — it grants nothing and
     * blocks nothing, so a request is never stuck behind an absent assignee.
     */
    public function assign(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $onboarding = $this->find($uuid);
        $onboarding->forceFill([
            'assigned_to' => $data['assigned_to'] ?? null,
            'assigned_at' => ($data['assigned_to'] ?? null) ? now() : null,
        ])->save();

        $onboarding->recordEvent(
            'assigned',
            $data['assigned_to'] ? 'Assigned to an engineer.' : 'Assignment cleared.',
            ['assigned_to' => $data['assigned_to'] ?? null, 'by' => $request->user()?->id],
        );

        return ApiResponse::updated($onboarding->fresh('assignee'), message: 'Assignment updated.');
    }

    /**
     * Append an operator note. Notes land on the same timeline as state
     * changes so the history reads in one chronological order.
     */
    public function note(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $onboarding = $this->find($uuid);
        $onboarding->recordEvent('note', $data['note'], [
            'by' => $request->user()?->id,
            'by_name' => $request->user()?->name,
        ]);

        return ApiResponse::updated($onboarding->refresh(), message: 'Note added.');
    }

    /**
     * Bulk approve / reject / retry / assign.
     *
     * Each request is processed independently and failures are collected rather
     * than aborting the batch — one request in a bad state must not stop an
     * operator clearing the other nineteen.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'retry', 'assign'])],
            'uuids' => ['required', 'array', 'min:1', 'max:50'],
            'uuids.*' => ['string'],
            'reason' => ['nullable', 'string', 'max:500'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($data['action'] === 'reject' && blank($data['reason'] ?? null)) {
            return ApiResponse::errors(
                ['reason' => ['A reason is required when rejecting.']],
                'A reason is required when rejecting.',
                422
            );
        }

        $succeeded = [];
        $failed = [];

        foreach ($data['uuids'] as $uuid) {
            try {
                $onboarding = $this->find($uuid);

                match ($data['action']) {
                    'approve' => $this->service->approve($onboarding, $request->user(), $data['reason'] ?? null),
                    'reject' => $this->service->reject($onboarding, $request->user(), $data['reason']),
                    'retry' => $this->service->retry($onboarding, $request->user()),
                    'assign' => tap($onboarding)->forceFill([
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'assigned_at' => ($data['assigned_to'] ?? null) ? now() : null,
                    ])->save(),
                };

                $succeeded[] = $uuid;
            } catch (\Throwable $exception) {
                $failed[] = ['uuid' => $uuid, 'reason' => $exception->getMessage()];
            }
        }

        return ApiResponse::success([
            'action' => $data['action'],
            'succeeded' => $succeeded,
            'failed' => $failed,
        ], "{$data['action']}: " . count($succeeded) . ' succeeded, ' . count($failed) . ' failed.');
    }

    /**
     * Lifecycle board across pre-tenant requests and provisioned tenants.
     */
    public function lifecycle(): JsonResponse
    {
        return ApiResponse::success([
            'board' => $this->lifecycle->board(),
        ]);
    }

    private function find(string $uuid): SaasOnboardingRequest
    {
        return SaasOnboardingRequest::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Counts the operator queue cares about: what needs a human right now.
     */
    private function summary(): array
    {
        $byStatus = SaasOnboardingRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'awaiting_payment' => (int) ($byStatus[OnboardingRequestStatus::AwaitingPayment->value] ?? 0),
            'awaiting_approval' => (int) ($byStatus[OnboardingRequestStatus::AwaitingApproval->value] ?? 0),
            'provisioning' => (int) ($byStatus[OnboardingRequestStatus::Provisioning->value] ?? 0),
            'failed' => (int) ($byStatus[OnboardingRequestStatus::Failed->value] ?? 0),
            'completed' => (int) ($byStatus[OnboardingRequestStatus::Completed->value] ?? 0),
        ];
    }
}
