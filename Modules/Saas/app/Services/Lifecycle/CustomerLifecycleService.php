<?php

namespace Modules\Saas\Services\Lifecycle;

use Illuminate\Support\Collection;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Models\SaasCustomerSuccessRecord;
use Modules\Saas\Models\SaasOnboardingRequest;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

/**
 * Customer lifecycle tracking, from Lead to Cancelled.
 *
 * No lifecycle model of its own, by design:
 *
 *  - the *current* stage is a single value, stored on the tenant
 *    (`tenants.lifecycle_stage`) or derived from the onboarding request's own
 *    status for customers who do not have a tenant yet;
 *  - the *history* is written as SaasCustomerSuccessRecord rows of type
 *    `lifecycle`, reusing Customer Success rather than duplicating it.
 *
 * That means a stage change already appears on the Customer Success board, is
 * already assignable, and already has due dates — none of which had to be
 * rebuilt.
 */
class CustomerLifecycleService
{
    public const RECORD_TYPE = 'lifecycle';

    /**
     * Move a tenant to a new stage and audit it. No-ops when the stage is
     * unchanged, so a nightly health job does not fill the board with noise.
     */
    public function transitionTenant(
        Tenant $tenant,
        CustomerLifecycleStage $stage,
        ?string $reason = null,
        ?User $actor = null,
    ): Tenant {
        $current = $this->stageForTenant($tenant);

        if ($current === $stage) {
            return $tenant;
        }

        $tenant->forceFill([
            'lifecycle_stage' => $stage->value,
            'lifecycle_changed_at' => now(),
        ])->save();

        $this->audit($tenant, $current, $stage, $reason, $actor);

        return $tenant->refresh();
    }

    /**
     * Current stage of a tenant. Falls back to a sensible derivation for
     * tenants created before lifecycle tracking existed, so the board is never
     * blank for existing customers.
     */
    public function stageForTenant(Tenant $tenant): CustomerLifecycleStage
    {
        $stored = $tenant->getAttributes()['lifecycle_stage'] ?? null;

        if ($stored && $case = CustomerLifecycleStage::tryFrom($stored)) {
            return $case;
        }

        return $this->deriveTenantStage($tenant);
    }

    /**
     * Best-effort stage for a tenant that has never been explicitly staged.
     * Reads only what already exists — no new state.
     */
    private function deriveTenantStage(Tenant $tenant): CustomerLifecycleStage
    {
        if (! $tenant->is_active) {
            return CustomerLifecycleStage::Cancelled;
        }

        $subscription = $tenant->subscriptions()->latest('id')->first();

        return match (true) {
            $subscription?->status === 'cancelled' => CustomerLifecycleStage::Cancelled,
            $subscription?->status === 'trial' => CustomerLifecycleStage::Trial,
            default => CustomerLifecycleStage::Healthy,
        };
    }

    /**
     * Record the pre-tenant stage of an onboarding request. There is no tenant
     * to stamp yet, so this only writes history once a tenant is attached;
     * before that the request's own status is the source of truth (see
     * SaasOnboardingRequest::lifecycleStage()).
     */
    public function recordForRequest(
        SaasOnboardingRequest $request,
        CustomerLifecycleStage $stage,
        ?string $reason = null,
    ): void {
        $request->recordEvent('lifecycle', "Lifecycle stage: {$stage->label()}", [
            'stage' => $stage->value,
            'reason' => $reason,
        ]);

        if ($request->tenant_id && $tenant = $request->tenant) {
            $this->transitionTenant($tenant, $stage, $reason);
        }
    }

    /**
     * Board data: how many customers sit at each stage, counting both
     * provisioned tenants and pre-tenant onboarding requests so nobody in the
     * pipeline is invisible.
     *
     * @return array<string, array{stage: string, label: string, tenants: int, requests: int, total: int}>
     */
    public function board(): array
    {
        $tenantCounts = Tenant::query()
            ->withoutGlobalScopes()
            ->selectRaw('lifecycle_stage, COUNT(*) as aggregate')
            ->groupBy('lifecycle_stage')
            ->pluck('aggregate', 'lifecycle_stage');

        $requestStages = SaasOnboardingRequest::query()
            ->whereNull('tenant_id')
            ->get(['id', 'status'])
            ->groupBy(fn (SaasOnboardingRequest $request) => $request->lifecycleStage()->value)
            ->map->count();

        $board = [];
        foreach (CustomerLifecycleStage::cases() as $stage) {
            $tenants = (int) ($tenantCounts[$stage->value] ?? 0);
            $requests = (int) ($requestStages[$stage->value] ?? 0);

            $board[$stage->value] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'tenants' => $tenants,
                'requests' => $requests,
                'total' => $tenants + $requests,
            ];
        }

        return $board;
    }

    /**
     * Transition history for one tenant, newest first.
     */
    public function history(Tenant $tenant, int $limit = 50): Collection
    {
        return SaasCustomerSuccessRecord::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', self::RECORD_TYPE)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    private function audit(
        Tenant $tenant,
        CustomerLifecycleStage $from,
        CustomerLifecycleStage $to,
        ?string $reason,
        ?User $actor,
    ): void {
        SaasCustomerSuccessRecord::query()->create([
            'tenant_id' => $tenant->id,
            'type' => self::RECORD_TYPE,
            'visibility' => 'internal',
            'title' => "{$from->label()} → {$to->label()}",
            'body' => $reason,
            'priority' => $to === CustomerLifecycleStage::Cancelled ? 'high' : 'normal',
            // Closed on creation: this is a record of something that already
            // happened, not a task somebody has to action.
            'status' => 'closed',
            'completed_at' => now(),
            'created_by' => $actor?->id,
        ]);
    }
}
