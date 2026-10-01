<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Models\AgentCommand;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Throwable;

trait HandlesAgentPrinterInventory
{
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required'],
            'status' => ['nullable', 'string', 'max:30'],
            'service_status' => ['nullable', 'string', 'max:50'],
            'version' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:100'],
            'machine_name' => ['nullable', 'string', 'max:120'],
            'queue' => ['nullable', 'array'],
            'last_print' => ['nullable', 'array'],
            'selected_printer' => ['nullable', 'array'],
            'printers' => ['nullable', 'array', 'max:100'],
            'printers.*.printer_id' => ['required_with:printers', 'string', 'max:255'],
            'printers.*.name' => ['required_with:printers', 'string', 'max:255'],
            'printers.*.type' => ['nullable', 'string', 'max:50'],
            'printers.*.status' => ['nullable', 'string', 'max:50'],
            'printers.*.is_escpos_compatible' => ['nullable', 'boolean'],
            'printers.*.capabilities' => ['nullable', 'array'],
        ]);

        $agent = $request->agent;
        $configurationChanged = (string) $validated['branch_id'] !== (string) $agent->branch_id;

        $printers = collect($validated['printers'] ?? [])
            ->filter(fn(array $printer) => filled($printer['printer_id'] ?? null) && filled($printer['name'] ?? null))
            ->values();

        $registrationErrors = collect();
        $registered = $printers
            ->map(function (array $printer) use ($agent, $registrationErrors) {
                try {
                    return $this->syncDetectedPrinter($agent, $printer);
                } catch (Throwable $exception) {
                    Log::warning('Print agent printer registration failed.', [
                        'agent_id' => $agent->agent_id,
                        'branch_id' => $agent->branch_id,
                        'printer_id' => (string) ($printer['printer_id'] ?? ''),
                        'printer_name' => (string) ($printer['name'] ?? ''),
                        'error' => $exception->getMessage(),
                    ]);

                    $registrationErrors->push([
                        'printer_id' => (string) ($printer['printer_id'] ?? ''),
                        'message' => 'Printer registration failed.',
                    ]);

                    return null;
                }
            })
            ->filter()
            ->values();
        $this->markMissingDetectedPrintersOffline($agent, $printers->pluck('printer_id')->map(fn($id) => (string) $id)->all());

        $lastPrint = $validated['last_print'] ?? [];
        $agent->forceFill([
            'last_seen_at' => now(),
            'status' => $validated['status'] ?? 'online',
            'version' => $validated['version'] ?? null,
            'platform' => $validated['platform'] ?? null,
            'machine_name' => $validated['machine_name'] ?? null,
            'queue_status' => $validated['queue'] ?? [],
            'printer_inventory' => $printers->all(),
            'health_payload' => [
                'service_status' => $validated['service_status'] ?? null,
                'selected_printer' => $validated['selected_printer'] ?? null,
                'registered_printer_ids' => $registered->pluck('id')->values()->all(),
                'printer_registration_error_ids' => $registrationErrors->pluck('printer_id')->filter()->values()->all(),
            ],
            'last_error' => data_get($lastPrint, 'last_error'),
            'last_print_success_at' => filled(data_get($lastPrint, 'success_at')) ? data_get($lastPrint, 'success_at') : null,
            'last_print_failed_at' => filled(data_get($lastPrint, 'failed_at')) ? data_get($lastPrint, 'failed_at') : null,
        ])->save();

        Log::info('Print agent heartbeat received.', [
            'agent_id' => $agent->agent_id,
            'branch_id' => $agent->branch_id,
            'status' => $agent->status,
            'printers_count' => $printers->count(),
            'registered_count' => $registered->count(),
            'registration_error_count' => $registrationErrors->count(),
            'queue' => $validated['queue'] ?? [],
            ...$this->agentRequestContext($request),
        ]);

        $pendingCommands = AgentCommand::query()
            ->where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->orderBy('issued_at')
            ->get(['id', 'command', 'payload']);

        return response()->json([
            'success' => true,
            'agent_id' => $agent->agent_id,
            'branch_id' => (string) $agent->branch_id,
            'configuration_changed' => $configurationChanged,
            'registered_printers' => $registered,
            'registration_errors' => $registrationErrors,
            'last_seen_at' => optional($agent->last_seen_at)->toISOString(),
            'commands' => $pendingCommands,
        ]);
    }

    private function assignedPrintersForAgent(PrintAgent $agent): array
    {
        return $this->assignedPrinterQuery($agent)
            ->get()
            ->map(fn(Printer $printer) => [
                'id' => $printer->id,
                'name' => $printer->name,
                'connection_type' => $printer->connection_type->value,
                'host' => data_get($printer->options, 'host'),
                'port' => data_get($printer->options, 'port'),
                'spooler_name' => data_get($printer->options, 'spooler_name'),
                'paper_size' => data_get($printer->options, 'paper_size'),
                'copies' => (int) data_get($printer->options, 'copies', 1),
            ])
            ->values()
            ->all();
    }

    private function firstAssignedPrinterForAgent(PrintAgent $agent): ?Printer
    {
        return $this->assignedPrinterQuery($agent)->first();
    }

    private function syncDetectedPrinter(PrintAgent $agent, array $detected): ?array
    {
        $printerId = (string) ($detected['printer_id'] ?? '');
        $name = trim((string) ($detected['name'] ?? ''));
        if ($printerId === '' || $name === '') {
            return null;
        }

        $connectionType = $this->connectionTypeFromDetected($detected);
        $options = $this->optionsFromDetectedPrinter($agent, $detected, $connectionType);
        $printer = $this->findDetectedPrinter($agent, $printerId, $options, $connectionType);

        $printer ??= new Printer();
        $printer->forceFill([
            'branch_id' => $agent->branch_id,
            'created_by' => null,
            'name' => ['en' => $name],
            'connection_type' => $connectionType->value,
            'provider_type' => PrinterProviderType::WindowsAgent->value,
            'options' => array_replace($printer->options ?? [], $options),
            'is_active' => true,
        ])->save();

        return [
            'id' => $printer->id,
            'name' => $printer->name,
            'connection_type' => $printer->connection_type->value,
            'status' => $detected['status'] ?? 'ready',
        ];
    }

    private function findDetectedPrinter(PrintAgent $agent, string $printerId, array $options, PrinterConnectionType $connectionType): ?Printer
    {
        $query = Printer::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $agent->branch_id)
            ->where('provider_type', PrinterProviderType::WindowsAgent->value)
            ->where('connection_type', $connectionType->value);

        $printer = (clone $query)
            ->where('options->agent_id', $agent->agent_id)
            ->where('options->agent_printer_id', $printerId)
            ->first();

        if ($printer) {
            return $printer;
        }

        if ($connectionType === PrinterConnectionType::Tcp) {
            return (clone $query)
                ->where('options->host', $options['host'] ?? null)
                ->where('options->port', $options['port'] ?? 9100)
                ->where(function ($query) use ($agent) {
                    $query->whereNull('options->agent_id')
                        ->orWhere('options->agent_id', '')
                        ->orWhere('options->agent_id', $agent->agent_id);
                })
                ->first();
        }

        $target = $options['spooler_name'] ?? $options['device_path'] ?? null;
        if (blank($target)) {
            return null;
        }

        return (clone $query)
            ->where(function ($query) use ($target) {
                $query->where('options->spooler_name', $target)
                    ->orWhere('options->device_path', $target);
            })
            ->where(function ($query) use ($agent) {
                $query->whereNull('options->agent_id')
                    ->orWhere('options->agent_id', '')
                    ->orWhere('options->agent_id', $agent->agent_id);
            })
            ->first();
    }

    private function markMissingDetectedPrintersOffline(PrintAgent $agent, array $seenPrinterIds): void
    {
        Printer::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $agent->branch_id)
            ->where('provider_type', PrinterProviderType::WindowsAgent->value)
            ->where('options->agent_id', $agent->agent_id)
            ->where('options->auto_registered_by_agent', true)
            ->get()
            ->each(function (Printer $printer) use ($seenPrinterIds) {
                $options = $printer->options ?? [];
                $agentPrinterId = (string) data_get($options, 'agent_printer_id', '');
                if ($agentPrinterId === '' || in_array($agentPrinterId, $seenPrinterIds, true)) {
                    return;
                }

                data_set($options, 'detected_status', 'offline');
                data_set($options, 'last_missing_at', now()->toISOString());

                $printer->forceFill([
                    'options' => $options,
                    'is_active' => false,
                ])->save();
            });
    }

    private function connectionTypeFromDetected(array $detected): PrinterConnectionType
    {
        $type = strtolower((string) ($detected['type'] ?? ''));
        $printerId = (string) ($detected['printer_id'] ?? '');

        if ($type === 'network' || str_contains($printerId, ':')) {
            return PrinterConnectionType::Tcp;
        }

        if ($type === 'bluetooth') {
            return PrinterConnectionType::Bluetooth;
        }

        return PrinterConnectionType::Spooler;
    }

    private function optionsFromDetectedPrinter(PrintAgent $agent, array $detected, PrinterConnectionType $connectionType): array
    {
        $printerId = (string) ($detected['printer_id'] ?? '');
        $name = (string) ($detected['name'] ?? $printerId);
        $capabilities = $detected['capabilities'] ?? [];
        $paperWidth = (int) data_get($capabilities, 'max_paper_width', 80);
        $paperSize = $paperWidth <= 58 ? '58mm' : '80mm';
        $defaultMargins = (array) config('printer.spooler.default_margins', []);

        $base = [
            'agent_id' => $agent->agent_id,
            'agent_printer_id' => $printerId,
            'auto_registered_by_agent' => true,
            'last_seen_at' => now()->toISOString(),
            'detected_status' => (string) ($detected['status'] ?? 'ready'),
            'paper_size' => $paperSize,
            'copies' => 1,
            'timeout_ms' => $connectionType === PrinterConnectionType::Tcp ? 5000 : 10000,
            'supports_escpos' => (bool) ($detected['is_escpos_compatible'] ?? data_get($capabilities, 'supports_escpos', true)),
        ];

        if ($connectionType === PrinterConnectionType::Tcp) {
            [$host, $port] = array_pad(explode(':', $printerId, 2), 2, '9100');

            return [
                ...$base,
                'host' => $host,
                'port' => (int) ($port ?: 9100),
                'cut_paper' => true,
                'beep' => false,
                'open_cash_drawer' => false,
            ];
        }

        if ($connectionType === PrinterConnectionType::Bluetooth) {
            return [
                ...$base,
                'device_path' => $name,
                'channel' => 1,
                'cut_paper' => true,
                'beep' => false,
            ];
        }

        return [
            ...$base,
            'spooler_name' => $name,
            'raw' => true,
            'fallback_text_mode' => false,
            'margins' => [
                'top' => (int) ($defaultMargins['top'] ?? 0),
                'right' => (int) ($defaultMargins['right'] ?? 0),
                'bottom' => (int) ($defaultMargins['bottom'] ?? 8),
                'left' => (int) ($defaultMargins['left'] ?? 0),
            ],
        ];
    }

    private function assignedPrinterQuery(PrintAgent $agent)
    {
        return Printer::query()
            ->withoutGlobalScopes()
            ->where('branch_id', $agent->branch_id)
            ->where('is_active', true)
            ->where('options->agent_id', $agent->agent_id)
            ->orderBy('name');
    }
}
