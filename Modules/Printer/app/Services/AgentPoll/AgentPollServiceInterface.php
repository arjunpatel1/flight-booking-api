<?php

namespace Modules\Printer\Services\AgentPoll;

use Illuminate\Support\Collection;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Throwable;

interface AgentPollServiceInterface
{
    /**
     * Poll
     *
     * @param PrintAgent $agent
     * @param int $branchId
     * @return Collection
     * @throws Throwable
     */
    public function poll(PrintAgent $agent, int $branchId): Collection;

    /**
     * Fetch and lease one event-delivered print job.
     *
     * @param PrintAgent $agent
     * @param string $jobId
     * @param int $branchId
     * @return array
     * @throws Throwable
     */
    public function fetchJob(PrintAgent $agent, string $jobId, int $branchId): array;

    /**
     * @param PrintAgent $agent
     * @param string $jobId
     * @param PrintJobStatus $status
     * @param string|null $error
     * @return void
     * @throws Throwable
     */
    public function report(PrintAgent $agent, string $jobId, PrintJobStatus $status, ?string $error = null): void;
}
