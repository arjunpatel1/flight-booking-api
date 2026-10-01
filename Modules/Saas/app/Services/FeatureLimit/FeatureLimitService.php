<?php

namespace Modules\Saas\Services\FeatureLimit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantFeatureLimit;
use Modules\Saas\Models\TenantSubscription;

class FeatureLimitService implements FeatureLimitServiceInterface
{
    public function resolve(Tenant|int $tenant, string $feature): array
    {
        $tenant = $this->tenant($tenant);
        $subscription = $this->activeSubscription($tenant);
        $plan = $subscription?->plan;
        $tenantLimit = $tenant->featureLimits->firstWhere('feature', $feature);
        $planFeatures = collect($plan?->features ?? []);
        $planLimits = $plan?->limits ?? [];
        $subscriptionOverrides = collect($subscription?->overrides ?? [])->except('_features')->all();

        // A tenant without an active subscription owns no commercial modules.
        // Legacy plans with an empty feature list retain their historical
        // unlimited-module behaviour once an active subscription exists.
        $enabled = $subscription !== null
            && ($planFeatures->isEmpty() || $planFeatures->contains($feature));
        $limit = $planLimits[$feature] ?? null;

        if (array_key_exists($feature, $subscriptionOverrides)) {
            $limit = $subscriptionOverrides[$feature];
            $enabled = true;
        }

        if ($tenantLimit !== null) {
            $limit = $tenantLimit->limit;
        }

        return [
            'feature' => $feature,
            'enabled' => (bool) $enabled,
            'limit' => $this->normalizeLimit($limit),
            'used' => (int) ($tenantLimit?->used ?? 0),
            'remaining' => $this->remaining($this->normalizeLimit($limit), (int) ($tenantLimit?->used ?? 0)),
            'resets_at' => $tenantLimit?->resets_at,
            'subscription_id' => $subscription?->id,
            'subscription_status' => $subscription?->status,
            'plan_id' => $plan?->id,
            'plan_name' => $plan?->name,
        ];
    }

    public function isEnabled(Tenant|int $tenant, string $feature): bool
    {
        return $this->resolve($tenant, $feature)['enabled'];
    }

    public function hasCapacity(Tenant|int $tenant, string $feature, int $increment = 1): bool
    {
        $limit = $this->resolve($tenant, $feature);

        if (! $limit['enabled']) {
            return false;
        }

        if ($limit['limit'] === null) {
            return true;
        }

        return $limit['used'] + $increment <= $limit['limit'];
    }

    public function recordUsage(Tenant|int $tenant, string $feature, int $increment = 1): array
    {
        $tenant = $this->tenant($tenant);

        return DB::transaction(function () use ($tenant, $feature, $increment) {
            $limit = TenantFeatureLimit::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'feature' => $feature],
                    ['limit' => $this->resolve($tenant, $feature)['limit'], 'used' => 0]
                );

            $limit->increment('used', max(0, $increment));
            $tenant->unsetRelation('featureLimits');

            return $this->resolve($tenant->fresh(['featureLimits']), $feature);
        });
    }

    public function releaseUsage(Tenant|int $tenant, string $feature, int $decrement = 1): array
    {
        $tenant = $this->tenant($tenant);

        return DB::transaction(function () use ($tenant, $feature, $decrement) {
            $limit = TenantFeatureLimit::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'feature' => $feature],
                    ['limit' => $this->resolve($tenant, $feature)['limit'], 'used' => 0]
                );

            $limit->update([
                'used' => max(0, $limit->used - max(0, $decrement)),
            ]);
            $tenant->unsetRelation('featureLimits');

            return $this->resolve($tenant->fresh(['featureLimits']), $feature);
        });
    }

    protected function tenant(Tenant|int $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant->loadMissing(['featureLimits']);
        }

        return Tenant::query()
            ->with(['featureLimits'])
            ->findOrFail($tenant);
    }

    protected function activeSubscription(Tenant $tenant): ?TenantSubscription
    {
        return $tenant->subscriptions()
            ->with('plan')
            ->whereIn('status', ['trial', 'active'])
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', Carbon::now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', Carbon::now());
            })
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    protected function normalizeLimit(mixed $limit): ?int
    {
        if ($limit === null || $limit === '') {
            return null;
        }

        return max(0, (int) $limit);
    }

    protected function remaining(?int $limit, int $used): ?int
    {
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $used);
    }
}
