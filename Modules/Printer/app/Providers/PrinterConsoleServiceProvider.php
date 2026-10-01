<?php

namespace Modules\Printer\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Printer\Commands\PruneAgentLogsCommand;
use Modules\Printer\Commands\RecoverPrintJobsCommand;
use Modules\Printer\Commands\RunLocalPrintAgent;

class PrinterConsoleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunLocalPrintAgent::class,
                RecoverPrintJobsCommand::class,
                PruneAgentLogsCommand::class,
            ]);

            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('printer:recover-jobs')
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();

                $schedule->command('printer:prune-agent-logs')
                    ->dailyAt('03:00')
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }
}
