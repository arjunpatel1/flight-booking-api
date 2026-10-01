<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Http\Requests\Api\V1\SavePrintAgentRequest;
use Modules\Printer\Models\AgentCommand;
use Modules\Printer\Models\AgentIncident;
use Modules\Voice\Models\VoiceDiagnosticRun;
use Modules\Voice\Models\VoiceExportBundle;
use Modules\Voice\Models\VoiceHealthSnapshot;
use Modules\Voice\Models\VoiceTestResult;
use Modules\Printer\Models\AgentLog;
use Modules\Printer\Models\AgentTelemetry;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\Agent\PrintAgentServiceInterface;
use Modules\Printer\Transformers\Api\V1\PrintAgentResource;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class PrintAgentController extends Controller
{
    /**
     * Create a new instance of PrintAgentController
     *
     * @param PrintAgentServiceInterface $service
     */
    public function __construct(protected PrintAgentServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of print agent models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: PrintAgentResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single print agent model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PrintAgentResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the print agent model.
     *
     * @param SavePrintAgentRequest $request
     * @return JsonResponse
     */
    public function store(SavePrintAgentRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new PrintAgentResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the print agent model.
     *
     * @param SavePrintAgentRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SavePrintAgentRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PrintAgentResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the print agent model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    /**
     * Get form meta
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->getFormMeta(
                auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }

    /**
     * Download local print agent setup scripts.
     *
     * @param string $platform
     * @return BinaryFileResponse|JsonResponse
     */
    public function downloadScript(string $platform): BinaryFileResponse|JsonResponse
    {
        $scripts = [
            'windows' => [
                'path' => base_path('tools/print-agent/windows/install-nexdine-print-agent.ps1'),
                'name' => 'install-nexdine-print-agent.ps1',
            ],
            'windows-agent' => [
                'path' => base_path('tools/print-agent/windows/NexDinePrintAgent.ps1'),
                'name' => 'NexDinePrintAgent.ps1',
            ],
            'ubuntu' => [
                'path' => base_path('tools/print-agent/ubuntu/install-nexdine-print-agent.sh'),
                'name' => 'install-nexdine-print-agent.sh',
            ],
            'ubuntu-agent' => [
                'path' => base_path('tools/print-agent/ubuntu/nexdine-print-agent.py'),
                'name' => 'nexdine-print-agent.py',
            ],
            'windows-zip' => [
                'path' => base_path('tools/print-agent/windows.zip'),
                'name' => 'nexdine-windows-print-agent.zip',
            ],
        ];

        if (!isset($scripts[$platform]) || !is_file($scripts[$platform]['path'])) {
            return ApiResponse::errors(
                errors: null,
                message: __('admin::messages.resource_not_found', ['resource' => 'Print agent script']),
                code: Response::HTTP_NOT_FOUND
            );
        }

        return response()->download($scripts[$platform]['path'], $scripts[$platform]['name']);
    }

    /**
     * Return paginated log entries for a single agent.
     * Supports filtering by level, free-text search, and date range.
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $query = AgentLog::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('logged_at');

        if ($level = $request->get('level')) {
            $query->where('level', $level);
        }

        if ($search = $request->get('search')) {
            $safe = mb_substr($search, 0, 100);
            $query->where(function ($q) use ($safe) {
                $q->where('message', 'LIKE', "%{$safe}%")
                  ->orWhere('source_context', 'LIKE', "%{$safe}%")
                  ->orWhere('exception', 'LIKE', "%{$safe}%");
            });
        }

        if ($from = $request->get('from')) {
            $query->where('logged_at', '>=', $from);
        }

        if ($to = $request->get('to')) {
            $query->where('logged_at', '<=', $to);
        }

        $perPage = min((int) $request->get('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    public function telemetry(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $rows = AgentTelemetry::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('recorded_at')
            ->limit(60)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function issueCommand(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', 'in:restart_agent,restart_queue,refresh_printers,run_diagnostics,run_voice_diagnostics,test_voice,test_speaker,test_tts,recover_voice_queue,export_voice_logs,download_logs'],
            'payload' => ['nullable', 'array'],
        ]);

        $agent = $this->authorizedAgent($id);

        $command = AgentCommand::query()->create([
            'agent_id'  => $agent->id,
            'command'   => $validated['command'],
            'payload'   => $validated['payload'] ?? null,
            'status'    => 'pending',
            'issued_by' => auth()->id(),
            'issued_at' => now(),
        ]);

        return response()->json(['success' => true, 'command_id' => $command->id]);
    }

    public function agentCommands(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $commands = AgentCommand::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('issued_at')
            ->limit(20)
            ->get();

        return response()->json(['data' => $commands]);
    }

    /**
     * Return support dashboard data for an agent:
     *   - Latest incident + support score
     *   - Last auto recovery
     *   - 7-day recovery success rate
     *   - Recent incidents (last 20)
     */
    public function supportStatus(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $latest = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('category', '!=', 'none')
            ->orderByDesc('detected_at')
            ->first();

        $lastRecovery = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('auto_fix_attempted', true)
            ->where('auto_fix_result', 'success')
            ->orderByDesc('detected_at')
            ->first();

        // Recovery rate = successful auto-fixes / total auto-fix-attempted in last 7 days
        $recentAttempted = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('auto_fix_attempted', true)
            ->where('detected_at', '>=', now()->subDays(7))
            ->count();

        $recentSuccess = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->where('auto_fix_attempted', true)
            ->where('auto_fix_result', 'success')
            ->where('detected_at', '>=', now()->subDays(7))
            ->count();

        $recoveryRate = $recentAttempted > 0
            ? (int) round(($recentSuccess / $recentAttempted) * 100)
            : null;

        $recentIncidents = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();

        $latestScore = AgentIncident::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('detected_at')
            ->value('support_score') ?? 100;

        return response()->json([
            'support_score'         => $latestScore,
            'current_incident'      => $latest,
            'last_auto_recovery'    => $lastRecovery ? [
                'category'     => $lastRecovery->category,
                'result'       => $lastRecovery->auto_fix_result,
                'detail'       => $lastRecovery->auto_fix_detail,
                'recovered_at' => $lastRecovery->detected_at,
            ] : null,
            'recovery_success_rate' => $recoveryRate,
            'recent_incidents'      => $recentIncidents,
        ]);
    }

    /**
     * Return the latest voice health snapshot for an agent.
     */
    public function voiceHealth(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $latest = VoiceHealthSnapshot::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('checked_at')
            ->first();

        return response()->json(['data' => $latest]);
    }

    /**
     * Return the latest voice diagnostic run for an agent.
     */
    public function voiceDiagnostics(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $latest = VoiceDiagnosticRun::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('ran_at')
            ->first();

        return response()->json(['data' => $latest]);
    }

    public function voiceTestResults(int $id): JsonResponse
    {
        $agent = $this->authorizedAgent($id);

        $results = VoiceTestResult::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json(['data' => $results]);
    }

    public function voiceExportBundle(int $id): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $agent = $this->authorizedAgent($id);

        $bundle = VoiceExportBundle::query()
            ->where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$bundle) {
            return response()->json(['message' => 'No export bundle available. Run "Export Voice Logs" command on the agent first.'], 404);
        }

        $filename = "voice-export-agent-{$agent->id}-" . now()->format('Y-m-d-His') . '.json';

        return response()->streamDownload(
            fn() => print($bundle->bundle),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    private function authorizedAgent(int $id): PrintAgent
    {
        $agent = PrintAgent::withoutGlobalScopes()
            ->withOutGlobalBranchPermission()
            ->with('branch:id,tenant_id')
            ->findOrFail($id);

        $user = auth()->user();

        if (! $user) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($user->isSuperAdmin()) {
            return $agent;
        }

        if ($user->assignedToBranch() && (int) $agent->branch_id === (int) $user->branch_id) {
            return $agent;
        }

        if ($user->assignedToTenant() && (int) $agent->branch?->tenant_id === (int) $user->tenant_id) {
            return $agent;
        }

        abort(Response::HTTP_NOT_FOUND);
    }
}
