<?php

namespace Modules\Flight\Services\Subscription;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Exceptions\InvalidPlanException;
use Modules\Flight\Exceptions\QuotaExceededException;
use Modules\Flight\Exceptions\SubscriptionExpiredException;
use Modules\Flight\Models\FlightSubscriptionOverage;
use Modules\Flight\Models\FlightSubscriptionUsageLog;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantFeatureLimit;
use Modules\Saas\Models\TenantSubscription;

class FlightSubscriptionQuotaService
{
    public const METRIC_USERS      = 'users';
    public const METRIC_BOOKINGS   = 'bookings';
    public const METRIC_API_CALLS  = 'api_calls';
    public const METRIC_SUPPLIERS  = 'suppliers';
    public const METRIC_AGENTS     = 'b2b_agents';
    public const METRIC_STORAGE    = 'storage';

    public const ALL_METRICS = [
        self::METRIC_USERS,
        self::METRIC_BOOKINGS,
        self::METRIC_API_CALLS,
        self::METRIC_SUPPLIERS,
        self::METRIC_AGENTS,
        self::METRIC_STORAGE,
    ];

    /**
     * Standardized plan definitions for Starter, Pro, and Enterprise tiers.
     */
    public const PLANS = [
        'starter' => [
            'name'          => 'Flight Starter',
            'code'          => 'starter',
            'description'   => 'Entry tier for boutique flight operators and new agencies.',
            'billing_cycle' => 'monthly',
            'price'         => 99.00,
            'currency'      => 'USD',
            'features'      => [
                'flight_booking',
                'flight_inventory',
                'flight_schedules',
            ],
            'limits'        => [
                self::METRIC_USERS     => 3,
                self::METRIC_BOOKINGS  => 100,
                self::METRIC_API_CALLS => 1000,
                self::METRIC_SUPPLIERS => 1,
                self::METRIC_AGENTS    => 2,
                self::METRIC_STORAGE   => 1024, // 1 GB in MB
            ],
            'overage_policy' => [
                'allowed' => false,
                'rates'   => [],
            ],
        ],
        'pro' => [
            'name'          => 'Flight Pro',
            'code'          => 'pro',
            'description'   => 'Professional tier for high-volume travel consolidators and agencies.',
            'billing_cycle' => 'monthly',
            'price'         => 499.00,
            'currency'      => 'USD',
            'features'      => [
                'flight_booking',
                'flight_inventory',
                'flight_schedules',
                'flight_fares',
                'flight_b2b_network',
                'flight_wallet',
                'flight_commission',
            ],
            'limits'        => [
                self::METRIC_USERS     => 25,
                self::METRIC_BOOKINGS  => 2500,
                self::METRIC_API_CALLS => 50000,
                self::METRIC_SUPPLIERS => 5,
                self::METRIC_AGENTS    => 20,
                self::METRIC_STORAGE   => 10240, // 10 GB in MB
            ],
            'overage_policy' => [
                'allowed' => true,
                'rates'   => [
                    self::METRIC_BOOKINGS  => 1.50,
                    self::METRIC_API_CALLS => 0.01,
                    self::METRIC_AGENTS    => 25.00,
                    self::METRIC_USERS     => 15.00,
                    self::METRIC_STORAGE   => 0.10,
                ],
            ],
        ],
        'enterprise' => [
            'name'          => 'Flight Enterprise',
            'code'          => 'enterprise',
            'description'   => 'Uncapped enterprise tier for airlines, major GDS distributors, and global OTAs.',
            'billing_cycle' => 'monthly',
            'price'         => 1999.00,
            'currency'      => 'USD',
            'features'      => [
                'flight_booking',
                'flight_inventory',
                'flight_schedules',
                'flight_fares',
                'flight_b2b_network',
                'flight_wallet',
                'flight_commission',
                'flight_suppliers_custom',
                'api_access',
                'advanced_analytics',
                'priority_support',
            ],
            'limits'        => [
                self::METRIC_USERS     => null, // Unlimited
                self::METRIC_BOOKINGS  => null,
                self::METRIC_API_CALLS => null,
                self::METRIC_SUPPLIERS => null,
                self::METRIC_AGENTS    => null,
                self::METRIC_STORAGE   => null,
            ],
            'overage_policy' => [
                'allowed' => true,
                'rates'   => [],
            ],
        ],
    ];

