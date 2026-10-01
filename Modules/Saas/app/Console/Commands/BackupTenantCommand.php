<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class BackupTenantCommand extends Command
{
    protected $signature = 'saas:backup {--tenant=* : Tenant id or slug. Omit for all tenants.}';

    protected $description = 'Create safe JSON metadata backups for one, many, or all tenants.';

    public function handle(SaasTenantLifecycleService $service): int
    {
        foreach ($this->tenants() as $tenant) {
            $this->line($service->backup($tenant));
        }

        return self::SUCCESS;
    }

    private function tenants()
    {
        $values = array_filter((array) $this->option('tenant'));
        $query = Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->withTrashed();

        if ($values === []) {
            return $query->get();
        }

        return $query->where(function ($query) use ($values) {
            foreach ($values as $value) {
                $query->orWhere(is_numeric($value) ? 'id' : 'slug', $value);
            }
        })->get();
    }
}
