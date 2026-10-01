<?php

namespace Modules\Pos\Services\Automation;

use Modules\Core\Automation\AutomationExecutionState;
use Modules\Core\Models\AutomationExecution;

/**
 * RAE v2 — deterministic automation effectiveness from the execution history.
 *
 * Complements the live suggestion-side analytics in RestaurantAutomationService
 * (suggested/ignored). This measures the EXECUTED side — succeeded/failed/rolled
 * back, manual actions avoided, time saved — straight from automation_executions.
 * No ML; simple deterministic aggregation.
 */
class AutomationEffectivenessService
{
    /** Deterministic minutes saved per successful automation (matches existing heuristic). */
    private const TIME_SAVED_PER_EXECUTION_MINUTES = 2;

    /**
     * @return array<string,mixed>
     */
    public function summary(?int $branchId, int $days = 30): array
    {
        $since = now()->subDays(max($days, 1) - 1)->startOfDay();

        $base = AutomationExecution::query()
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->where('created_at', '>=', $since);

        $executed = (clone $base)->count();
        $succeeded = (clone $base)->where('state', AutomationExecutionState::Completed->value)->count();
        $failed = (clone $base)->where('state', AutomationExecutionState::Failed->value)->count();
        $rolledBack = (clone $base)->where('state', AutomationExecutionState::RolledBack->value)->count();
        $avgDurationMs = (int) round((float) ((clone $base)->whereNotNull('duration_ms')->avg('duration_ms') ?? 0));

        $topActions = (clone $base)
            ->selectRaw('action_key, COUNT(*) as total')
            ->groupBy('action_key')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['action_key' => $row->action_key, 'total' => (int) $row->total])
            ->all();

        return [
            'window_days' => $days,
            'branch_id' => $branchId,
            'executed' => $executed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'rolled_back' => $rolledBack,
            'success_rate' => $executed > 0 ? round($succeeded / $executed, 4) : null,
            'manual_actions_avoided' => $succeeded,
            'time_saved_minutes' => $succeeded * self::TIME_SAVED_PER_EXECUTION_MINUTES,
            'avg_duration_ms' => $avgDurationMs,
            'top_actions' => $topActions,
            'generated_at' => now()->toISOString(),
        ];
    }
}
