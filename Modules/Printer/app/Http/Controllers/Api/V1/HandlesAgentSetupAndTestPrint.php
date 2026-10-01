<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use Modules\Printer\Services\Reverb\ReverbConfigService;
use Throwable;

trait HandlesAgentSetupAndTestPrint
{
    public function setup(Request $request, string $agentId): JsonResponse
    {
        $agent = PrintAgent::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('agent_id', $agentId)
            ->first();

        if (!$agent || !$agent->is_active) {
            return response()->json(['error' => 'Print agent not found or inactive.'], 404);
        }

        $apiV1Url = rtrim($request->getSchemeAndHttpHost(), '/') . '/api/v1';
        $reverb = $this->reverbSetup($request);

        return response()->json([
            'server_url' => $apiV1Url,
            'agent_id' => $agent->agent_id,
            'branch_id' => (string) $agent->branch_id,
            'mode' => $this->effectiveTransport(),
            'reverb_app_key' => $reverb['app_key'],
            'reverb_socket_url' => $reverb['socket_url'],
            'websocket_channel' => "private-agent.{$agent->agent_id}",
            'websocket_event' => 'print.job.created',
            'assigned_printers' => $this->assignedPrintersForAgent($agent),
            'urls' => [
                'poll' => "{$apiV1Url}/agents/{$agent->agent_id}/poll",
                'fetch_job' => "{$apiV1Url}/agents/{$agent->agent_id}/jobs/{job_id}",
                'report' => "{$apiV1Url}/agents/{$agent->agent_id}/report",
                'heartbeat' => "{$apiV1Url}/agents/{$agent->agent_id}/heartbeat",
                'broadcast_auth' => "{$apiV1Url}/agents/{$agent->agent_id}/broadcasting/auth",
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        Log::info('Print agent verify succeeded.', [
            'agent_id' => $request->agent->agent_id,
            'branch_id' => $request->agent->branch_id,
            ...$this->agentRequestContext($request),
        ]);

        return response()->json([
            'success' => true,
            'agent_id' => $request->agent->agent_id,
            'branch_id' => (string) $request->agent->branch_id,
            'is_active' => (bool) $request->agent->is_active,
            'last_seen_at' => optional($request->agent->last_seen_at)->toISOString(),
            'transport' => $this->effectiveTransport(),
            'reverb' => $this->reverbSetup($request),
            'assigned_printers' => $this->assignedPrintersForAgent($request->agent),
        ]);
    }

    public function testPrint(Request $request): JsonResponse
    {
        $agent = $request->agent;
        $printerConfig = $this->printerConfigFromRequest($request, $agent);

        $job = PrintJob::query()->create([
            'branch_id' => $agent->branch_id,
            'deduplication_key' => 'agent-server-test:' . $agent->agent_id . ':' . now()->timestamp . ':' . bin2hex(random_bytes(6)),
            'printer_config' => $printerConfig,
            'rendered_bytes' => base64_encode($this->testPrintPayload($agent, $printerConfig)),
            'status' => PrintJobStatus::Pending,
        ]);

        Cache::forget(AgentPollService::emptyPollCacheKey($agent->branch_id, $agent->agent_id));

        Log::info('Agent server test print job created.', [
            'agent_id' => $agent->agent_id,
            'branch_id' => $agent->branch_id,
            'job_id' => $job->id,
            'printer_type' => data_get($printerConfig, 'type'),
            'printer_name' => data_get($printerConfig, 'connection.name') ?: data_get($printerConfig, 'connection.host'),
            'transport' => $this->effectiveTransport(),
            ...$this->agentRequestContext($request),
        ]);

        if ($this->effectiveTransport() === 'reverb') {
            try {
                event(new PrintJobCreated($job, 'agent-test', 'test', $agent->agent_id));
                Log::info('Agent server test print event broadcasted.', [
                    'agent_id' => $agent->agent_id,
                    'job_id' => $job->id,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Agent server test print event broadcast failed.', [
                    'agent_id' => $agent->agent_id,
                    'job_id' => $job->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'status' => $job->status->value,
            'transport' => $this->effectiveTransport(),
        ]);
    }

    private function effectiveTransport(): string
    {
        if (
            config('printer.agent.transport', 'polling') === 'reverb'
            || (
                filled(config('broadcasting.connections.reverb.key'))
                && filled(config('broadcasting.connections.reverb.secret'))
            )
        ) {
            return 'reverb';
        }

        return 'polling';
    }

    private function reverbSetup(Request $request): array
    {
        return (new ReverbConfigService())->toArray($request);
    }

    private function printerConfigFromRequest(Request $request, PrintAgent $agent): array
    {
        $printerType = (string) $request->input('printer_type', 'spooler');
        $printerId = (string) $request->input('printer_id', '');
        $printerName = (string) $request->input('printer_name', '');

        if ($printerId === '' && $printerName === '') {
            $assignedPrinter = $this->firstAssignedPrinterForAgent($agent);
            if ($assignedPrinter) {
                $config = $assignedPrinter->mapPrinterConfig();
                data_set($config, 'agent_id', $agent->agent_id);

                return $config;
            }
        }

        if ($printerType === 'Network' || $printerType === 'tcp' || str_contains($printerId, ':')) {
            [$host, $port] = array_pad(explode(':', $printerId, 2), 2, '9100');

            return [
                'type' => 'tcp',
                'agent_id' => $agent->agent_id,
                'connection' => [
                    'host' => $host,
                    'port' => (int) ($port ?: 9100),
                ],
                'settings' => [
                    'copies' => 1,
                    'timeout_ms' => 5000,
                    'media' => '80mm',
                    'cut_paper' => true,
                ],
            ];
        }

        return [
            'type' => strtolower($printerType) === 'bluetooth' ? 'bluetooth' : 'spooler',
            'agent_id' => $agent->agent_id,
            'connection' => [
                'name' => $printerName ?: $printerId,
                'device_path' => $printerName ?: $printerId,
            ],
            'settings' => [
                'copies' => 1,
                'timeout_ms' => 10000,
                'media' => '80mm',
                'raw' => true,
            ],
        ];
    }

    private function testPrintPayload(PrintAgent $agent, array $printerConfig): string
    {
        $line = str_repeat('-', 32);

        return "\x1B\x40"
            . "\x1B\x61\x01"
            . "NEXDINE SERVER TEST\n"
            . "\x1B\x61\x00"
            . "{$line}\n"
            . "Agent  : {$agent->agent_id}\n"
            . "Branch : {$agent->branch_id}\n"
            . "Type   : " . data_get($printerConfig, 'type') . "\n"
            . "Target : " . (data_get($printerConfig, 'connection.name') ?: data_get($printerConfig, 'connection.host')) . "\n"
            . "Time   : " . now()->format('Y-m-d H:i:s') . "\n"
            . "{$line}\n"
            . "Server job pickup is working.\n\n\n"
            . "\x1D\x56\x00";
    }
}
