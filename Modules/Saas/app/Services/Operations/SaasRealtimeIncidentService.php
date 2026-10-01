<?php

namespace Modules\Saas\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SaasRealtimeIncidentService
{
    public function unresolvedByTenant(int $limit = 12): Collection
    {
        if (! $this->tablesAreAvailable()) {
            return collect();
        }

        return DB::table('agent_incidents as incidents')
            ->join('print_agents as agents', 'agents.id', '=', 'incidents.agent_id')
            ->join('branches', 'branches.id', '=', 'agents.branch_id')
            ->join('tenants', 'tenants.id', '=', 'branches.tenant_id')
            ->whereNull('incidents.resolved_at')
            ->whereNull('tenants.deleted_at')
            ->select([
                'tenants.id as tenant_id',
                'tenants.name as tenant',
                'incidents.category',
                DB::raw('COUNT(*) as incident_count'),
                DB::raw('MIN(incidents.support_score) as lowest_support_score'),
                DB::raw('MAX(incidents.detected_at) as detected_at'),
            ])
            ->groupBy('tenants.id', 'tenants.name', 'incidents.category')
            ->orderByRaw('MIN(incidents.support_score) asc')
            ->orderByRaw('MAX(incidents.detected_at) desc')
            ->limit($limit)
            ->get()
            ->map(function (object $incident): array {
                $criticalCategories = ['backend_unreachable', 'database_error', 'queue_stuck'];
                $severity = in_array($incident->category, $criticalCategories, true)
                    || (int) $incident->lowest_support_score < 40
                    ? 'critical'
                    : 'warning';

                return [
                    'tenant_id' => (int) $incident->tenant_id,
                    'tenant' => $incident->tenant,
                    'category' => $incident->category,
                    'incident_count' => (int) $incident->incident_count,
                    'lowest_support_score' => (int) $incident->lowest_support_score,
                    'detected_at' => $incident->detected_at,
                    'severity' => $severity,
                ];
            });
    }

    private function tablesAreAvailable(): bool
    {
        return collect(['agent_incidents', 'print_agents', 'branches', 'tenants'])
            ->every(fn (string $table) => Schema::hasTable($table));
    }
}
