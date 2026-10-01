<?php

namespace Modules\SeatingPlan\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\SeatingPlan\Services\Floor\FloorService;
use Modules\SeatingPlan\Services\Floor\FloorServiceInterface;
use Modules\SeatingPlan\Services\Reservation\ReservationService;
use Modules\SeatingPlan\Services\Reservation\ReservationServiceInterface;
use Modules\SeatingPlan\Services\Table\TableService;
use Modules\SeatingPlan\Services\Table\TableServiceInterface;
use Modules\SeatingPlan\Services\TableMerge\TableMergeService;
use Modules\SeatingPlan\Services\TableMerge\TableMergeServiceInterface;
use Modules\SeatingPlan\Services\TableViewer\TableViewerService;
use Modules\SeatingPlan\Services\TableViewer\TableViewerServiceInterface;
use Modules\SeatingPlan\Services\Zone\ZoneService;
use Modules\SeatingPlan\Services\Zone\ZoneServiceInterface;

class DeferredSeatingPlanServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: FloorServiceInterface::class,
            concrete: fn($app) => $app->make(FloorService::class)
        );

        $this->app->singleton(
            abstract: ZoneServiceInterface::class,
            concrete: fn($app) => $app->make(ZoneService::class)
        );

        $this->app->singleton(
            abstract: TableServiceInterface::class,
            concrete: fn($app) => $app->make(TableService::class)
        );

        $this->app->singleton(
            abstract: TableViewerServiceInterface::class,
            concrete: fn($app) => $app->make(TableViewerService::class)
        );
        $this->app->singleton(
            abstract: TableMergeServiceInterface::class,
            concrete: fn($app) => $app->make(TableMergeService::class)
        );

        $this->app->singleton(
            abstract: ReservationServiceInterface::class,
            concrete: fn($app) => $app->make(ReservationService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            FloorServiceInterface::class,
            ZoneServiceInterface::class,
            TableServiceInterface::class,
            TableViewerServiceInterface::class,
            TableMergeServiceInterface::class,
            ReservationServiceInterface::class,
        ];
    }
}
