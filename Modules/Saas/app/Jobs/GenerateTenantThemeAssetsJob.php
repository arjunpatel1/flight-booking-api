<?php

namespace Modules\Saas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Services\Provisioning\Concerns\RunsTenantProvisioningStep;

class GenerateTenantThemeAssetsJob implements ShouldQueue
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
        $this->runStep($this->runId, 'theme_ready', function ($run, $tenant): void {
            $theme = $tenant->settings['theme'] ?? [];

            Storage::disk('local')->put("tenants/{$tenant->id}/theme.json", json_encode([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'primary' => $theme['primary'] ?? '#ff6b00',
                'secondary' => $theme['secondary'] ?? '#0f172a',
                'updated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
        });
    }
}
