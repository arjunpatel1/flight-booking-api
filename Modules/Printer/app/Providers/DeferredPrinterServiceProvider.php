<?php

namespace Modules\Printer\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Printer\Services\Agent\PrintAgentService;
use Modules\Printer\Services\Agent\PrintAgentServiceInterface;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use Modules\Printer\Services\AgentSignature\AgentSignatureService;
use Modules\Printer\Services\AgentSignature\AgentSignatureServiceInterface;
use Modules\Printer\Services\Dispatcher\PrintDispatcherService;
use Modules\Printer\Services\Dispatcher\PrintDispatcherServiceInterface;
use Modules\Printer\Services\Printer\PrinterService;
use Modules\Printer\Services\Printer\PrinterServiceInterface;
use Modules\Printer\Services\PrintJob\PrintJobService;
use Modules\Printer\Services\PrintJob\PrintJobServiceInterface;
use Modules\Printer\Services\Render\PrintRenderService;
use Modules\Printer\Services\Render\PrintRenderServiceInterface;

class DeferredPrinterServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: PrinterServiceInterface::class,
            concrete: fn($app) => $app->make(PrinterService::class)
        );

        $this->app->singleton(
            abstract: PrintAgentServiceInterface::class,
            concrete: fn($app) => $app->make(PrintAgentService::class)
        );

        $this->app->singleton(
            abstract: PrintJobServiceInterface::class,
            concrete: fn($app) => $app->make(PrintJobService::class)
        );

        $this->app->singleton(
            abstract: AgentSignatureServiceInterface::class,
            concrete: fn($app) => $app->make(AgentSignatureService::class)
        );

        $this->app->singleton(
            abstract: AgentPollServiceInterface::class,
            concrete: fn($app) => $app->make(AgentPollService::class)
        );


        $this->app->singleton(
            abstract: PrintDispatcherServiceInterface::class,
            concrete: fn($app) => $app->make(PrintDispatcherService::class)
        );

        $this->app->singleton(
            abstract: PrintRenderServiceInterface::class,
            concrete: fn($app) => $app->make(PrintRenderService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            PrinterServiceInterface::class,
            PrintAgentServiceInterface::class,
            PrintJobServiceInterface::class,
            AgentSignatureServiceInterface::class,
            AgentPollServiceInterface::class,
            PrintDispatcherServiceInterface::class,
            PrintRenderServiceInterface::class
        ];
    }
}