    /**
     * Ensure Starter, Pro, and Enterprise plans exist in the database.
     */
    public function seedPlans(): array
    {
        $seeded = [];

        foreach (self::PLANS as $code => $definition) {
            $plan = SubscriptionPlan::updateOrCreate(
                ['code' => $code],
                [
                    'name'          => $definition['name'],
                    'description'   => $definition['description'],
                    'billing_cycle' => $definition['billing_cycle'],
                    'price'         => $definition['price'],
                    'currency'      => $definition['currency'],
                    'access_scope'  => 'global',
                    'features'      => $definition['features'],
                    'limits'        => $definition['limits'],
                    'is_active'     => true,
                ]
            );
            $seeded[$code] = $plan;
        }

        return $seeded;
    }

    /**
     * Get all available subscription plans.
     */
    public function getPlans(): Collection
    {
        $this->seedPlans();
        return SubscriptionPlan::whereIn('code', array_keys(self::PLANS))->get();
    }

    /**
     * Retrieve a specific plan by code.
     */
    public function getPlan(string $code): SubscriptionPlan
    {
        $this->seedPlans();
        $plan = SubscriptionPlan::where('code', strtolower($code))->first();

        if (! $plan) {
            throw new InvalidPlanException($code);
        }

        return $plan;
    }

