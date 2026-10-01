<?php

namespace Modules\Saas\Services\Provisioning;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Arr;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Workspace\TenantWelcomeNotifier;

class SaasTenantLifecycleService
{
    public function suspend(Tenant $tenant, string $reason = 'manual'): Tenant
    {
        $settings = $tenant->settings ?: [];
        $settings['lifecycle_status'] = 'suspended';
        $settings['suspended_at'] = now()->toIso8601String();
        $settings['suspended_reason'] = $reason;

        $tenant->forceFill(['is_active' => false, 'settings' => $settings])->save();
        $this->revokeTenantSessions($tenant);

        return $tenant->refresh();
    }

    public function activate(Tenant $tenant): Tenant
    {
        $settings = $tenant->settings ?: [];
        $settings['lifecycle_status'] = 'active';
        unset($settings['suspended_at'], $settings['suspended_reason'], $settings['deleted_at'], $settings['delete_reason']);

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $tenant->forceFill(['is_active' => true, 'settings' => $settings])->save();
        $tenant->refresh();

        // Welcome email / WhatsApp. Both channels are off by default and the
        // notifier swallows delivery failures, so activation never depends on
        // a message being sent.
        app(TenantWelcomeNotifier::class)->send($tenant);

        return $tenant;
    }

    public function delete(Tenant $tenant, bool $deleteStorage = false, string $reason = 'manual'): Tenant
    {
        $settings = $tenant->settings ?: [];
        $settings['lifecycle_status'] = 'deleted';
        $settings['deleted_at'] = now()->toIso8601String();
        $settings['delete_reason'] = $reason;

        $tenant->forceFill(['is_active' => false, 'settings' => $settings])->save();
        $this->revokeTenantSessions($tenant);
        $tenant->delete();

        if ($deleteStorage) {
            Storage::disk('local')->deleteDirectory("tenants/{$tenant->id}");
        }

        return $tenant;
    }

    private function revokeTenantSessions(Tenant $tenant): void
    {
        $tenant->users()
            ->withoutGlobalScopes()
            ->select('users.id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $user->tokens()->delete();
                }
            });
    }

    public function backup(Tenant $tenant): string
    {
        $path = "tenants/{$tenant->id}/backups/tenant-{$tenant->id}-".now()->format('Ymd-His').'.json';
        Storage::disk('local')->makeDirectory("tenants/{$tenant->id}/backups");
        Storage::disk('local')->put($path, json_encode([
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'tenant' => $tenant->withoutRelations()->toArray(),
            'branches' => $tenant->branches()->withoutGlobalScopes()->get()->toArray(),
            'subscriptions' => $tenant->subscriptions()->get()->toArray(),
            'storage_path' => storage_path("app/tenants/{$tenant->id}"),
        ], JSON_PRETTY_PRINT));

        return Storage::disk('local')->path($path);
    }

    public function restore(string $path): array
    {
        if (! File::exists($path) && File::exists(base_path($path))) {
            $path = base_path($path);
        }

        if (! File::exists($path)) {
            $storageRelativePath = preg_replace('#^storage/app/(private/)?#', '', $path);
            if ($storageRelativePath && Storage::disk('local')->exists($storageRelativePath)) {
                $path = Storage::disk('local')->path($storageRelativePath);
            }
        }

        abort_unless(File::exists($path), 404, 'Backup file not found.');

        $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        return [
            'status' => 'validated',
            'tenant_slug' => $payload['tenant']['slug'] ?? null,
            'tenant_id' => $payload['tenant']['id'] ?? null,
            'message' => 'Backup parsed successfully. Data restore is intentionally manual-reviewed in this safe command layer.',
        ];
    }

    public function executeRestore(string $path, string $confirmation): array
    {
        abort_unless($confirmation === 'RESTORE', 422, 'Restore confirmation must be RESTORE.');
        abort_unless((bool) config('saas.restore.execution_enabled'), 403, 'Restore execution is disabled. Enable SAAS_RESTORE_EXECUTION_ENABLED on a staging copy only.');
        abort_unless(
            in_array(app()->environment(), config('saas.restore.allowed_environments', []), true),
            403,
            'Restore execution is allowed only in approved staging/local environments.'
        );

        $validated = $this->restore($path);
        $payload = json_decode(File::get($this->resolvePath($path)), true, flags: JSON_THROW_ON_ERROR);
        $tenantData = $payload['tenant'] ?? [];
        abort_unless(isset($tenantData['slug']), 422, 'Backup tenant payload is invalid.');

        $tenant = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->withTrashed()
            ->where('slug', $tenantData['slug'])
            ->first();

        $safetyBackup = $tenant ? $this->backup($tenant) : null;

        $tenant = Tenant::query()->withoutGlobalScopes()->withTrashed()->updateOrCreate(
            ['slug' => $tenantData['slug']],
            collect($tenantData)
                ->only(['name', 'legal_name', 'domain', 'contact_name', 'contact_email', 'contact_phone', 'settings', 'is_active'])
                ->all()
        );

        if ($tenant->trashed()) {
            $tenant->restore();
        }

        $restoredBranches = 0;
        foreach ($payload['branches'] ?? [] as $branch) {
            $branchQuery = Branch::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id);
            $existingBranch = filled($branch['registration_number'] ?? null)
                ? (clone $branchQuery)->where('registration_number', $branch['registration_number'])->first()
                : (clone $branchQuery)
                    ->where(function ($query) use ($branch) {
                        $query->where('legal_name', $branch['legal_name'] ?? null)
                            ->when($branch['email'] ?? null, fn ($query, string $email) => $query->orWhere('email', $email));
                    })
                    ->first();

            $branchPayload = [
                ...Arr::only($branch, [
                    'name',
                    'legal_name',
                    'address_line1',
                    'address_line2',
                    'city',
                    'state',
                    'phone',
                    'email',
                    'country_code',
                    'cash_difference_threshold',
                    'timezone',
                    'currency',
                    'latitude',
                    'longitude',
                    'is_main',
                    'registration_number',
                    'vat_tin',
                    'postal_code',
                    'order_types',
                    'payment_methods',
                    'quick_pay_amounts',
                    'hide_waiter_selection',
                    'appearance_settings',
                    'is_active',
                ]),
                'tenant_id' => $tenant->id,
            ];

            $existingBranch
                ? tap($existingBranch)->forceFill($branchPayload)->save()
                : Branch::query()->withoutGlobalScopes()->create($branchPayload);

            if ($existingBranch?->trashed()) {
                $existingBranch->restore();
            }

            $restoredBranches++;
        }

        foreach ($payload['subscriptions'] ?? [] as $subscription) {
            $tenant->subscriptions()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'subscription_plan_id' => $subscription['subscription_plan_id'] ?? null,
                ],
                collect($subscription)
                    ->only(['status', 'starts_at', 'ends_at', 'trial_ends_at', 'cancelled_at', 'overrides'])
                    ->all()
            );
        }

        return [
            ...$validated,
            'status' => 'restored',
            'safety_backup' => $safetyBackup,
            'branches_restored' => $restoredBranches,
            'message' => 'Tenant, branch, and subscription metadata restored with tenant-owned identifiers.',
        ];
    }

    private function resolvePath(string $path): string
    {
        if (File::exists($path)) {
            return $path;
        }

        if (File::exists(base_path($path))) {
            return base_path($path);
        }

        $storageRelativePath = preg_replace('#^storage/app/(private/)?#', '', $path);
        if ($storageRelativePath && Storage::disk('local')->exists($storageRelativePath)) {
            return Storage::disk('local')->path($storageRelativePath);
        }

        abort(404, 'Backup file not found.');
    }
}
