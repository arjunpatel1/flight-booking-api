<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Modules\Saas\Jobs\RunSaasBillingLifecycleJob;
use Modules\Saas\Services\Billing\SaasLicenseLifecycleService;

class RunBillingLifecycleCommand extends Command
{
    protected $signature = 'saas:billing-lifecycle {--sync : Run immediately instead of dispatching a queued lifecycle job.}';

    protected $description = 'Run SaaS trial, renewal, dunning, grace, and suspension automation.';

    public function handle(SaasLicenseLifecycleService $licenses): int
    {
        if (! $this->option('sync')) {
            RunSaasBillingLifecycleJob::dispatch();
            $this->info('SaaS billing lifecycle job dispatched.');

            return self::SUCCESS;
        }

        $result = $licenses->runLifecycle();

        $this->info('SaaS billing lifecycle completed.');
        $this->line('Subscriptions processed: '.count($result['processed'] ?? []));
        $this->line('Dunning invoices processed: '.count($result['dunning'] ?? []));

        return self::SUCCESS;
    }
}
