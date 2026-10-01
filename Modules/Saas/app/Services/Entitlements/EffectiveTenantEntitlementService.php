<?php

namespace Modules\Saas\Services\Entitlements;

use Illuminate\Support\Carbon;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Models\TenantServiceAssignment;
use Illuminate\Support\Facades\Schema;

class EffectiveTenantEntitlementService
{
    public function subscription(Tenant $tenant): ?TenantSubscription
    {
        return $tenant->subscriptions()->withoutGlobalScopes()->with('plan')
            ->whereIn('status', ['trial', 'active'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', Carbon::now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', Carbon::now()))
            ->where(fn ($query) => $query->where('status', 'active')->orWhere(function ($trial) {
                $trial->where('status', 'trial')->where(function ($expiry) {
                    $expiry->where('trial_ends_at', '>=', Carbon::now())
                        ->orWhere(fn ($fallback) => $fallback->whereNull('trial_ends_at')->where('ends_at', '>=', Carbon::now()));
                });
            }))
            ->latest('starts_at')->latest('id')->first();
    }

    public function features(Tenant $tenant): array
    {
        $subscription = $this->subscription($tenant);
        if (! $subscription?->plan?->is_active) return [];

        $features = collect($this->resolveFeatures(
            (array) ($subscription->plan->features ?? []),
            (array) ($subscription->overrides ?? []),
        ));
        foreach ((array) data_get($tenant->settings ?? [], 'feature_flags.tenant', []) as $feature => $value) {
            $enabled = (bool) (is_array($value) ? ($value['enabled'] ?? false) : $value);
            $features = $enabled ? $features->push((string) $feature) : $features->reject(fn ($item) => $item === $feature);
        }

        $features = collect($this->applySubscriptionPolicy($features->all(), (string) $subscription->status));

        // Once explicit service terms exist, their dates/payment state govern
        // that service, including cancellation. Never grant paid access from
        // the mere presence of an invoice or a tenant flag.
        if (Schema::hasTable('tenant_service_assignments')) {
            foreach (TenantServiceAssignment::query()->where('tenant_id', $tenant->id)
                ->with('invoice')->orderByDesc('starts_at')->orderByDesc('id')->get()->groupBy('feature') as $feature => $terms) {
                $service = $terms->first(fn ($s) => $s->starts_at <= now());
                if (! $service) continue;

                // Expired add-on terms no longer override a feature that is now
                // included by the tenant's active package. A cancelled or unpaid
                // current term still revokes access as recorded.
                if ($service->status === 'active' && $service->ends_at <= now()) continue;

                $allowed = $service->grantsAccess() && ($service->billing_mode !== 'subscription' || $features->contains($feature));
                $features = $features->reject(fn ($f) => $f === $feature);
                if ($allowed) $features = $features->push($feature);
            }
        }
        return $features->unique()->values()->all();
    }

    /** Paid managed services are never inherited from a free-trial package. */
    public function applySubscriptionPolicy(array $features, string $status): array
    {
        if ($status !== 'trial') return $features;

        // Starter trials keep explicitly included WhatsApp ordering access.
        // Delivery remains separately controlled because it can incur provider charges.
        return array_values(array_filter($features, fn ($feature) => $feature !== 'delivery'));
    }

    public function resolveFeatures(array $planFeatures, array $overrides): array
    {
        $features = collect($planFeatures)->filter()->map(fn ($feature) => (string) $feature);
        // Preserve legacy top-level feature/limit overrides. New records use
        // the explicit _features map so access and numerical limits no longer
        // compete for the same form field.
        foreach (collect($overrides)->except('_features') as $feature => $value) {
            $enabled = $value === true || $value === 1 || $value === '1' || is_array($value);
            $features = $enabled ? $features->push((string) $feature) : $features->reject(fn ($item) => $item === $feature);
        }
        foreach ((array) data_get($overrides, '_features', []) as $feature => $value) {
            $enabled = $value === true || $value === 1 || $value === '1' || is_array($value);
            $features = $enabled ? $features->push((string) $feature) : $features->reject(fn ($item) => $item === $feature);
        }

        // Older plans stored the ordering capabilities separately but omitted the
        // umbrella feature used by tenant routes and navigation.
        if ($features->intersect([
            'whatsapp_bring_your_own_api',
            'whatsapp_managed_api',
            'whatsapp_order_payments',
            'whatsapp_order_automation',
            'whatsapp_human_handoff',
        ])->isNotEmpty()) {
            $features->push('whatsapp_ordering');
        }

        return $features->unique()->values()->all();
    }

    public function has(Tenant $tenant, string $feature): bool
    {
        return in_array($feature, $this->features($tenant), true);
    }
}
