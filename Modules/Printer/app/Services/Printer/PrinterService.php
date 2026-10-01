<?php

namespace Modules\Printer\Services\Printer;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Branch\Models\Branch;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Enum\PrinterSpoolerColorMode;
use Modules\Printer\Enum\PrinterSpoolerOrientation;
use Modules\Printer\Enum\PrinterSpoolerSide;
use Modules\Printer\Enum\PrinterUsbRawEndpoint;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Models\Printer;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Support\GlobalStructureFilters;

class PrinterService implements PrinterServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("printer::printers.printer");
    }

    /** @inheritDoc */
    public function show(int $id): Printer
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Printer
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getModel(): Printer
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Printer::class;
    }

    /** @inheritDoc */
    public function store(array $data): Printer
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Printer
    {
        $printer = $this->findOrFail($id);
        $printer->update($data);

        return $printer;
    }

    /** @inheritDoc */
    public function testPrint(int $id): array
    {
        $printer = $this->findOrFail($id);
        $config = $printer->mapPrinterConfig();
        $agentId = $this->resolveTestPrintAgentId($printer, data_get($config, 'agent_id'));
        data_set($config, 'agent_id', $agentId);
        $payload = $this->testPrintPayload($printer);

        PrintJob::query()
            ->where('branch_id', $printer->branch_id)
            ->where('status', PrintJobStatus::Pending)
            ->where('deduplication_key', 'like', 'test-print:' . $printer->id . ':%')
            ->update([
                'status' => PrintJobStatus::Failed,
                'error_message' => 'Superseded by a newer printer test.',
                'lease_until' => null,
                'completed_at' => now(),
            ]);

        $job = PrintJob::query()->create([
            'branch_id' => $printer->branch_id,
            'deduplication_key' => 'test-print:' . $printer->id . ':' . now()->timestamp . ':' . bin2hex(random_bytes(6)),
            'printer_config' => $config,
            'rendered_bytes' => base64_encode($payload),
            'status' => PrintJobStatus::Pending,
        ]);

        $this->clearEmptyPollCache($printer->branch_id, $agentId);

        if ($this->canBroadcastPrintJobs() && filled($agentId)) {
            event(new PrintJobCreated($job, $printer->id, 'test', $agentId));
            Log::info('Printer test print event broadcasted to assigned agent.', [
                'job_id' => $job->id,
                'branch_id' => $printer->branch_id,
                'printer_id' => $printer->id,
                'agent_id' => $agentId,
                'printer_type' => data_get($config, 'type'),
                'printer_target' => data_get($config, 'connection.host')
                    ?: data_get($config, 'connection.name')
                    ?: data_get($config, 'connection.device_path'),
            ]);
        } elseif (! $this->canBroadcastPrintJobs()) {
            Log::warning('Printer test print event broadcast skipped because broadcasting is disabled.', [
                'job_id' => $job->id,
                'branch_id' => $printer->branch_id,
                'printer_id' => $printer->id,
                'agent_id' => $agentId,
            ]);
        } else {
            Log::warning('Printer test print event broadcast skipped because printer has no assigned agent.', [
                'job_id' => $job->id,
                'branch_id' => $printer->branch_id,
                'printer_id' => $printer->id,
            ]);
        }

        return [
            'job_id' => $job->id,
            'status' => $job->status->value,
            'agent_id' => $agentId,
            'message' => __('printer::printers.messages.test_print_queued'),
        ];
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'connection_type',
                "label" => __('printer::printers.filters.connection_type'),
                "type" => 'select',
                "options" => PrinterConnectionType::toArrayTrans(),
            ],
            [
                "key" => 'provider_type',
                "label" => __('printer::printers.filters.provider_type'),
                "type" => 'select',
                "options" => PrinterProviderType::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        return [
            "branches" => Branch::list(),
            "connection_types" => PrinterConnectionType::toArrayTrans(),
            "provider_types" => PrinterProviderType::toArrayTrans(),
            "paper_sizes" => PrinterPaperSize::toArrayTrans(),
            "spooler_color_modes" => PrinterSpoolerColorMode::toArrayTrans(),
            "spooler_orientations" => PrinterSpoolerOrientation::toArrayTrans(),
            "spooler_color_sides" => PrinterSpoolerSide::toArrayTrans(),
            "usb_raw_endpoints" => PrinterUsbRawEndpoint::toArrayTrans(),
            "print_agents" => PrintAgent::query()
                ->where('is_active', true)
                ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
                ->orderBy('platform')
                ->orderBy('name')
                ->get(['agent_id', 'name', 'platform', 'branch_id', 'last_seen_at'])
                ->map(fn (PrintAgent $agent) => [
                    'id' => $agent->agent_id,
                    'name' => $agent->name,
                    'platform' => $agent->platform,
                    'branch_id' => $agent->branch_id,
                    'online' => $agent->last_seen_at?->gte(now()->subMinutes(5)) ?? false,
                ])
                ->values(),
        ];
    }

    private function testPrintPayload(Printer $printer): string
    {
        $line = str_repeat('-', 32);
        $connection = $printer->connection_type->value;
        $agentId = (string) data_get($printer->options, 'agent_id', '-');
        $branch = (string) ($printer->branch?->name ?? $printer->branch_id);
        $target = $connection === 'tcp'
            ? data_get($printer->options, 'host') . ':' . data_get($printer->options, 'port', 9100)
            : (data_get($printer->options, 'spooler_name') ?: data_get($printer->options, 'device_path') ?: '-');

        return "\x1B\x40"
            . "\x1B\x61\x01"
            . "NEXDINE TEST PRINT\n"
            . "\x1B\x61\x00"
            . "{$line}\n"
            . "Printer : {$printer->name}\n"
            . "Branch  : {$branch}\n"
            . "Type    : {$connection}\n"
            . "Target  : {$target}\n"
            . "Agent   : {$agentId}\n"
            . "Time    : " . now()->format('Y-m-d H:i:s') . "\n"
            . "{$line}\n"
            . "If this slip printed, silent\n"
            . "printing is working correctly.\n\n\n"
            . "\x1D\x56\x00";
    }

    private function clearEmptyPollCache(int $branchId, ?string $agentId): void
    {
        $agentIds = filled($agentId)
            ? collect([$agentId])
            : PrintAgent::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->pluck('agent_id');

        $agentIds->each(fn(string $id) => Cache::forget(AgentPollService::emptyPollCacheKey($branchId, $id)));
    }

    private function resolveTestPrintAgentId(Printer $printer, ?string $configuredAgentId): ?string
    {
        if (filled($configuredAgentId)) {
            $configuredAgent = $this->eligibleAgentQuery($printer)
                ->where('agent_id', $configuredAgentId)
                ->first(['agent_id']);

            if ($configuredAgent) {
                return (string) $configuredAgent->agent_id;
            }

            Log::warning('Configured test-print agent is incompatible with the printer provider.', [
                'branch_id' => $printer->branch_id,
                'printer_id' => $printer->id,
                'configured_agent_id' => $configuredAgentId,
                'provider_type' => $printer->provider_type->value,
            ]);
        }

        $agent = $this->eligibleAgentQuery($printer)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->first(['agent_id']);

        return $agent ? (string) $agent->agent_id : null;
    }

    private function eligibleAgentQuery(Printer $printer): Builder
    {
        $platform = match ($printer->provider_type) {
            PrinterProviderType::AndroidApp => 'android',
            PrinterProviderType::WindowsAgent => 'windows',
            PrinterProviderType::UbuntuAgent => 'linux',
        };

        return PrintAgent::query()
            ->where('branch_id', $printer->branch_id)
            ->where('is_active', true)
            ->where('platform', $platform);
    }

    private function canBroadcastPrintJobs(): bool
    {
        return filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret'));
    }


    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name"])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }
}
