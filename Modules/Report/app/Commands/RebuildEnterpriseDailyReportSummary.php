<?php

namespace Modules\Report\Commands;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Modules\Report\Jobs\RebuildEnterpriseDailyReportSummaryJob;
use Modules\Report\Services\EnterpriseReportSummary\EnterpriseReportSummaryServiceInterface;

class RebuildEnterpriseDailyReportSummary extends Command
{
    protected $signature = 'reports:rebuild-daily-summary
                            {date? : Business date in Y-m-d format. Defaults to today.}
                            {--from= : Start date for a backfill range.}
                            {--to= : End date for a backfill range.}
                            {--branch= : Optional branch id.}
                            {--queue : Dispatch rebuild jobs instead of running immediately.}';

    protected $description = 'Rebuild enterprise owner and waiter daily report summaries.';

    public function handle(EnterpriseReportSummaryServiceInterface $service): int
    {
        $branchId = $this->option('branch') !== null
            ? (int) $this->option('branch')
            : null;

        $dates = $this->dates();
        $queued = (bool) $this->option('queue');

        foreach ($dates as $date) {
            if ($queued) {
                RebuildEnterpriseDailyReportSummaryJob::dispatch($date, $branchId);
                $this->line("Queued daily summary rebuild for {$date}" . ($branchId ? " / branch {$branchId}" : ''));
                continue;
            }

            $service->rebuildDaily($date, $branchId);
            $this->line("Rebuilt daily summary for {$date}" . ($branchId ? " / branch {$branchId}" : ''));
        }

        $this->info(($queued ? 'Queued' : 'Rebuilt') . " {$dates->count()} daily summary date(s).");

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
