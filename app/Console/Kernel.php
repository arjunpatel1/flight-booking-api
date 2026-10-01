<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Modules\Voice\Jobs\CheckDelayedOrdersJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Analytics fact tables daily rollup at 1 AM
        $schedule->command('analytics:build-fact-tables')
            ->dailyAt('01:00')
            ->onQueue('analytics')
            ->withoutOverlapping()
            ->runInBackground();

        // Enterprise daily report summary rebuild at 2 AM
        $schedule->command('reports:rebuild-daily-summary')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Check for delayed orders every 5 minutes
        $schedule->job(new CheckDelayedOrdersJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onQueue('voice');

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
