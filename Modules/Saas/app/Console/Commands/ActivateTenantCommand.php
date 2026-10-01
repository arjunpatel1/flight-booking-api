<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class ActivateTenantCommand extends Command
{
    protected $signature = 'saas:activate-tenant {tenant : Tenant id or slug}';

    protected $description = 'Activate or restore a suspended SaaS tenant.';

    public function handle(SaasTenantLifecycleService $service): int
    {
        $tenant = $service->activate($this->tenant());
        $this->info("Tenant {$tenant->slug} activated.");

        return self::SUCCESS;
    }

    private function tenant(): Tenant
    {
        $value = $this->argument('tenant');

        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->when(is_numeric($value), fn ($query) => $query->whereKey((int) $value), fn ($query) => $query->where('slug', $value))
            ->firstOrFail();
    }
}
