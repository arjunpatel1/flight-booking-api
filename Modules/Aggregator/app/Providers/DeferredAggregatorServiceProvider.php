<?php

namespace Modules\Aggregator\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Aggregator\Services\Integration\AggregatorIntegrationService;
use Modules\Aggregator\Services\Integration\AggregatorIntegrationServiceInterface;
use Modules\Aggregator\Services\ItemAvailability\AggregatorItemAvailabilityService;
use Modules\Aggregator\Services\ItemAvailability\AggregatorItemAvailabilityServiceInterface;
use Modules\Aggregator\Services\MenuSync\AggregatorMenuSyncService;
use Modules\Aggregator\Services\MenuSync\AggregatorMenuSyncServiceInterface;
use Modules\Aggregator\Services\OrderSync\AggregatorOrderSyncService;
use Modules\Aggregator\Services\OrderSync\AggregatorOrderSyncServiceInterface;
use Modules\Aggregator\Services\StatusSync\AggregatorStatusSyncService;
use Modules\Aggregator\Services\StatusSync\AggregatorStatusSyncServiceInterface;
use Modules\Aggregator\Services\Webhook\AggregatorWebhookService;
use Modules\Aggregator\Services\Webhook\AggregatorWebhookServiceInterface;

class DeferredAggregatorServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(
            abstract: AggregatorIntegrationServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorIntegrationService::class)
        );

        $this->app->singleton(
            abstract: AggregatorMenuSyncServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorMenuSyncService::class)
        );

        $this->app->singleton(
            abstract: AggregatorOrderSyncServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorOrderSyncService::class)
        );

        $this->app->singleton(
            abstract: AggregatorStatusSyncServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorStatusSyncService::class)
        );

        $this->app->singleton(
            abstract: AggregatorWebhookServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorWebhookService::class)
        );

        $this->app->singleton(
            abstract: AggregatorItemAvailabilityServiceInterface::class,
            concrete: fn($app) => $app->make(AggregatorItemAvailabilityService::class)
        );
    }

    public function provides(): array
    {
        return [
            AggregatorIntegrationServiceInterface::class,
            AggregatorMenuSyncServiceInterface::class,
            AggregatorOrderSyncServiceInterface::class,
            AggregatorStatusSyncServiceInterface::class,
            AggregatorWebhookServiceInterface::class,
            AggregatorItemAvailabilityServiceInterface::class,
        ];
    }
}
