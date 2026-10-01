<?php

namespace Modules\Saas\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\FeatureLimit\FeatureLimitServiceInterface;

class SaasControlPlaneService
{
    public function __construct(private readonly FeatureLimitServiceInterface $limits)
    {
    }

    public function overview(?int $tenantId = null): array
    {
        $tenants = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with(['activeSubscription.plan', 'featureLimits'])
            ->when($tenantId, fn ($query) => $query->whereKey($tenantId))
            ->latest()
            ->limit($tenantId ? 1 : 20)
            ->get();

        return [
            'tenants' => $tenants->map(fn (Tenant $tenant) => [
                'tenant' => $tenant->only(['id', 'name', 'slug', 'domain', 'is_active']),
                'plan' => $tenant->activeSubscription?->plan?->only(['id', 'name', 'code']),
                'features' => $this->featureMatrix($tenant),
                'usage' => $this->usageSummary($tenant),
                'identity' => $this->identitySummary($tenant),
                'recent_activity' => $this->activityFeed($tenant, 5),
            ])->values(),
        ];
    }

    public function featureMatrix(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];
        $tenantFlags = $settings['feature_flags']['tenant'] ?? [];
        $branchFlags = $settings['feature_flags']['branches'] ?? [];

        return collect(config('saas.enterprise_features', []))->map(function (string $feature) use ($tenant, $tenantFlags, $branchFlags) {
            $resolved = $this->limits->resolve($tenant, $feature);
            $override = $tenantFlags[$feature]['enabled'] ?? null;

            return [
                'feature' => $feature,
                'enabled' => $override === null ? $resolved['enabled'] : (bool) $override,
                'source' => $override === null ? 'plan' : 'tenant',
                'limit' => $resolved['limit'],
                'used' => $resolved['used'],
                'remaining' => $resolved['remaining'],
                'branch_overrides' => collect($branchFlags)
                    ->filter(fn (array $flags) => array_key_exists($feature, $flags))
                    ->map(fn (array $flags, string $branchId) => [
                        'branch_id' => (int) $branchId,
                        'enabled' => (bool) ($flags[$feature]['enabled'] ?? false),
                    ])->values(),
            ];
        })->values()->all();
    }

    public function updateFeatureFlag(Tenant $tenant, string $feature, bool $enabled, ?int $branchId = null): array
    {
        abort_unless(in_array($feature, config('saas.enterprise_features', []), true), 422, 'Unknown feature flag.');

        $settings = $tenant->settings ?? [];
        if ($branchId) {
            $branch = Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($branchId);
            $settings['feature_flags']['branches'][$branch->id][$feature] = $this->flagPayload($enabled);
        } else {
            $settings['feature_flags']['tenant'][$feature] = $this->flagPayload($enabled);
        }

        $old = $tenant->settings ?? [];
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit('feature_flag_updated', $tenant, ['old' => $old, 'attributes' => $settings, 'feature' => $feature, 'branch_id' => $branchId]);

        return $this->featureMatrix($tenant->fresh(['featureLimits']));
    }

    public function usageSummary(Tenant $tenant): array
    {
        $branchIds = Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id')->all();

        return [
            'branches' => count($branchIds),
            'users' => $this->countTenantTable('users', $tenant->id),
            'orders' => $this->countBranchTable('orders', $branchIds),
            'invoices' => $this->countBranchTable('invoices', $branchIds),
            'payments' => $this->countBranchTable('payments', $branchIds),
            'tables' => $this->countBranchTable('tables', $branchIds),
            'menu_items' => $this->countBranchTable('menus', $branchIds),
            'customers' => $this->countTenantOrBranchTable('customers', $tenant->id, $branchIds),
            'printer_jobs' => $this->countTenantOrBranchTable('print_jobs', $tenant->id, $branchIds),
            'devices' => $this->countTenantOrBranchTable('pos_terminal_devices', $tenant->id, $branchIds),
            'feature_limits' => $tenant->featureLimits->map(fn ($row) => [
                'feature' => $row->feature,
                'limit' => $row->limit,
                'used' => $row->used,
                'remaining' => $row->limit === null ? null : max(0, $row->limit - $row->used),
                'resets_at' => $row->resets_at,
            ])->values(),
        ];
    }

    public function identitySummary(Tenant $tenant): array
    {
        $providers = collect(config('services.sso.providers', []))
            ->map(fn (array $provider, string $key) => [
                'key' => $key,
                'name' => $provider['name'] ?? ucfirst($key),
                'enabled' => (bool) ($provider['enabled'] ?? false)
                    && filled($provider['client_id'] ?? null)
                    && filled($provider['client_secret'] ?? null),
            ])
            ->values()
            ->all();

        return [
            'tenant_domain' => $tenant->domain,
            'password_login' => true,
            'mfa_available' => Schema::hasColumn('users', 'mfa_enabled'),
            'passkeys_available' => class_exists(\Laravel\Passkeys\Passkey::class),
            'sso_providers' => $providers,
            'sso_ready' => collect($providers)->contains(fn (array $provider) => $provider['enabled']),
        ];
    }

    public function activityFeed(Tenant $tenant, int $limit = 25): array
    {
        return ActivityLog::query()
            ->with('causer:id,name,email')
            ->where(function ($query) use ($tenant) {
                $query->where(fn ($q) => $q->where('subject_type', Tenant::class)->where('subject_id', $tenant->id))
                    ->orWhere('properties->attributes->tenant_id', $tenant->id)
                    ->orWhere('properties->old->tenant_id', $tenant->id)
                    ->orWhere('properties->tenant_id', $tenant->id);
            })
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'log_name' => $log->log_name,
                'description' => $log->description,
                'causer' => $log->causer?->only(['id', 'name', 'email']),
                'ip' => data_get($log->properties, 'info.ip'),
                'user_agent' => data_get($log->properties, 'info.user_agent'),
                'created_at' => $log->created_at,
            ])->values()->all();
    }

    private function flagPayload(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'updated_by' => auth()->id(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function audit(string $event, Tenant $tenant, array $properties = []): void
    {
        activity('saas_control_plane')
            ->event($event)
            ->causedBy(auth()->user())
            ->performedOn($tenant)
            ->withProperties(['tenant_id' => $tenant->id] + $properties)
            ->log($event);
    }

    private function countTenantTable(string $table, int $tenantId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            return 0;
        }

        return (int) DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    private function countBranchTable(string $table, array $branchIds): int
    {
        if (! $branchIds || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
            return 0;
        }

        return (int) DB::table($table)->whereIn('branch_id', $branchIds)->count();
    }

    private function countTenantOrBranchTable(string $table, int $tenantId, array $branchIds): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        if (Schema::hasColumn($table, 'tenant_id')) {
            return $this->countTenantTable($table, $tenantId);
        }

        return $this->countBranchTable($table, $branchIds);
    }
}
