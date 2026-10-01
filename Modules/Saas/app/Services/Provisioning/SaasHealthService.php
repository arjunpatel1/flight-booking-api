<?php

namespace Modules\Saas\Services\Provisioning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Models\Tenant;

class SaasHealthService
{
    public function tenant(Tenant $tenant): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant' => $tenant->name,
            'status' => $tenant->is_active ? 'active' : ($tenant->settings['lifecycle_status'] ?? 'inactive'),
            'database' => $this->database(),
            'redis' => $this->redis(),
            'storage' => $this->storage($tenant),
            'queue' => config('queue.default'),
            'scheduler' => 'configured',
            'reverb' => [
                'driver' => config('broadcasting.default'),
                'app_id' => config('reverb.apps.apps.0.app_id'),
            ],
            'failed_jobs' => $this->failedJobs(),
            'disk_usage_mb' => $this->diskUsageMb($tenant),
        ];
    }

    public function all(): array
    {
        return Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $tenant) => $this->safeTenant($tenant))
            ->all();
    }

    private function safeTenant(Tenant $tenant): array
    {
        try {
            return $this->tenant($tenant);
        } catch (\Throwable $exception) {
            return [
                'tenant_id' => $tenant->id,
                'tenant' => $tenant->name,
                'status' => $tenant->is_active ? 'active' : ($tenant->settings['lifecycle_status'] ?? 'inactive'),
                'database' => 'failed: '.$exception->getMessage(),
                'redis' => 'unknown',
                'storage' => 'unknown',
                'queue' => config('queue.default'),
                'scheduler' => 'configured',
                'reverb' => [
                    'driver' => config('broadcasting.default'),
                    'app_id' => config('reverb.apps.apps.0.app_id'),
                ],
                'failed_jobs' => $this->failedJobs(),
                'disk_usage_mb' => 0,
            ];
        }
    }

    private function database(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (\Throwable $exception) {
            return 'failed: '.$exception->getMessage();
        }
    }

    private function redis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (\Throwable $exception) {
            return 'not_available: '.$exception->getMessage();
        }
    }

    private function storage(Tenant $tenant): string
    {
        $path = "tenants/{$tenant->id}";

        return Storage::disk('local')->exists($path) ? 'ok' : 'missing';
    }

    private function failedJobs(): int
    {
        return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
    }

    private function diskUsageMb(Tenant $tenant): float
    {
        $base = Storage::disk('local')->path("tenants/{$tenant->id}");
        if (! is_dir($base)) {
            return 0;
        }

        $bytes = 0;
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->getSize();
            $files++;

            if ($files >= 5000) {
                break;
            }
        }

        return round($bytes / 1024 / 1024, 2);
    }
}
