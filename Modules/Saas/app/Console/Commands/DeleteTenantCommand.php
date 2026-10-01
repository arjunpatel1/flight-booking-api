<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class DeleteTenantCommand extends Command
{
    protected $signature = 'saas:delete-tenant {tenant : Tenant id or slug} {--delete-storage} {--reason=manual}';

    protected $description = 'Soft delete and archive a SaaS tenant.';

    public function handle(SaasTenantLifecycleService $service): int
    {
        $tenant = $this->tenant();
        $backup = $service->backup($tenant);
        $service->delete($tenant, (bool) $this->option('delete-storage'), $this->option('reason'));

        $this->info("Tenant {$tenant->slug} deleted. Backup: {$backup}");

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
