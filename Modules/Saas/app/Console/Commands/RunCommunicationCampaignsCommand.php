<?php
namespace Modules\Saas\Console\Commands;
use Illuminate\Console\Command;
use Modules\Saas\Services\Communication\SaasCampaignService;
class RunCommunicationCampaignsCommand extends Command {
    protected $signature='saas:run-communication-campaigns {--limit=20}'; protected $description='Dispatch due SaaS restaurant communication campaigns.';
    public function handle(SaasCampaignService $service): int { $this->info($service->runDue((int)$this->option('limit')).' campaign(s) processed.'); return self::SUCCESS; }
}
