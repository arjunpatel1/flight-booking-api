<?php

namespace Modules\Report\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Report\Services\EnterpriseReportSummary\EnterpriseReportSummaryServiceInterface;

class RebuildEnterpriseDailyReportSummaryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $businessDate,
        public readonly ?int $branchId = null,
    ) {
        $this->onQueue((string) config('report.enterprise_summary.queue', 'reports'));
    }

    public function uniqueId(): string
    {
        return $this->businessDate . ':' . ($this->branchId ?: 'all');
    }

    public function handle(EnterpriseReportSummaryServiceInterface $service): void
    {
        $service->rebuildDaily($this->businessDate, $this->branchId);
    }
}
