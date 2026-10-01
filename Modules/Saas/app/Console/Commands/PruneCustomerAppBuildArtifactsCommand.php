<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\CustomerAppBuildArtifact;

class PruneCustomerAppBuildArtifactsCommand extends Command
{
    protected $signature = 'saas:prune-customer-app-build-artifacts {--limit=100} {--dry-run}';

    protected $description = 'Revoke and remove expired private customer application artifacts.';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $artifacts = CustomerAppBuildArtifact::query()->withoutGlobalScopes()
            ->whereNull('revoked_at')
            ->whereNotNull('retention_expires_at')
            ->where('retention_expires_at', '<=', now())
            ->oldest('retention_expires_at')->limit($limit)->get();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$artifacts->count()} expired artifacts would be revoked.");

            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($artifacts as $artifact) {
            DB::transaction(function () use ($artifact, &$removed): void {
                $locked = CustomerAppBuildArtifact::query()->withoutGlobalScopes()->lockForUpdate()->find($artifact->id);
                if (! $locked || $locked->revoked_at) {
                    return;
                }
                $disk = Storage::disk($locked->storage_disk);
                if ($disk->exists($locked->storage_reference) && ! $disk->delete($locked->storage_reference)) {
                    throw new \RuntimeException('Expired private artifact could not be removed.');
                }
                $locked->forceFill(['revoked_at' => now()])->save();
                CustomerAppBuild::query()->withoutGlobalScopes()
                    ->whereKey($locked->customer_app_build_id)->where('status', 'ready')->update([
                        'status' => 'expired', 'active_fingerprint' => null, 'updated_at' => now(),
                    ]);
                $removed++;
            });
        }

        $this->info("Revoked {$removed} expired customer application artifacts.");

        return self::SUCCESS;
    }
}
