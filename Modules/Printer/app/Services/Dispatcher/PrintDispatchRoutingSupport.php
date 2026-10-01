<?php

namespace Modules\Printer\Services\Dispatcher;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Pos\Models\PosRegister;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Throwable;

class PrintDispatchRoutingSupport
{
    public function physicalPrinterKey(Printer $printer): string
    {
        $config = $printer->mapPrinterConfig();
        $type = (string) data_get($config, 'type', $printer->connection_type->value);
        $agentId = trim((string) data_get($config, 'agent_id', ''));

        $target = match ($type) {
            'tcp' => implode(':', [
                trim((string) data_get($config, 'connection.host', '')),
                trim((string) data_get($config, 'connection.port', '9100')),
            ]),
            'spooler' => trim((string) (
                data_get($config, 'connection.name')
                ?: data_get($config, 'connection.host')
                ?: data_get($printer->options ?? [], 'agent_printer_id')
                ?: data_get($printer->options ?? [], 'spooler_name')
            )),
            'usbRaw' => trim((string) (
                data_get($config, 'connection.device_path')
                ?: data_get($config, 'connection.vendor_id').':'.data_get($config, 'connection.product_id')
            )),
            'bluetooth' => trim((string) (
                data_get($config, 'connection.mac_address')
                ?: data_get($config, 'connection.device_path')
            )),
            default => trim((string) ($printer->name ?: $printer->id)),
        };

        $key = strtolower(trim($agentId.'|'.$type.'|'.$target));

        return $key !== '||' && $key !== ''
            ? $key
            : 'printer:'.$printer->id;
    }

    public function clearEmptyPollCache(int $branchId, ?string $agentId): void
    {
        $agentIds = filled($agentId)
            ? collect([$agentId])
            : PrintAgent::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->pluck('agent_id');

        $agentIds->each(fn (string $id) => Cache::forget(AgentPollService::emptyPollCacheKey($branchId, $id)));
    }