    /**
     * Get current active subscription for tenant.
     */
    public function getCurrentSubscription(Tenant|int $tenant): ?TenantSubscription
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return TenantSubscription::withoutGlobalScopes()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trial'])
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest('starts_at')
            ->latest('id')
            ->first();
    }

    /**
     * Subscribe tenant to a plan.
     */
    public function subscribe(Tenant|int $tenant, string $planCode, array $options = []): TenantSubscription
    {
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenant);
        $plan = $this->getPlan($planCode);
        $durationMonths = $options['duration_months'] ?? 1;

        return DB::transaction(function () use ($tenantModel, $plan, $durationMonths, $planCode) {
            // Cancel previous active subscriptions
            TenantSubscription::where('tenant_id', $tenantModel->id)
                ->whereIn('status', ['active', 'trial'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $subscription = TenantSubscription::create([
                'tenant_id'            => $tenantModel->id,
                'subscription_plan_id' => $plan->id,
                'status'               => 'active',
                'starts_at'            => now(),
                'ends_at'              => now()->addMonths($durationMonths),
                'trial_ends_at'        => null,
                'overrides'            => [
                    'plan_code' => $planCode,
                ],
            ]);

            $this->syncQuotasForPlan($tenantModel->id, $planCode);

            return $subscription->fresh(['plan']);
        });
    }

    /**
     * Upgrade subscription to a higher tier.
     */
    public function upgrade(Tenant|int $tenant, string $newPlanCode): TenantSubscription
    {
        return $this->subscribe($tenant, $newPlanCode);
    }

    /**
     * Downgrade subscription to a lower tier.
     */
    public function downgrade(Tenant|int $tenant, string $newPlanCode): TenantSubscription
    {
        return $this->subscribe($tenant, $newPlanCode);
    }

    /**
     * Renew subscription and reset cyclical usage counters (bookings, api calls).
     */
    public function renew(Tenant|int $tenant, ?Carbon $newEndsAt = null): TenantSubscription
    {
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenant);
        $subscription = $this->getCurrentSubscription($tenantModel);

        if (! $subscription) {
            throw new SubscriptionExpiredException('No active subscription found to renew.');
        }

        return DB::transaction(function () use ($subscription, $tenantModel, $newEndsAt) {
            $currentEnd = $subscription->ends_at ?? now();
            $endsAt = $newEndsAt ?? Carbon::parse($currentEnd)->addMonth();

            $subscription->update([
                'status'  => 'active',
                'ends_at' => $endsAt,
            ]);

            // Reset monthly cycle quotas (bookings & api calls), preserving cumulative counts (users, agents)
            TenantFeatureLimit::where('tenant_id', $tenantModel->id)
                ->whereIn('feature', [self::METRIC_BOOKINGS, self::METRIC_API_CALLS])
                ->update([
                    'used'      => 0,
                    'resets_at' => $endsAt,
                ]);

            return $subscription->fresh(['plan']);
        });
    }

    /**
     * Mark subscription as expired.
     */
    public function expire(Tenant|int $tenant): TenantSubscription
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trial'])
            ->latest('id')
            ->firstOrFail();

        $subscription->update([
            'status'  => 'expired',
            'ends_at' => now()->subMinute(),
        ]);

        return $subscription->fresh();
    }

    /**
     * Cancel subscription.
     */
    public function cancel(Tenant|int $tenant): TenantSubscription
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trial'])
            ->latest('id')
            ->firstOrFail();

        $subscription->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return $subscription->fresh();
    }

    /**
     * Check if a feature is enabled under the tenant's active plan.
     */
    public function hasFeature(Tenant|int $tenant, string $feature): bool
    {
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::findOrFail($tenant);
        $subscription = $this->getCurrentSubscription($tenantModel);

        if (! $subscription || ! $subscription->plan?->is_active) {
            return false;
        }

        $features = (array) ($subscription->plan->features ?? []);
        return in_array($feature, $features, true);
    }

    /**
     * Retrieve status, limits, and usage for a given metric.
     */
    public function getQuota(Tenant|int $tenant, string $metric): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $limitRecord = TenantFeatureLimit::where('tenant_id', $tenantId)
            ->where('feature', $metric)
            ->first();

        $limit = $limitRecord?->limit;
        $used = $limitRecord?->used ?? 0;
        $allowedOverage = (bool) ($limitRecord?->overage_allowed ?? false);
        $overageUnitPrice = (float) ($limitRecord?->overage_unit_price ?? 0.0);

        $isUnlimited = ($limit === null);
        $remaining = $isUnlimited ? null : max(0, $limit - $used);
        $overageUnits = $isUnlimited ? 0 : max(0, $used - $limit);

        return [
            'metric'             => $metric,
            'limit'              => $limit,
            'used'               => $used,
            'remaining'          => $remaining,
            'is_unlimited'       => $isUnlimited,
            'overage_units'      => $overageUnits,
            'overage_allowed'    => $allowedOverage,
            'overage_unit_price' => $overageUnitPrice,
            'resets_at'          => $limitRecord?->resets_at,
        ];
    }

    /**
     * Determine if a tenant has remaining quota or allowed overage capacity.
     */
    public function canConsume(Tenant|int $tenant, string $metric, int $units = 1): bool
    {
        $quota = $this->getQuota($tenant, $metric);

        if ($quota['is_unlimited']) {
            return true;
        }

        $newUsed = $quota['used'] + $units;

        if ($newUsed <= $quota['limit']) {
            return true;
        }

        return $quota['overage_allowed'];
    }

    /**
     * Enforce quota: throws QuotaExceededException if capacity is exceeded without allowed overage.
     */
    public function enforceQuota(Tenant|int $tenant, string $metric, int $units = 1): void
    {
        $quota = $this->getQuota($tenant, $metric);

        if ($quota['is_unlimited']) {
            return;
        }

        $newUsed = $quota['used'] + $units;

        if ($newUsed > $quota['limit'] && ! $quota['overage_allowed']) {
            throw new QuotaExceededException(
                metric: $metric,
                limit: $quota['limit'],
                currentUsed: $quota['used'],
                requestedUnits: $units
            );
        }
    }

    /**
     * Record usage: increments usage counter with row locking and records overage charges if applicable.
     */
    public function recordUsage(
        Tenant|int $tenant,
        string $metric,
        int $units = 1,
        ?string $actor = 'System',
        ?string $reference = null
    ): array {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return DB::transaction(function () use ($tenantId, $metric, $units, $actor, $reference) {
            $limitRecord = TenantFeatureLimit::where('tenant_id', $tenantId)
                ->where('feature', $metric)
                ->lockForUpdate()
                ->first();

            if (! $limitRecord) {
                // Initialize default limit if missing
                $limitRecord = TenantFeatureLimit::create([
                    'tenant_id' => $tenantId,
                    'feature'   => $metric,
                    'limit'     => 100,
                    'used'      => 0,
                ]);
            }

            $limit = $limitRecord->limit;
            $currentUsed = $limitRecord->used;
            $newUsed = $currentUsed + $units;
            $isUnlimited = ($limit === null);

            // Enforce quota if overage is forbidden
            if (! $isUnlimited && $newUsed > $limit && ! $limitRecord->overage_allowed) {
                throw new QuotaExceededException(
                    metric: $metric,
                    limit: $limit,
                    currentUsed: $currentUsed,
                    requestedUnits: $units
                );
            }

            $isOverage = (! $isUnlimited && $newUsed > $limit);
            $limitRecord->increment('used', $units);

            // Record overage charge if soft quota overage occurred
            if ($isOverage && $limitRecord->overage_allowed) {
                $overageDelta = ($currentUsed >= $limit) ? $units : ($newUsed - $limit);
                $unitRate = (float) $limitRecord->overage_unit_price;
                $charge = round($overageDelta * $unitRate, 2);

                FlightSubscriptionOverage::create([
                    'tenant_id'   => $tenantId,
                    'metric'      => $metric,
                    'units'       => $overageDelta,
                    'unit_price'  => $unitRate,
                    'total_price' => $charge,
                    'reference'   => $reference,
                    'actor'       => $actor,
                    'description' => sprintf('Overage charge of %.2f for %d units of %s', $charge, $overageDelta, $metric),
                ]);
            }

            // Record usage audit log
            FlightSubscriptionUsageLog::create([
                'tenant_id'    => $tenantId,
                'metric'       => $metric,
                'units'        => $units,
                'current_used' => $newUsed,
                'quota_limit'  => $limit,
                'is_overage'   => $isOverage,
                'reference'    => $reference,
                'actor'        => $actor,
            ]);

            return $this->getQuota($tenantId, $metric);
        });
    }

    /**
     * Release allocated usage (e.g. user seat deleted or agent removed).
     */
    public function releaseUsage(Tenant|int $tenant, string $metric, int $units = 1): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return DB::transaction(function () use ($tenantId, $metric, $units) {
            $limitRecord = TenantFeatureLimit::where('tenant_id', $tenantId)
                ->where('feature', $metric)
                ->lockForUpdate()
                ->first();

            if ($limitRecord) {
                $limitRecord->update([
                    'used' => max(0, $limitRecord->used - max(0, $units)),
                ]);
            }

            return $this->getQuota($tenantId, $metric);
        });
    }

    /**
     * Get complete subscription overview and usage summary.
     */
    public function getSummary(Tenant|int $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $subscription = $this->getCurrentSubscription($tenantId);
        $plan = $subscription?->plan;

        $quotas = [];
        foreach (self::ALL_METRICS as $metric) {
            $quotas[$metric] = $this->getQuota($tenantId, $metric);
        }

        $totalOverageCharges = (float) FlightSubscriptionOverage::where('tenant_id', $tenantId)->sum('total_price');

        return [
            'has_active_subscription' => ($subscription !== null),
            'subscription'            => $subscription,
            'plan_code'               => $plan?->code,
            'plan_name'               => $plan?->name,
            'status'                  => $subscription?->status ?? 'none',
            'starts_at'               => $subscription?->starts_at,
            'ends_at'                 => $subscription?->ends_at,
            'features'                => $plan?->features ?? [],
            'quotas'                  => $quotas,
            'total_overage_charges'   => $totalOverageCharges,
        ];
    }

    /**
     * Synchronize tenant_feature_limits table with plan limits and overage rules.
     */
    protected function syncQuotasForPlan(int $tenantId, string $planCode): void
    {
        $def = self::PLANS[$planCode] ?? self::PLANS['starter'];
        $limits = $def['limits'] ?? [];
        $overageAllowed = $def['overage_policy']['allowed'] ?? false;
        $overageRates = $def['overage_policy']['rates'] ?? [];

        foreach ($limits as $metric => $limit) {
            $rate = $overageRates[$metric] ?? 0.0000;

            TenantFeatureLimit::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'feature'   => $metric,
                ],
                [
                    'limit'              => $limit,
                    'overage_allowed'    => $overageAllowed,
                    'overage_unit_price' => $rate,
                    'resets_at'          => now()->addMonth(),
                ]
            );
        }
    }
}
