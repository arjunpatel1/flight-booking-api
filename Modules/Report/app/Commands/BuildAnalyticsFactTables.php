<?php

namespace Modules\Report\Commands;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Modules\Report\Jobs\BuildAnalyticsFactTablesJob;

class BuildAnalyticsFactTables extends Command
{
    protected $signature = 'analytics:build-fact-tables
                            {date? : Business date in Y-m-d format. Defaults to today.}
                            {--from= : Start date for a backfill range.}
                            {--to= : End date for a backfill range.}
                            {--branch= : Optional branch id.}
                            {--force : Force rebuild even if data exists.}
                            {--queue : Dispatch jobs instead of running immediately.}';

    protected $description = 'Build analytics fact tables for advanced reporting (fact_sales_dailies, fact_item_sales_hourly, fact_inventory_daily)';

    public function handle(): int
    {
        $branchId = $this->option('branch') !== null
            ? (int) $this->option('branch')
            : null;

        $dates = $this->dates();
        $queued = (bool) $this->option('queue');
        $force = (bool) $this->option('force');

        $this->info("Building fact tables for {$dates->count()} date(s)..." . ($branchId ? " (branch {$branchId})" : ''));

        foreach ($dates as $date) {
            if ($queued) {
                BuildAnalyticsFactTablesJob::dispatch($date, $branchId, $force)
                    ->onQueue('analytics');
                $this->line("Queued fact table build for {$date}" . ($branchId ? " / branch {$branchId}" : ''));
                continue;
            }

            $this->line("Building fact tables for {$date}" . ($branchId ? " / branch {$branchId}" : ''));
            
            $job = new BuildAnalyticsFactTablesJob($date, $branchId, $force);
            $job->handle();
        }

        $this->info(($queued ? 'Queued' : 'Built') . " fact tables for {$dates->count()} date(s).");

        return self::SUCCESS;
    }

    private function dates(): \Illuminate\Support\Collection
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from || $to) {
            $start = Carbon::parse($from ?: $to)->startOfDay();
            $end = Carbon::parse($to ?: $from)->startOfDay();

            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            return collect(CarbonPeriod::create($start, $end))
                ->map(fn(Carbon $date) => $date->toDateString())
                ->values();
        }

        return collect([
            Carbon::parse($this->argument('date') ?: now())->toDateString(),
        ]);
    }
}
