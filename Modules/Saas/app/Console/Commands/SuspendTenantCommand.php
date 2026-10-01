<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class SuspendTenantCommand extends Command
{
    protected $signature = 'saas:suspend-tenant {tenant : Tenant id or slug} {--reason=manual}';

    protected $description = 'Suspend a tenant without deleting data.';

    public function handle(SaasTenantLifecycleService $service): int
    {
        $tenant = $service->suspend($this->tenant(), $this->option('reason'));
        $this->info("Tenant {$tenant->slug} suspended.");

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
