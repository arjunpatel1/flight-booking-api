<?php

namespace Tests\Feature\Saas;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Services\FeatureLimit\FeatureLimitServiceInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class TenantFeatureLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_access_requires_an_active_subscription_and_enabled_feature(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant Access',
            'slug' => 'tenant-access',
            'is_active' => true,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Waiter',
            'code' => 'waiter',
            'features' => ['waiter_app'],
            'limits' => [],
            'is_active' => true,
        ]);
        $service = app(FeatureLimitServiceInterface::class);

        $this->assertFalse($service->isEnabled($tenant, 'waiter_app'));

        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $tenant->unsetRelation('activeSubscription');
        $this->assertTrue($service->isEnabled($tenant, 'waiter_app'));
        $this->assertFalse($service->isEnabled($tenant, 'inventory'));
    }

    public function test_active_legacy_plan_with_empty_feature_catalog_remains_unrestricted(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Legacy Tenant',
            'slug' => 'legacy-tenant',
            'is_active' => true,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Legacy',
            'code' => 'legacy',
            'features' => [],
            'limits' => [],
            'is_active' => true,
        ]);
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $service = app(FeatureLimitServiceInterface::class);

        $this->assertTrue($service->isEnabled($tenant, 'inventory'));
    }

    public function test_branch_usage_can_be_recorded_and_released(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Starter',
            'code' => 'starter',
            'features' => ['branches'],
            'limits' => ['branches' => 1],
            'is_active' => true,
        ]);
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $service = app(FeatureLimitServiceInterface::class);
        $this->assertTrue($service->hasCapacity($tenant, 'branches'));

        $service->recordUsage($tenant, 'branches');
        $this->assertFalse($service->hasCapacity($tenant, 'branches'));

        $service->releaseUsage($tenant, 'branches');
        $this->assertTrue($service->hasCapacity($tenant, 'branches'));
    }
}
