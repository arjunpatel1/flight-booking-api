<?php

namespace Modules\Saas\Services\CustomerApp;

use Illuminate\Support\Carbon;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Support\CustomerAppEntitlement;

class CustomerAppEntitlementService
{
    public function assertEnabled(Tenant $tenant, string $feature = CustomerAppEntitlement::APP): TenantSubscription
    {
        if (! in_array($feature, CustomerAppEntitlement::ALL, true)) {
            throw new CustomerAppAuthorizationException('UNKNOWN_ENTITLEMENT', 'Unknown customer application entitlement.');
        }

        $subscription = $tenant->subscriptions()
            ->withoutGlobalScopes()
            ->with('plan')
            ->whereIn('status', ['trial', 'active'])
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', Carbon::now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', Carbon::now()))
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhereNull('trial_ends_at')
                    ->orWhere('trial_ends_at', '>=', Carbon::now());
            })
            ->latest('starts_at')
            ->latest('id')
            ->first();

        if (! $subscription || ! $subscription->plan || ! $subscription->plan->is_active) {
            throw new CustomerAppAuthorizationException('SUBSCRIPTION_INACTIVE', 'An active subscription is required.');
        }

        $features = $subscription->plan->features ?? [];
        $overrides = $subscription->overrides ?? [];
        $featureOverrides = (array) data_get($overrides, '_features', []);
        if (array_key_exists($feature, $featureOverrides) && ! (bool) $featureOverrides[$feature]) {
            throw new CustomerAppAuthorizationException('PLAN_FEATURE_DISABLED', 'This capability is disabled for the restaurant.');
        }
        $override = $featureOverrides[$feature] ?? ($overrides[$feature] ?? null);
        $explicitOverride = $override === true || $override === 1 || $override === '1' || is_array($override);

        // Customer App entitlements are deny-by-default. Unlike historical POS
        // plans, an empty feature list never grants these security-sensitive capabilities.
        if (! in_array($feature, $features, true) && ! $explicitOverride) {
            throw new CustomerAppAuthorizationException('PLAN_FEATURE_DISABLED', 'The subscription does not include this capability.');
        }

        return $subscription;
    }
}
