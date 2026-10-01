<?php

namespace Modules\Notification\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;

class DeferredNotificationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppProviderFactory::class);
        $this->app->singleton(NotificationDispatcherService::class);
        $this->app->singleton(
            abstract: NotificationServiceInterface::class,
            concrete: fn($app) => $app->make(NotificationService::class)
        );
    }

    public function provides(): array
    {
        return [
            WhatsAppProviderFactory::class,
            NotificationDispatcherService::class,
            NotificationServiceInterface::class,
        ];
    }
}
