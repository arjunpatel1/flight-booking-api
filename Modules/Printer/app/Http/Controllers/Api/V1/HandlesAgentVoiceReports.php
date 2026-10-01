<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Events\PlatformEvent;
use Modules\Voice\Models\VoiceAlert;
use Modules\Voice\Models\VoiceDiagnosticRun;
use Modules\Voice\Models\VoiceExportBundle;
use Modules\Voice\Models\VoiceHealthSnapshot;
use Modules\Voice\Models\VoiceHistory;
use Modules\Voice\Models\VoiceTestResult;

trait HandlesAgentVoiceReports
{
    private function pollVoiceAnnouncements(Request $request): \Illuminate\Support\Collection
    {
        $agent = $request->agent;
        $branchId = (int) $request->input('branch_id', $agent->branch_id);

        throw_if($branchId !== $agent->branch_id, new Exception('Branch mismatch'));

        $lookbackMinutes = max(1, (int) config('voice.agent_poll_lookback_minutes', 5));
        $batchSize = max(1, min((int) config('voice.agent_poll_batch_size', 5), 10));
        $now = now();
        $emptyCacheKey = $this->voicePollEmptyCacheKey($agent->branch_id, $agent->agent_id);

        if (Cache::get($emptyCacheKey)) {
            return collect();
        }

        $announcements = VoiceHistory::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $agent->branch_id)
            ->where('success', true)
            ->where('created_at', '>=', $now->copy()->subMinutes($lookbackMinutes))
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->filter(function (VoiceHistory $history) use ($agent) {
                return Cache::add(
                    $this->voicePollCacheKey($agent->branch_id, $agent->agent_id, (int) $history->id),
                    true,
                    now()->addHours(2)
                );
            })
            ->take($batchSize)
            ->map(fn(VoiceHistory $history) => [
                'id' => (string) $history->id,
                'announcement_id' => (string) $history->id,
                'branch_id' => (string) $history->branch_id,
                'order_id' => $history->order_id ? (string) $history->order_id : null,
                'announcement_text' => $history->announcement_text,
                'event_type' => $history->event_type,
                'delivery_source' => 'polling',
                'created_at' => optional($history->created_at)->toISOString(),
                'event_name' => PlatformEvent::VOICE_ANNOUNCEMENT_CREATED,
                'voice' => [
                    'gender' => $history->voice_gender,
                    'volume' => $history->volume,
                    'device_id' => $history->device_id,
                    'device_name' => $history->device_name,
                ],
            ])
            ->values();

        if ($announcements->isEmpty()) {
            Cache::put($emptyCacheKey, true, now()->addSecond());
        } else {
            Cache::forget($emptyCacheKey);
        }

