<?php

namespace Modules\Saas\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Saas\Services\FeatureLimit\FeatureLimitService;
use Modules\Saas\Services\FeatureLimit\FeatureLimitServiceInterface;
use Modules\Saas\Services\SubscriptionPlan\SubscriptionPlanService;
use Modules\Saas\Services\SubscriptionPlan\SubscriptionPlanServiceInterface;
use Modules\Saas\Services\Tenant\TenantService;
use Modules\Saas\Services\Tenant\TenantServiceInterface;
use Modules\Saas\Services\TenantSubscription\TenantSubscriptionService;
use Modules\Saas\Services\TenantSubscription\TenantSubscriptionServiceInterface;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Support\TenantDomainResolver;

class DeferredSaasServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantDomainResolver::class);

        $this->app->singleton(
            abstract: TenantServiceInterface::class,
            concrete: fn($app) => $app->make(TenantService::class)
        );

        $this->app->singleton(
            abstract: SubscriptionPlanServiceInterface::class,
            concrete: fn($app) => $app->make(SubscriptionPlanService::class)
        );

        $this->app->singleton(
            abstract: TenantSubscriptionServiceInterface::class,
            concrete: fn($app) => $app->make(TenantSubscriptionService::class)
        );

        $this->app->singleton(
            abstract: FeatureLimitServiceInterface::class,
            concrete: fn($app) => $app->make(FeatureLimitService::class)
        );
    }

    public function provides(): array
    {
        return [
            TenantContext::class,
            TenantDomainResolver::class,
            TenantServiceInterface::class,
            SubscriptionPlanServiceInterface::class,
            TenantSubscriptionServiceInterface::class,
            FeatureLimitServiceInterface::class,
        ];
    }
}
