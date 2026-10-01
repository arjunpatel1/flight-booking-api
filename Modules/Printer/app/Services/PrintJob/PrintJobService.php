<?php

namespace Modules\Printer\Services\PrintJob;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;
use Modules\Support\GlobalStructureFilters;

class PrintJobService implements PrintJobServiceInterface
{
    /** {@inheritDoc} */
    public function label(): string
    {
        return __('printer::print_jobs.print_job');
    }

    /** {@inheritDoc} */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return PrintJob::query()
            ->with(['branch:id,name'])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** {@inheritDoc} */
    public function retry(string $id): PrintJob
    {
        $job = PrintJob::query()->findOrFail($id);

        if ($job->status === PrintJobStatus::Success) {
            throw ValidationException::withMessages([
                'id' => __('printer::print_jobs.success_jobs_cannot_be_retried'),
            ]);
        }

        $config = PrintJobTrace::appendToConfig(
            (array) $job->printer_config,
            'retry_queued',
            'Job was manually retried and returned to queue.',
            [
                'retried_by_user_id' => auth()->id(),
                'retried_at' => now()->toISOString(),
            ],
        );

        $job->update([
            'status' => PrintJobStatus::Pending,
            'claimed_by' => null,
            'lease_until' => null,
            'error_message' => null,
            'completed_at' => null,
            'printer_config' => $config,
        ]);

        $agentId = data_get($job->printer_config, 'agent_id');
        $this->clearEmptyPollCache((int) $job->branch_id, filled($agentId) ? (string) $agentId : null);
        if ($this->canBroadcastPrintJobs() && filled($agentId)) {
            event(new PrintJobCreated($job, null, 'retry', (string) $agentId));
        }

        return $job;
    }

    /** {@inheritDoc} */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                'key' => 'status',
                'label' => __('printer::print_jobs.filters.status'),
                'type' => 'select',
                'options' => PrintJobStatus::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** {@inheritDoc} */
    public function summary(array $filters = []): array
    {
        $baseQuery = PrintJob::query()->filters($filters);
        $now = now();

        return [
            'total' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', PrintJobStatus::Pending)->count(),
            'ready_to_print' => (clone $baseQuery)
                ->where('status', PrintJobStatus::Pending)
                ->where(function ($query) use ($now) {
                    $query->whereNull('claimed_by')
                        ->orWhereNull('lease_until')
                        ->orWhere('lease_until', '<', $now);
                })
                ->count(),
            'awaiting_agent_report' => (clone $baseQuery)
                ->where('status', PrintJobStatus::Pending)
                ->whereNotNull('claimed_by')
                ->where('lease_until', '>=', $now)
                ->count(),
            'success' => (clone $baseQuery)->where('status', PrintJobStatus::Success)->count(),
            'failed' => (clone $baseQuery)->where('status', PrintJobStatus::Failed)->count(),
        ];
    }

    /** {@inheritDoc} */
    public function diagnostics(array $filters = []): array
    {
        $offlineAfterMinutes = (int) config('printer.diagnostics.agent_offline_after_minutes', 5);
        $agentOnlineCutoff = now()->subMinutes(max(1, $offlineAfterMinutes));

        $printerQuery = Printer::query()
            ->withoutGlobalActive()
            ->filters($filters);

        $agentQuery = PrintAgent::query()
            ->withoutGlobalActive()
            ->filters($filters);

        $activePrinters = (clone $printerQuery)->where('is_active', true)->get(['id', 'options']);
        $activeAgents = (clone $agentQuery)->where('is_active', true);

        $cashDrawerReady = $activePrinters
            ->filter(fn (Printer $printer) => (bool) data_get($printer->options, 'open_cash_drawer', false))
            ->count();

        return [
            'printers' => [
                'total' => (clone $printerQuery)->count(),
                'active' => $activePrinters->count(),
                'cash_drawer_ready' => $cashDrawerReady,
            ],
            'agents' => [
                'total' => (clone $agentQuery)->count(),
                'active' => (clone $activeAgents)->count(),
                'online' => (clone $activeAgents)->where('last_seen_at', '>=', $agentOnlineCutoff)->count(),
                'offline' => (clone $activeAgents)->where(function ($query) use ($agentOnlineCutoff) {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $agentOnlineCutoff);
                })->count(),
                'offline_after_minutes' => $offlineAfterMinutes,
            ],
            'jobs' => [
                'failed_last_24h' => PrintJob::query()
                    ->filters($filters)
                    ->where('status', PrintJobStatus::Failed)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
            'capabilities' => [
                'cash_drawer' => $cashDrawerReady > 0,
                'barcode_scanner' => in_array('barcode_scanner', config('printer.diagnostics.hardware_capabilities', []), true),
                'weighing_scale' => in_array('weighing_scale', config('printer.diagnostics.hardware_capabilities', []), true),
            ],
        ];
    }

    private function clearEmptyPollCache(int $branchId, ?string $agentId): void
    {
        $agentIds = filled($agentId)
            ? collect([$agentId])
            : PrintAgent::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->pluck('agent_id');

        $agentIds->each(fn (string $id) => Cache::forget(AgentPollService::emptyPollCacheKey($branchId, $id)));
    }

    private function canBroadcastPrintJobs(): bool
    {
        return filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret'));
    }
}
