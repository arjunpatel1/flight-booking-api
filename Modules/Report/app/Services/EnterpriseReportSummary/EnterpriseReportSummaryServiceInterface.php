<?php

namespace Modules\Report\Services\EnterpriseReportSummary;

use Carbon\CarbonInterface;

interface EnterpriseReportSummaryServiceInterface
{
    /**
     * Rebuild daily owner and waiter summaries from immutable transaction data.
     */
    public function rebuildDaily(CarbonInterface|string $date, ?int $branchId = null): void;
}
