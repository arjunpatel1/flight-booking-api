<?php

namespace Modules\Notification\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Notification\Commands\CleanupWhatsAppLogs;
use Modules\Notification\Commands\RetryFailedWhatsAppMessages;
use Modules\Notification\Commands\SendBirthdayOffers;
use Modules\Notification\Commands\SendAnniversaryOffers;
use Modules\Notification\Commands\SendInactiveCustomerOffers;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppProviderFactory::class);
        $this->app->singleton(NotificationDispatcherService::class);
        $this->app->singleton(NotificationServiceInterface::class, NotificationService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupWhatsAppLogs::class,
                RetryFailedWhatsAppMessages::class,
                SendBirthdayOffers::class,
                SendAnniversaryOffers::class,
                SendInactiveCustomerOffers::class,
            ]);
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command(SendInactiveCustomerOffers::class)
                ->dailyAt(setting('crm_inactive_customer_automation_time', '10:00'))
                ->withoutOverlapping()
                ->when(fn() => (bool) setting('whatsapp_marketing_campaigns_enabled', false)
                    && (bool) setting('crm_inactive_customer_automation_enabled', false));

            $this->app->make(Schedule::class)
                ->command(SendBirthdayOffers::class)
                ->dailyAt(setting('crm_birthday_offer_automation_time', '09:00'))
                ->withoutOverlapping()
                ->when(fn() => (bool) setting('whatsapp_marketing_campaigns_enabled', false)
                    && (bool) setting('crm_birthday_offer_automation_enabled', false));

            $this->app->make(Schedule::class)
                ->command(SendAnniversaryOffers::class)
                ->dailyAt(setting('crm_anniversary_offer_automation_time', '09:00'))
                ->withoutOverlapping()
                ->when(fn() => (bool) setting('whatsapp_marketing_campaigns_enabled', false)
                    && (bool) setting('crm_anniversary_offer_automation_enabled', false));
        });
    }
}
