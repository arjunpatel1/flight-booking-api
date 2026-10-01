<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasHealthService;

class ListTenantsCommand extends Command
{
    protected $signature = 'saas:list';

    protected $description = 'List SaaS tenants with status and health metadata.';

    public function handle(SaasHealthService $health): int
    {
        $rows = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->with(['activeSubscription.plan:id,name,code'])
            ->withCount(['branches', 'subscriptions'])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($health) {
                $tenantHealth = $health->tenant($tenant);

                return [
                    $tenant->id,
                    $tenant->name,
                    $tenant->domain,
                    $tenant->settings['lifecycle_status'] ?? ($tenant->is_active ? 'active' : 'inactive'),
                    $tenant->activeSubscription?->plan?->code ?? '-',
                    $tenantHealth['disk_usage_mb'].' MB',
                    $tenant->branches_count,
                    $tenantHealth['failed_jobs'],
                ];
            });

        $this->table(['ID', 'Tenant', 'Domain', 'Status', 'Plan', 'Storage', 'Branches', 'Failed Jobs'], $rows);

        return self::SUCCESS;
    }
}
