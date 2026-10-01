<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Services\Provisioning\SaasTenantLifecycleService;

class RestoreTenantCommand extends Command
{
    protected $signature = 'saas:restore {backup : Backup JSON path}';

    protected $description = 'Validate a SaaS tenant backup for safe restore review.';

    public function handle(SaasTenantLifecycleService $service): int
    {
        $this->line(json_encode($service->restore($this->argument('backup')), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
