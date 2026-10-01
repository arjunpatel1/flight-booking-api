<?php

namespace Modules\Pos\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Pos\Services\KitchenStation\KitchenStationService;
use Modules\Pos\Services\KitchenStation\KitchenStationServiceInterface;
use Modules\Pos\Services\KitchenViewer\KitchenViewerService;
use Modules\Pos\Services\KitchenViewer\KitchenViewerServiceInterface;
use Modules\Pos\Services\OfflineMode\OfflineModeService;
use Modules\Pos\Services\OfflineMode\OfflineModeServiceInterface;
use Modules\Pos\Services\PosCashMovement\PosCashMovementService;
use Modules\Pos\Services\PosCashMovement\PosCashMovementServiceInterface;
use Modules\Pos\Services\PosRegister\PosRegisterService;
use Modules\Pos\Services\PosRegister\PosRegisterServiceInterface;
use Modules\Pos\Services\PosSession\PosSessionService;
use Modules\Pos\Services\PosSession\PosSessionServiceInterface;
use Modules\Pos\Services\PosViewer\PosViewerService;
use Modules\Pos\Services\PosViewer\PosViewerServiceInterface;
use Modules\Pos\Services\QRCode\QRCodeService;
use Modules\Pos\Services\QRCode\QRCodeServiceInterface;

class DeferredPosServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: PosRegisterServiceInterface::class,
            concrete: fn($app) => $app->make(PosRegisterService::class)
        );

        $this->app->singleton(
            abstract: PosSessionServiceInterface::class,
            concrete: fn($app) => $app->make(PosSessionService::class)
        );

        $this->app->singleton(
            abstract: PosCashMovementServiceInterface::class,
            concrete: fn($app) => $app->make(PosCashMovementService::class)
        );

        $this->app->singleton(
            abstract: PosViewerServiceInterface::class,
            concrete: fn($app) => $app->make(PosViewerService::class)
        );

        $this->app->singleton(
            abstract: KitchenViewerServiceInterface::class,
            concrete: fn($app) => $app->make(KitchenViewerService::class)
        );

        $this->app->singleton(
            abstract: KitchenStationServiceInterface::class,
            concrete: fn($app) => $app->make(KitchenStationService::class)
        );

        $this->app->singleton(
            abstract: OfflineModeServiceInterface::class,
            concrete: fn($app) => $app->make(OfflineModeService::class)
        );

        $this->app->singleton(
            abstract: QRCodeServiceInterface::class,
            concrete: fn($app) => $app->make(QRCodeService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            PosRegisterServiceInterface::class,
            PosSessionServiceInterface::class,
            PosCashMovementServiceInterface::class,
            PosViewerServiceInterface::class,
            KitchenViewerServiceInterface::class,
            KitchenStationServiceInterface::class,
            OfflineModeServiceInterface::class,
            QRCodeServiceInterface::class,
        ];
    }
}
