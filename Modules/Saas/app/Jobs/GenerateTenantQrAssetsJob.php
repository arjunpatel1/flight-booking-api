<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;

class GenerateTenantQrAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsTenantProvisioningStep, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $this->runStep($this->runId, 'qr_ready', function ($run, $tenant): void {
            $path = "tenants/{$tenant->id}/qr/placeholder.json";

            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, json_encode([
                    'tenant_id' => $tenant->id,
                    'branch_id' => $run->branch_id,
                    'status' => 'placeholder',
                    'generated_at' => now()->toIso8601String(),
                ], JSON_PRETTY_PRINT));
            }
        });
    }
}