    public function resolvePrinterAgentId(Printer $printer, ?string $configuredAgentId): ?string
    {
        if (filled($configuredAgentId)) {
            $configuredAgent = $this->eligibleAgentQuery($printer)
                ->where('agent_id', $configuredAgentId)
                ->first(['agent_id']);

            if ($configuredAgent) {
                return (string) $configuredAgent->agent_id;
            }

            Log::warning('Configured printer agent is inactive, outside the branch, or incompatible with the printer provider.', [
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

        if ($agent) {
            $activeAgentCount = $this->eligibleAgentQuery($printer)->count();

            if ($activeAgentCount > 1) {
                Log::warning('Printer has no explicit agent_id; selected latest active agent to prevent duplicate physical prints.', [
                    'branch_id' => $printer->branch_id,
                    'printer_id' => $printer->id,
                    'selected_agent_id' => $agent->agent_id,
                    'active_agent_count' => $activeAgentCount,
                ]);
            }

            return (string) $agent->agent_id;
        }

        Log::warning('Print job created without agent ownership because printer has no explicit agent_id and branch has no active agents.', [
            'branch_id' => $printer->branch_id,
            'printer_id' => $printer->id,
        ]);

        return null;
    }

    private function eligibleAgentQuery(Printer $printer)
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

    public function broadcastPrintJobCreated(PrintJob $job, Printer $printer, PrintContentType $type): void
    {
        if (! $this->canBroadcastPrintJobs()) {
            Log::warning('Print job event broadcast skipped because broadcasting is disabled.', [
                'job_id' => $job->id,
                'branch_id' => $job->branch_id,
                'printer_id' => $printer->id,
                'type' => $type->value,
            ]);

            return;
        }

        try {
            $agentId = data_get($job->printer_config, 'agent_id');
            if (filled($agentId)) {
                event(new PrintJobCreated($job, $printer->id, $type->value, (string) $agentId));
                Log::info('Print job event broadcasted to assigned agent.', [
                    'job_id' => $job->id,
                    'branch_id' => $job->branch_id,
                    'printer_id' => $printer->id,
                    'type' => $type->value,
                    'agent_id' => (string) $agentId,
                    'printer_type' => data_get($job->printer_config, 'type'),
                    'printer_target' => data_get($job->printer_config, 'connection.host')
                        ?: data_get($job->printer_config, 'connection.name')
                        ?: data_get($job->printer_config, 'connection.device_path'),
                ]);

                return;
            }

            $fallbackAgentId = $this->resolvePrinterAgentId($printer, null);

            if (filled($fallbackAgentId)) {
                event(new PrintJobCreated($job, $printer->id, $type->value, (string) $fallbackAgentId));

                Log::warning('Print job had no explicit agent_id; broadcasted only to latest active agent to prevent duplicate physical prints.', [
                    'job_id' => $job->id,
                    'branch_id' => $job->branch_id,
                    'printer_id' => $printer->id,
                    'type' => $type->value,
                    'agent_id' => $fallbackAgentId,
                ]);

                return;
            }

            Log::warning('Print job event broadcast skipped because printer has no explicit agent_id and branch has no active agents.', [
                'job_id' => $job->id,
                'branch_id' => $job->branch_id,
                'printer_id' => $printer->id,
                'type' => $type->value,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Print job event broadcast failed.', [
                'job_id' => $job->id,
                'branch_id' => $job->branch_id,
                'printer_id' => $printer->id,
                'type' => $type->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function fallbackKitchenPrinter(Order $order): ?Printer
    {
        if (empty($order->pos_register_id)) {
            return null;
        }

        $register = PosRegister::query()
            ->with(['waiterPrinter', 'billPrinter', 'invoicePrinter'])
            ->find($order->pos_register_id);

        return $register?->waiterPrinter
            ?? $register?->billPrinter
            ?? $register?->invoicePrinter;
    }

    public function fallbackSingleActiveBranchPrinter(Order $order, PrintContentType $type): ?Printer
    {
        $printers = Printer::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        if ($printers->count() === 1) {
            $printer = $printers->first();

            Log::warning('Print using single active branch printer fallback; configure printer assignments for production routing.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'branch_id' => $order->branch_id,
                'type' => $type->value,
                'printer_id' => $printer?->id,
            ]);

            return $printer;
        }

        $agentPrinter = $this->fallbackLatestAgentPrinter($order, $type);
        if ($agentPrinter) {
            return $agentPrinter;
        }

        if ($printers->count() > 1) {
            Log::warning('Print fallback skipped: multiple active branch printers and no explicit assignment/register printer.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'branch_id' => $order->branch_id,
                'type' => $type->value,
                'printer_ids' => $printers->pluck('id')->all(),
            ]);
        }

        return null;
    }

    private function fallbackLatestAgentPrinter(Order $order, PrintContentType $type): ?Printer
    {
        $agent = PrintAgent::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $agent) {
            return null;
        }

        $printerQuery = Printer::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->where('options->agent_id', $agent->agent_id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $selectedPrinterKey = $this->selectedAgentPrinterKey($agent);
        if ($selectedPrinterKey !== null) {
            $selectedPrinter = (clone $printerQuery)
                ->where(function ($query) use ($selectedPrinterKey) {
                    $query->where('options->agent_printer_id', $selectedPrinterKey)
                        ->orWhere('options->spooler_name', $selectedPrinterKey)
                        ->orWhere('name->en', $selectedPrinterKey);
                })
                ->first();

            if ($selectedPrinter) {
                Log::warning('Print using selected active agent printer fallback; configure printer assignments for production routing.', [
                    'order_id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'branch_id' => $order->branch_id,
                    'type' => $type->value,
                    'agent_id' => $agent->agent_id,
                    'printer_id' => $selectedPrinter->id,
                    'selected_printer' => $selectedPrinterKey,
                ]);

                return $selectedPrinter;
            }
        }

        $agentPrinters = $printerQuery->limit(2)->get();
        if ($agentPrinters->count() === 1) {
            $printer = $agentPrinters->first();

            Log::warning('Print using only active printer for latest agent fallback; configure printer assignments for production routing.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'branch_id' => $order->branch_id,
                'type' => $type->value,
                'agent_id' => $agent->agent_id,
                'printer_id' => $printer?->id,
            ]);

            return $printer;
        }

        if ($agentPrinters->count() > 1) {
            Log::warning('Print latest-agent fallback skipped: multiple active printers for the latest active agent.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'branch_id' => $order->branch_id,
                'type' => $type->value,
                'agent_id' => $agent->agent_id,
                'printer_ids' => $agentPrinters->pluck('id')->all(),
            ]);
        }

        return null;
    }

    private function selectedAgentPrinterKey(PrintAgent $agent): ?string
    {
        $selected = data_get($agent->health_payload, 'selected_printer', []);
        $key = trim((string) (
            data_get($selected, 'printer_id')
            ?: data_get($selected, 'agent_printer_id')
            ?: data_get($selected, 'spooler_name')
            ?: data_get($selected, 'name')
        ));

        return $key === '' ? null : $key;
    }

    private function canBroadcastPrintJobs(): bool
    {
        return filled(config('broadcasting.connections.reverb.key'))
            && filled(config('broadcasting.connections.reverb.secret'));
    }
}
