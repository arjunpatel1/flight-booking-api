<?php

namespace Modules\SeatingPlan\Providers;

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

class SeatingPlanServiceProvider extends ServiceProvider
{
    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Bind all service interfaces to their implementations
        $this->app->singleton(
            FloorServiceInterface::class,
            FloorService::class
        );

        $this->app->singleton(
            ZoneServiceInterface::class,
            ZoneService::class
        );

        $this->app->singleton(
            TableServiceInterface::class,
            TableService::class
        );

        $this->app->singleton(
            TableViewerServiceInterface::class,
            TableViewerService::class
        );

        $this->app->singleton(
            TableMergeServiceInterface::class,
            TableMergeService::class
        );

        $this->app->singleton(
            ReservationServiceInterface::class,
            ReservationService::class
        );
    }

    /**
     * Boot the application services.
     */
    public function boot(): void
    {
        // Reservations live in this module but the frontend references the
        // `reservation::` translation namespace, which has no module of its own.
        $reservationLang = base_path('Modules/Reservation/lang');
        if (is_dir($reservationLang)) {
            $this->loadTranslationsFrom($reservationLang, 'reservation');
        }
    }
}
