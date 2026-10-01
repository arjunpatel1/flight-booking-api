<?php

namespace Modules\Voice\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Voice\Models\VoiceAlert;
use Modules\Voice\Models\VoiceDiagnosticRun;
use Modules\Voice\Models\VoiceHealthSnapshot;
use Modules\Voice\Models\VoiceHistory;
use Modules\Voice\Models\VoiceTestResult;

class VoiceAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days  = $request->integer('days', 7);
        $since = now()->subDays($days);

        // ── Announcement stats ────────────────────────────────────────────
        $historyStats = VoiceHistory::query()
            ->withoutGlobalBranchPermission()
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*)                                       AS total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END)  AS successful,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END)  AS failed,
                AVG(processing_duration_ms)                    AS avg_processing_ms
            ')
            ->first();

        $total      = (int)($historyStats->total        ?? 0);
        $successful = (int)($historyStats->successful   ?? 0);
        $failed     = (int)($historyStats->failed       ?? 0);
        $successRate = $total > 0 ? round(($successful / $total) * 100, 1) : null;

        // ── Daily trend ───────────────────────────────────────────────────
        $dailyTrend = VoiceHistory::query()
            ->withoutGlobalBranchPermission()
            ->where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) AS date, COUNT(*) AS total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successful")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // ── Alert summary ─────────────────────────────────────────────────
        $alertSummary = VoiceAlert::query()
            ->withoutGlobalBranchPermission()
            ->where('fired_at', '>=', $since)
            ->selectRaw('type, status, COUNT(*) AS cnt')
            ->groupBy('type', 'status')
            ->get()
            ->groupBy('type')
            ->map(fn($rows) => $rows->pluck('cnt', 'status'));

        $activeAlerts = VoiceAlert::query()
            ->withoutGlobalBranchPermission()
            ->active()
            ->count();

        // ── Latest health snapshots (per-agent, most recent) ──────────────
        $latestHealth = VoiceHealthSnapshot::query()
            ->withoutGlobalBranchPermission()
            ->orderByDesc('checked_at')
            ->limit(10)
            ->get(['agent_id', 'overall', 'checked_at']);

        // ── Last diagnostic run ───────────────────────────────────────────
        $lastDiag = VoiceDiagnosticRun::query()
            ->withoutGlobalBranchPermission()
            ->orderByDesc('ran_at')
            ->first(['all_passed', 'ran_at', 'duration_ms']);

        // ── Test results breakdown ────────────────────────────────────────
        $testBreakdown = VoiceTestResult::query()
            ->withoutGlobalBranchPermission()
            ->where('created_at', '>=', $since)
            ->selectRaw('test_type, status, COUNT(*) AS cnt')
            ->groupBy('test_type', 'status')
            ->get()
            ->groupBy('test_type')
            ->map(fn($rows) => $rows->pluck('cnt', 'status'));

        return response()->json([
            'period_days'    => $days,
            'announcements'  => [
                'total'           => $total,
                'successful'      => $successful,
                'failed'          => $failed,
                'success_rate'    => $successRate,
                'avg_processing_ms' => $historyStats->avg_processing_ms
                    ? round((float)$historyStats->avg_processing_ms, 0)
                    : null,
            ],
            'daily_trend'    => $dailyTrend,
            'alerts'         => [
                'active_count'   => $activeAlerts,
                'by_type_status' => $alertSummary,
            ],
            'health_snapshots' => $latestHealth,
            'last_diagnostics' => $lastDiag,
            'test_results'     => $testBreakdown,
        ]);
    }
}