        return $announcements;
    }

    private function voicePollCacheKey(int $branchId, string $agentId, int $announcementId): string
    {
        return "voice-agent-polled:{$branchId}:{$agentId}:{$announcementId}";
    }

    private function voicePollEmptyCacheKey(int $branchId, string $agentId): string
    {
        return "voice-agent-empty-poll:{$branchId}:{$agentId}";
    }


    public function reportVoiceHealth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'overall'           => ['required', 'string', 'in:' . implode(',', VoiceHealthSnapshot::STATES)],
            'checked_at'        => ['required', 'date'],
            'subsystems'        => ['required', 'array'],
            'subsystems.*.name'  => ['required', 'string', 'max:60'],
            'subsystems.*.state' => ['required', 'string', 'in:' . implode(',', VoiceHealthSnapshot::STATES)],
            'subsystems.*.message' => ['nullable', 'string', 'max:255'],
        ]);

        $agent      = $request->agent;
        $subsystems = collect($validated['subsystems']);
        $find       = fn(string $name) => $subsystems->firstWhere('name', $name)['state'] ?? 'offline';

        VoiceHealthSnapshot::create([
            'branch_id'               => $agent->branch_id,
            'agent_id'                => $agent->id,
            'overall'                 => $validated['overall'],
            'voice_service_state'     => $find('Voice Service'),
            'tts_engine_state'        => $find('TTS Engine'),
            'audio_device_state'      => $find('Audio Device'),
            'queue_state'             => $find('Voice Queue'),
            'websocket_state'         => $find('WebSocket'),
            'backend_state'           => $find('Backend'),
            'last_announcement_state' => $find('Last Announcement'),
            'subsystems'              => $validated['subsystems'],
            'checked_at'              => $validated['checked_at'],
        ]);

        // Keep only the last 7 days of snapshots per agent
        VoiceHealthSnapshot::query()
            ->where('agent_id', $agent->id)
            ->where('checked_at', '<', now()->subDays(7))
            ->delete();

        return response()->json(['accepted' => true]);
    }

    public function reportVoiceDiagnostics(Request $request): JsonResponse
    {
        $validStatuses = implode(',', VoiceDiagnosticRun::STATUSES);

        $validated = $request->validate([
            'ran_at'           => ['required', 'date'],
            'all_passed'       => ['required', 'boolean'],
            'duration_ms'      => ['required', 'integer', 'min:0'],
            'results'          => ['required', 'array', 'min:1'],
            'results.*.test'   => ['required', 'string', 'max:80'],
            'results.*.status' => ['required', 'string', "in:{$validStatuses}"],
            'results.*.detail' => ['nullable', 'string', 'max:500'],
            'results.*.duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $agent   = $request->agent;
        $results = collect($validated['results']);
        $find    = fn(string $name) => $results->firstWhere('test', $name)['status'] ?? 'warn';

        VoiceDiagnosticRun::create([
            'branch_id'              => $agent->branch_id,
            'agent_id'               => $agent->id,
            'all_passed'             => $validated['all_passed'],
            'duration_ms'            => $validated['duration_ms'],
            'backend_connectivity'   => $find('Backend Connectivity'),
            'websocket_connectivity' => $find('WebSocket Connectivity'),
            'audio_device_test'      => $find('Audio Device Test'),
            'tts_engine_test'        => $find('TTS Engine Test'),
            'voice_queue_health'     => $find('Voice Queue Health'),
            'end_to_end_test'        => $find('End-to-End Test'),
            'results'                => $validated['results'],
            'ran_at'                 => $validated['ran_at'],
        ]);

        // Keep only 30 days of diagnostic history per agent
        VoiceDiagnosticRun::query()
            ->where('agent_id', $agent->id)
            ->where('ran_at', '<', now()->subDays(30))
            ->delete();

        return response()->json(['accepted' => true]);
    }

    public function reportVoiceTestResult(Request $request): JsonResponse
    {
        $agent = $request->agent;

        $data = $request->validate([
            'test_type'   => ['required', 'string', 'in:' . implode(',', VoiceTestResult::TEST_TYPES)],
            'status'      => ['required', 'string', 'in:' . implode(',', VoiceTestResult::STATUSES)],
            'message'     => ['nullable', 'string', 'max:500'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'device_used' => ['nullable', 'string', 'max:255'],
            'error'       => ['nullable', 'string', 'max:500'],
            'triggered_by'=> ['nullable', 'string', 'max:30'],
        ]);

        VoiceTestResult::create([
            'branch_id'   => $agent->branch_id,
            'agent_id'    => $agent->id,
            'test_type'   => $data['test_type'],
            'status'      => $data['status'],
            'message'     => $data['message'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'device_used' => $data['device_used'] ?? null,
            'error'       => $data['error'] ?? null,
            'triggered_by'=> $data['triggered_by'] ?? 'remote_command',
        ]);

        // Keep 14 days of test results per agent.
        VoiceTestResult::query()
            ->where('agent_id', $agent->id)
            ->where('created_at', '<', now()->subDays(14))
            ->delete();

        return response()->json(['accepted' => true]);
    }

    public function reportVoiceAlert(Request $request): JsonResponse
    {
        $agent = $request->agent;

        $data = $request->validate([
            'type'     => ['required', 'string', 'in:' . implode(',', VoiceAlert::ALERT_TYPES)],
            'severity' => ['required', 'string', 'in:' . implode(',', VoiceAlert::SEVERITIES)],
            'message'  => ['required', 'string', 'max:500'],
            'context'  => ['nullable', 'array'],
            'fired_at' => ['required', 'date'],
        ]);

        // Deduplicate: if an active alert of the same type already exists for this
        // agent fired within the last hour, skip creating a duplicate.
        $recentExists = VoiceAlert::query()
            ->where('agent_id', $agent->id)
            ->where('type', $data['type'])
            ->where('status', 'active')
            ->where('fired_at', '>=', now()->subHour())
            ->exists();

        if (!$recentExists) {
            VoiceAlert::create([
                'branch_id' => $agent->branch_id,
                'agent_id'  => $agent->id,
                'type'      => $data['type'],
                'severity'  => $data['severity'],
                'message'   => $data['message'],
                'context'   => $data['context'] ?? null,
                'status'    => 'active',
                'fired_at'  => $data['fired_at'],
            ]);
        }

        return response()->json(['accepted' => true]);
    }

    public function receiveExportBundle(Request $request): JsonResponse
    {
        $agent = $request->agent;

        $data = $request->validate([
            'bundle' => ['required', 'string'],
        ]);

        // Keep only the latest bundle per agent — prune older ones first.
        VoiceExportBundle::query()
            ->where('agent_id', $agent->id)
            ->delete();

        $bundle = VoiceExportBundle::create([
            'branch_id' => $agent->branch_id,
            'agent_id'  => $agent->id,
            'bundle'    => $data['bundle'],
        ]);

        return response()->json(['accepted' => true, 'bundle_id' => $bundle->id]);
    }
}
