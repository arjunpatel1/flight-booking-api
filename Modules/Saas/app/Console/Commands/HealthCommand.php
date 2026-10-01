<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\SaasHealthService;

class HealthCommand extends Command
{
    protected $signature = 'saas:health {--tenant= : Tenant id or slug}';

    protected $description = 'Show SaaS platform and tenant health.';

    public function handle(SaasHealthService $service): int
    {
        $tenant = $this->tenant();
        $payload = $tenant ? $service->tenant($tenant) : $service->all();

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function tenant(): ?Tenant
    {
        $value = $this->option('tenant');
        if (blank($value)) {
            return null;
        }

        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->when(is_numeric($value), fn ($query) => $query->whereKey((int) $value), fn ($query) => $query->where('slug', $value))
            ->firstOrFail();
    }
}
