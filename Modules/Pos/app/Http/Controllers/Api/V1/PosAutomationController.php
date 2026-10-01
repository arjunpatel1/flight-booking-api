<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Core\Models\AutomationExecution;
use Modules\Pos\Services\Automation\AutomationEffectivenessService;
use Modules\Pos\Services\Automation\AutomationExecutor;
use Modules\Pos\Services\Automation\RestaurantAutomationService;
use Modules\Support\ApiResponse;

class PosAutomationController extends Controller
{
    public function __construct(
        private readonly RestaurantAutomationService $service,
        private readonly AutomationExecutor $executor,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->evaluate(
            $request->integer('branch_id') ?: null
        ));
    }

    /**
     * RAE v2 (P2) — Owner Automation Center. Composes existing calculations only:
     * live evaluation (pending/ignored/business impact/readiness) + execution
     * effectiveness (executed today, time saved, success %, top types).
     */
    public function ownerCenter(
        Request $request,
        AutomationEffectivenessService $effectiveness,
    ): JsonResponse {
        $user = $request->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : ($request->integer('branch_id') ?: null);
        $days = max(min((int) ($request->integer('days') ?: 30), 180), 1);

        $evaluation = $this->service->evaluate($branchId);
        $today = $effectiveness->summary($branchId, 1);
        $window = $effectiveness->summary($branchId, $days);

        $pending = collect($evaluation['decisions'] ?? [])
            ->where('state', '!=', 'inactive')
            ->count();

        return ApiResponse::success([
            'branch_id' => $branchId,
            'window_days' => $days,
            'pending_automations' => $pending,
            'executed_today' => $today['executed'],
            'time_saved_minutes_today' => $today['time_saved_minutes'],
            'time_saved_minutes_window' => $window['time_saved_minutes'],
            'success_rate' => $window['success_rate'],
            'top_automation_types' => $window['top_actions'],
            'ignored_suggestions' => $evaluation['analytics']['ignored_automations'] ?? 0,
            'business_impact' => $evaluation['analytics']['business_impact'] ?? [],
            'effectiveness' => $window,
            'readiness' => $evaluation['readiness_report'] ?? null,
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * RAE v2 — deterministic automation effectiveness (executed/succeeded/failed/
     * rolled-back, time saved, manual actions avoided) from execution history.
     */
    public function effectiveness(Request $request, AutomationEffectivenessService $service): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : ($request->integer('branch_id') ?: null);
        $days = max(min((int) ($request->integer('days') ?: 30), 180), 1);

        return ApiResponse::success($service->summary($branchId, $days));
    }

    /**
     * RAE v2 — searchable automation execution history.
     */
    public function executions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => 'nullable|string|max:24',
            'automation_id' => 'nullable|string|max:64',
            'action_key' => 'nullable|string|max:64',
            'domain' => 'nullable|string|max:32',
            'branch_id' => 'nullable|integer',
            'executor_id' => 'nullable|integer',
            'rolled_back' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $user = $request->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : ($filters['branch_id'] ?? null);

        $paginator = AutomationExecution::query()
            ->when($branchId, fn($q, $v) => $q->where('branch_id', $v))
            ->when($filters['state'] ?? null, fn($q, $v) => $q->where('state', $v))
            ->when($filters['automation_id'] ?? null, fn($q, $v) => $q->where('automation_id', $v))
            ->when($filters['action_key'] ?? null, fn($q, $v) => $q->where('action_key', $v))
            ->when($filters['domain'] ?? null, fn($q, $v) => $q->where('domain', $v))
            ->when($filters['executor_id'] ?? null, fn($q, $v) => $q->where('executor_id', $v))
            ->when(
                array_key_exists('rolled_back', $filters) && $filters['rolled_back'] !== null,
                fn($q) => $filters['rolled_back']
                    ? $q->whereNotNull('rolled_back_at')
                    : $q->whereNull('rolled_back_at'),
            )
            ->when($filters['from'] ?? null, fn($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->where('created_at', '<=', $v))
            ->latest('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->through(fn(AutomationExecution $execution) => $execution->toPayload());

        return ApiResponse::pagination($paginator);
    }

    /**
     * RAE v2 — safely execute a reversible automation (idempotent, auditable).
     */
    public function execute(Request $request, string $automation): JsonResponse
    {
        $result = $this->executor->execute(
            automationId: $automation,
            branchId: $request->integer('branch_id') ?: null,
            idempotencyKey: $request->header('Idempotency-Key') ?: $request->input('idempotency_key'),
            deviceId: $request->header('X-NexDine-Device-Id'),
        );

        return response()->json(['data' => $result['body']], $result['code']);
    }

    /**
     * Explicitly roll back a reversible automation execution.
     */
    public function rollback(int $execution): JsonResponse
    {
        $result = $this->executor->rollback($execution);

        return response()->json(['data' => $result['body']], $result['code']);
    }
}
