<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Printer\Models\AgentCommand;
use Modules\Printer\Models\AgentIncident;
use Modules\Printer\Models\AgentLog;
use Modules\Printer\Models\AgentTelemetry;

trait HandlesAgentDiagnostics
{
    public function telemetry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cpu_percent'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ram_used_mb'            => ['nullable', 'numeric', 'min:0'],
            'ram_total_mb'           => ['nullable', 'numeric', 'min:0'],
            'disk_used_gb'           => ['nullable', 'numeric', 'min:0'],
            'disk_total_gb'          => ['nullable', 'numeric', 'min:0'],
            'print_queue_depth'      => ['nullable', 'integer', 'min:0'],
            'voice_queue_depth'      => ['nullable', 'integer', 'min:0'],
            'print_count_today'      => ['nullable', 'integer', 'min:0'],
            'print_failures_today'   => ['nullable', 'integer', 'min:0'],
            'network_latency_ms'     => ['nullable', 'integer', 'min:0'],
            'heartbeat_success_rate' => ['nullable', 'integer', 'min:0', 'max:100'],
            'ws_state'               => ['nullable', 'string', 'max:20'],
            'uptime_seconds'         => ['nullable', 'integer', 'min:0'],
        ]);

        $agent = $request->agent;

        AgentTelemetry::query()->create(array_merge($validated, [
            'agent_id'   => $agent->id,
            'agent_uuid' => $agent->agent_id,
        ]));

        // Prune rows older than 24 hours for this agent to cap table growth.
        AgentTelemetry::query()
            ->where('agent_id', $agent->id)
            ->where('recorded_at', '<', now()->subDay())
            ->delete();

        return response()->json(['success' => true]);
    }

    public function commands(Request $request): JsonResponse
    {
        $agent = $request->agent;
        $commands = AgentCommand::query()
            ->where('agent_id', $agent->id)
            ->where('status', 'pending')
            ->orderBy('issued_at')
            ->get(['id', 'command', 'payload']);

        return response()->json(['commands' => $commands]);
    }

    /**
     * Receive a batch of structured log entries from the agent.
     * Entries older than the retention window are pruned after insert.
     * Rate limited to 20 requests/minute per agent (enforced in routes).
     */
    public function uploadLogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries'                      => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.level'              => ['required', 'string', 'in:trace,debug,info,warning,error,fatal'],
            'entries.*.message'            => ['required', 'string', 'max:5000'],
            'entries.*.logged_at'          => ['required', 'date'],
            'entries.*.source_context'     => ['nullable', 'string', 'max:255'],
            'entries.*.exception'          => ['nullable', 'string', 'max:20000'],
            'entries.*.context'            => ['nullable', 'array'],
            'log_file'                     => ['nullable', 'string', 'max:120'],
        ]);

        $agent = $request->agent;
        $now   = now();

        // Build rows for bulk insert — avoids N individual round-trips.
        $rows = array_map(fn(array $e) => [
            'agent_id'       => $agent->id,
            'level'          => $e['level'],
            'message'        => mb_substr($e['message'], 0, 5000),
            'source_context' => isset($e['source_context']) ? mb_substr($e['source_context'], 0, 255) : null,
            'exception'      => isset($e['exception']) ? mb_substr($e['exception'], 0, 20000) : null,
            'context'        => isset($e['context']) ? json_encode($e['context']) : null,
            'logged_at'      => $e['logged_at'],
            'uploaded_at'    => $now,
        ], $validated['entries']);

        // Chunk inserts so we never hit MySQL packet limits.
        foreach (array_chunk($rows, 100) as $chunk) {
            AgentLog::insert($chunk);
        }

        // Rolling retention: delete entries outside the window for this agent only.
        $retentionDays = (int) config('printer.agent_log_retention_days', 7);
        AgentLog::query()
            ->where('agent_id', $agent->id)
            ->where('logged_at', '<', now()->subDays($retentionDays))
            ->delete();

        return response()->json(['accepted' => count($rows)]);
    }

    public function commandAck(Request $request, string $agentId, string $commandId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:acknowledged,completed,failed'],
            'result' => ['nullable', 'string', 'max:2000'],
            'error'  => ['nullable', 'string', 'max:2000'],
        ]);

        $agent = $request->agent;
        $command = AgentCommand::query()
            ->where('agent_id', $agent->id)
            ->where('id', $commandId)
            ->firstOrFail();

        $now = now();
        $update = ['status' => $validated['status']];

        if ($validated['status'] === 'acknowledged') {
            $update['acknowledged_at'] = $now;
        } else {
            $update['executed_at'] = $now;
            if (!empty($validated['result'])) {
                $update['result'] = $validated['result'];
            }
            if (!empty($validated['error'])) {
                $update['error'] = $validated['error'];
            }
        }

        $command->forceFill($update)->save();

        return response()->json(['success' => true]);
    }

    public function reportIncident(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'           => ['required', 'string', 'in:' . implode(',', AgentIncident::CATEGORIES)],
            'confidence'         => ['required', 'numeric', 'min:0', 'max:1'],
            'explanation'        => ['required', 'string', 'max:2000'],
            'technical_detail'   => ['nullable', 'string', 'max:2000'],
            'fix_steps'          => ['nullable', 'array'],
            'fix_steps.*'        => ['string', 'max:500'],
            'support_score'      => ['required', 'integer', 'min:0', 'max:100'],
            'auto_fix_attempted' => ['nullable', 'boolean'],
            'auto_fix_result'    => ['nullable', 'string', 'in:success,failed,not_supported'],
            'auto_fix_detail'    => ['nullable', 'string', 'max:1000'],
            'detected_at'        => ['required', 'date'],
        ]);

        $agent = $request->agent;

        $incidentWindow = max(1, (int) config('saas.realtime.incident_window_minutes', 15));
        $recentIncident = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('category', $validated['category'])
            ->whereNull('resolved_at')
            ->where('detected_at', '>=', now()->subMinutes($incidentWindow))
            ->latest('detected_at')
            ->first();

        if ($recentIncident) {
            $recentIncident->forceFill([
                'confidence' => $validated['confidence'],
                'explanation' => $validated['explanation'],
                'technical_detail' => $validated['technical_detail'] ?? null,
                'support_score' => $validated['support_score'],
                'detected_at' => $validated['detected_at'],
            ])->save();

            return response()->json(['accepted' => true, 'deduplicated' => true]);
        }

        AgentIncident::create([
            'agent_id'           => $agent->id,
            'category'           => $validated['category'],
            'confidence'         => $validated['confidence'],
            'explanation'        => $validated['explanation'],
            'technical_detail'   => $validated['technical_detail'] ?? null,
            'fix_steps'          => $validated['fix_steps'] ?? null,
            'support_score'      => $validated['support_score'],
            'auto_fix_attempted' => (bool) ($validated['auto_fix_attempted'] ?? false),
            'auto_fix_result'    => $validated['auto_fix_result'] ?? null,
            'auto_fix_detail'    => $validated['auto_fix_detail'] ?? null,
            'detected_at'        => $validated['detected_at'],
            'created_at'         => now(),
        ]);

        // Prune incidents older than 30 days for this agent
        AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('detected_at', '<', now()->subDays(30))
            ->delete();

        return response()->json(['accepted' => true]);
    }
}
