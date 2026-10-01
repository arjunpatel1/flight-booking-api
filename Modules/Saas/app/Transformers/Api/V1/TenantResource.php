<?php

namespace Modules\Saas\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Lifecycle\CustomerLifecycleService;
use Modules\Saas\Services\Workspace\TenantWorkspaceService;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->ownerUser();
        $attributes = $this->resource->getAttributes();
        $walletAvailable = $attributes['delivery_wallet_available_balance'] ?? null;
        $walletReserved = $attributes['delivery_wallet_reserved_balance'] ?? null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'owner_name' => $owner?->name ?: $this->contact_name,
            'owner_email' => $owner?->email ?: $this->contact_email,
            'settings' => $this->settings ?: [],
            'logo_url' => $this->logoUrl(),
            'active_subscription' => $this->whenLoaded('activeSubscription', function () {
                if ($this->activeSubscription === null) {
                    return null;
                }

                return $this->subscriptionPayload();
            }),
            'subscription' => $this->whenLoaded('activeSubscription', fn () => $this->subscriptionPayload()),
            'active_plan_name' => $this->whenLoaded(
                'activeSubscription',
                fn () => $this->activeSubscription?->plan?->name ?? '-'
            ),
            'delivery_wallet' => [
                'available_balance' => (float) ($walletAvailable ?? 0),
                'reserved_balance' => (float) ($walletReserved ?? 0),
                'has_account' => $walletAvailable !== null || $walletReserved !== null,
            ],
            'branches_count' => $this->branches_count ?? $this->whenLoaded('branches', fn () => $this->branches->count()),
            'users_count' => $this->users_count ?? 0,
            'subscriptions_count' => $this->subscriptions_count ?? $this->whenLoaded('subscriptions', fn () => $this->subscriptions->count()),
            'branches' => $this->branchesPayload(),
            'users' => $this->usersPayload(),
            'subscriptions' => $this->subscriptionsPayload(),
            'feature_limits' => $this->featureLimitsPayload(),
            'effective_entitlements' => app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->features($this->resource),
            'devices' => $this->devicesPayload(),
            'printing' => $this->printingPayload(),
            'onboarding' => $this->whenLoaded('latestProvisioningRun', function () {
                $run = $this->latestProvisioningRun;
                $workspaceProgress = app(TenantWorkspaceService::class)->setup($this->resource)['progress'];

                if ($run === null) {
                    return [
                        'uuid' => null,
                        'status' => 'not_started',
                        'status_label' => __('Not started'),
                        'state' => 'PENDING',
                        'progress' => $workspaceProgress,
                        'current_step' => null,
                        'error' => null,
                    ];
                }

                return [
                    'uuid' => $run->uuid,
                    'status' => $run->status,
                    'status_label' => Str::of($run->status)->replace('_', ' ')->title()->toString(),
                    'state' => $run->state(),
                    'progress' => $workspaceProgress,
                    'current_step' => $run->current_step,
                    'error' => $run->error,
                    'started_at' => $run->started_at ? dateTimeFormat($run->started_at) : null,
                    'completed_at' => $run->completed_at ? dateTimeFormat($run->completed_at) : null,
                    'failed_at' => $run->failed_at ? dateTimeFormat($run->failed_at) : null,
                    'updated_at' => $run->updated_at ? dateTimeFormat($run->updated_at) : null,
                ];
            }),
            'usage' => $this->usagePayload(),
            'lifecycle' => $this->lifecyclePayload(),
            'health' => $this->healthPayload(),
            'warnings' => $this->warningsPayload(),
            'status' => $this->statusPayload(),
            'last_activity' => $this->updated_at ? dateTimeFormat($this->updated_at) : null,
            'activity' => $this->activityPayload(),
            'customer_360' => $this->customer360Payload(),
            'is_active' => $this->is_active,
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }

    /**
     * Commercial lifecycle stage.
     *
     * Resolved through CustomerLifecycleService so the registry, the workspace
     * journey and the lifecycle board all report the same stage — including the
     * derivation it applies to tenants that predate lifecycle tracking, which
     * is why this does not simply read the column.
     */
    private function lifecyclePayload(): array
    {
        $stage = app(CustomerLifecycleService::class)->stageForTenant($this->resource);
        $changedAt = $this->getAttributes()['lifecycle_changed_at'] ?? null;

        return [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'order' => $stage->order(),
            'is_pre_tenant' => $stage->isPreTenant(),
            'changed_at' => $changedAt ? dateTimeFormat($changedAt) : null,
            'stages' => array_map(
                fn (CustomerLifecycleStage $case) => ['value' => $case->value, 'label' => $case->label()],
                CustomerLifecycleStage::cases(),
            ),
        ];
    }

    private function branchesPayload(): array
    {
        if (! $this->relationLoaded('branches')) {
            return [];
        }

        return $this->branches
            ->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'currency' => $branch->currency,
                'is_active' => (bool) $branch->is_active,
            ])
            ->values()
            ->all();
    }

    private function logoUrl(): ?string
    {
        return data_get($this->settings, 'branding.logo_url')
            ?? data_get($this->settings, 'brand.logo_url')
            ?? data_get($this->settings, 'logo_url')
            ?? data_get($this->settings, 'logo')
            ?? data_get($this->settings, 'app.logo_url');
    }

    private function usersPayload(): array
    {
        if (! $this->relationLoaded('users')) {
            return [];
        }

        // The SaaS control plane manages restaurant owners/administrators, not
        // every waiter, cashier and kitchen account. Operational staff remain
        // in the restaurant's People module and are not exposed in this
        // platform-level resource.
        return $this->users
            ->filter(function ($user) {
                $roles = $user->roles?->pluck('name') ?? collect();

                return $roles->intersect(['enterprise_admin', 'admin_branch'])->isNotEmpty();
            })
            ->take(10)
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
                'roles' => $user->roles?->pluck('name')->values()->all() ?? [],
                'is_active' => (bool) $user->is_active,
                'last_activity' => $user->updated_at ? dateTimeFormat($user->updated_at) : null,
            ])
            ->values()
            ->all();
    }

    private function ownerUser(): mixed
    {
        if (! $this->relationLoaded('users')) {
            return null;
        }

        return $this->users->first(function ($user) {
            $roles = $user->roles?->pluck('name') ?? collect();

            return $roles->contains('enterprise_admin');
        }) ?? $this->users->first(function ($user) {
            $roles = $user->roles?->pluck('name') ?? collect();

            return $roles->contains('admin_branch');
        });
    }

    private function subscriptionsPayload(): array
    {
        if (! $this->relationLoaded('subscriptions')) {
            return [];
        }

        return $this->subscriptions
            ->map(fn ($subscription) => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'access_active' => in_array($subscription->status, ['trial', 'active'], true),
                'plan' => $subscription->plan ? [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'code' => $subscription->plan->code,
                ] : null,
                'starts_at' => $subscription->starts_at ? dateTimeFormat($subscription->starts_at) : null,
                'ends_at' => $subscription->ends_at ? dateTimeFormat($subscription->ends_at) : null,
                'trial_ends_at' => $subscription->trial_ends_at ? dateTimeFormat($subscription->trial_ends_at) : null,
                'effective_ends_at' => ($subscription->ends_at ?? $subscription->trial_ends_at)
                    ? dateTimeFormat($subscription->ends_at ?? $subscription->trial_ends_at)
                    : null,
            ])
            ->values()
            ->all();
    }

    private function featureLimitsPayload(): array
    {
        if (! $this->relationLoaded('featureLimits')) {
            return [];
        }

        return $this->featureLimits
            ->map(fn ($feature) => [
                'feature' => $feature->feature,
                'limit' => $feature->limit,
                'used' => $feature->used,
            ])
            ->values()
            ->all();
    }

    private function subscriptionPayload(): ?array
    {
        if (! $this->activeSubscription) {
            return null;
        }

        return [
            'id' => $this->activeSubscription->id,
            'status' => $this->activeSubscription->status,
            'access_active' => in_array($this->activeSubscription->status, ['trial', 'active'], true),
            'status_label' => __("saas::tenant_subscriptions.statuses.{$this->activeSubscription->status}"),
            'plan' => $this->activeSubscription->plan ? [
                'id' => $this->activeSubscription->plan->id,
                'name' => $this->activeSubscription->plan->name,
                'code' => $this->activeSubscription->plan->code,
            ] : null,
            'starts_at' => $this->activeSubscription->starts_at ? dateTimeFormat($this->activeSubscription->starts_at) : null,
            'ends_at' => $this->activeSubscription->ends_at ? dateTimeFormat($this->activeSubscription->ends_at) : null,
            'trial_ends_at' => $this->activeSubscription->trial_ends_at ? dateTimeFormat($this->activeSubscription->trial_ends_at) : null,
            'effective_ends_at' => ($this->activeSubscription->ends_at ?? $this->activeSubscription->trial_ends_at)
                ? dateTimeFormat($this->activeSubscription->ends_at ?? $this->activeSubscription->trial_ends_at)
                : null,
            // Carbon 3 returns a float here (6.70833333269676), which reached
            // the UI verbatim. A part-day still counts as a day remaining, so
            // round up and expose a whole number.
            'trial_days_remaining' => $this->activeSubscription->status === 'trial' && $this->activeSubscription->trial_ends_at
                ? (int) ceil(max(0, now()->diffInDays($this->activeSubscription->trial_ends_at, false)))
                : null,
        ];
    }

    private function printingPayload(): array
    {
        if (! $this->relationLoaded('branches') || ! Schema::hasTable('print_agents')) {
            return ['agents' => [], 'printers' => [], 'summary' => ['agents' => 0, 'printers' => 0, 'failed_jobs' => 0, 'pending_jobs' => 0]];
        }

        $branchIds = $this->branches->pluck('id')->filter()->values();
        if ($branchIds->isEmpty()) {
            return ['agents' => [], 'printers' => [], 'summary' => ['agents' => 0, 'printers' => 0, 'failed_jobs' => 0, 'pending_jobs' => 0]];
        }

        $agentColumns = collect(['id', 'branch_id', 'name', 'last_seen_at', 'is_active'])
            ->merge(['status', 'version', 'last_error'])
            ->filter(fn (string $column) => Schema::hasColumn('print_agents', $column))
            ->values()
            ->all();

        $agents = DB::table('print_agents')
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get($agentColumns)
            ->map(fn ($agent) => [
                'id' => $agent->id,
                'branch_id' => $agent->branch_id,
                'name' => is_string($agent->name) ? (data_get(json_decode($agent->name, true), 'en') ?: $agent->name) : 'Print agent',
                'status' => ($agent->status ?? null) ?: ($agent->is_active ? 'unknown' : 'inactive'),
                'version' => $agent->version ?? null,
                'last_seen_at' => $agent->last_seen_at ? dateTimeFormat($agent->last_seen_at) : null,
                'last_error' => $agent->last_error ?? null,
                'is_active' => (bool) $agent->is_active,
            ])
            ->values()
            ->all();

        $printers = Schema::hasTable('printers')
            ? DB::table('printers')
                ->whereIn('branch_id', $branchIds)
                ->orderBy('name')
                ->limit(30)
                ->get(['id', 'branch_id', 'name', 'connection_type', 'provider_type', 'is_active'])
                ->map(fn ($printer) => [
                    'id' => $printer->id,
                    'branch_id' => $printer->branch_id,
                    'name' => is_string($printer->name) ? $printer->name : data_get(json_decode($printer->name, true), 'en', 'Printer'),
                    'connection_type' => $printer->connection_type,
                    'provider_type' => $printer->provider_type,
                    'is_active' => (bool) $printer->is_active,
                ])
                ->values()
                ->all()
            : [];

        $jobCounts = ['failed_jobs' => 0, 'pending_jobs' => 0];
        if (Schema::hasTable('print_jobs')) {
            $jobCounts = [
                'failed_jobs' => (int) DB::table('print_jobs')->whereIn('branch_id', $branchIds)->whereIn('status', ['failed', 'error'])->count(),
                'pending_jobs' => (int) DB::table('print_jobs')->whereIn('branch_id', $branchIds)->whereIn('status', ['pending', 'processing'])->count(),
            ];
        }

        return [
            'agents' => $agents,
            'printers' => $printers,
            'summary' => [
                'agents' => count($agents),
                'printers' => count($printers),
                ...$jobCounts,
            ],
        ];
    }

    private function usagePayload(): array
    {
        $branchIds = $this->relationLoaded('branches')
            ? $this->branches->pluck('id')->filter()->values()
            : collect();
        $devices = 0;

        if (
            $branchIds->isNotEmpty()
            && Schema::hasTable('pos_terminal_devices')
            && Schema::hasColumn('pos_terminal_devices', 'branch_id')
        ) {
            $query = DB::table('pos_terminal_devices')->whereIn('branch_id', $branchIds);
            if (Schema::hasColumn('pos_terminal_devices', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            $devices = (int) $query->count();
        }

        return [
            'branches' => (int) ($this->branches_count ?? 0),
            'users' => (int) ($this->users_count ?? 0),
            'subscriptions' => (int) ($this->subscriptions_count ?? 0),
            'devices' => $devices,
            'storage_mb' => (float) data_get($this->settings, 'usage.storage_mb', 0),
        ];
    }

    private function devicesPayload(): array
    {
        if (
            ! $this->relationLoaded('branches')
            || ! Schema::hasTable('pos_terminal_devices')
            || ! Schema::hasColumn('pos_terminal_devices', 'branch_id')
        ) {
            return [];
        }

        $branches = $this->branches->keyBy('id');
        $branchIds = $branches->keys()->filter()->values();
        if ($branchIds->isEmpty()) {
            return [];
        }

        $columns = collect([
            'id', 'branch_id', 'device_id', 'name', 'status', 'is_disabled',
            'app_version', 'platform', 'last_seen_at', 'last_sync_at',
        ])->filter(fn (string $column) => Schema::hasColumn('pos_terminal_devices', $column))->all();

        $query = DB::table('pos_terminal_devices')->whereIn('branch_id', $branchIds);
        if (Schema::hasColumn('pos_terminal_devices', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get($columns)
            ->map(fn ($device) => [
                'id' => $device->id,
                'device_id' => $device->device_id ?? null,
                'name' => $device->name ?? null,
                'branch_id' => $device->branch_id,
                'branch_name' => $branches->get($device->branch_id)?->name,
                'status' => $device->status ?? 'unknown',
                'is_disabled' => (bool) ($device->is_disabled ?? false),
                'app_version' => $device->app_version ?? null,
                'platform' => $device->platform ?? null,
                'last_seen_at' => filled($device->last_seen_at ?? null) ? dateTimeFormat($device->last_seen_at) : null,
                'last_sync_at' => filled($device->last_sync_at ?? null) ? dateTimeFormat($device->last_sync_at) : null,
            ])
            ->values()
            ->all();
    }

    private function healthPayload(): array
    {
        $warnings = $this->warningsPayload();
        $score = max(0, 100 - (count($warnings) * 20));

        return [
            'status' => count($warnings) === 0 ? 'healthy' : 'warning',
            'score' => $score,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function warningsPayload(): array
    {
        $warnings = [];

        if (! $this->is_active) {
            $warnings[] = ['type' => 'tenant_suspended', 'message' => 'Tenant is suspended.'];
        }

        if (! $this->domain) {
            $warnings[] = ['type' => 'domain_missing', 'message' => 'Tenant domain is not configured.'];
        }

        if ($this->relationLoaded('latestProvisioningRun') && $this->latestProvisioningRun?->status === 'failed') {
            $warnings[] = ['type' => 'provisioning_failed', 'message' => 'Latest provisioning run failed.'];
        }

        return $warnings;
    }

    private function statusPayload(): array
    {
        $value = $this->trashed() ? 'deleted' : ($this->is_active ? 'active' : 'suspended');

        return [
            'value' => $value,
            'label' => Str::of($value)->replace('_', ' ')->title()->toString(),
            'color' => match ($value) {
                'active' => 'success',
                'suspended' => 'warning',
                'deleted' => 'error',
                default => 'secondary',
            },
        ];
    }

    private function activityPayload(): array
    {
        $items = [
            [
                'type' => 'created',
                'title' => 'Tenant created',
                'message' => $this->name.' workspace was created.',
                'created_at' => $this->created_at ? dateTimeFormat($this->created_at) : null,
            ],
        ];

        if ($this->relationLoaded('latestProvisioningRun') && $this->latestProvisioningRun) {
            $run = $this->latestProvisioningRun;
            $items[] = [
                'type' => 'provisioning',
                'title' => 'Provisioning '.$run->status,
                'message' => $run->current_step ?: $run->state(),
                'created_at' => $run->updated_at ? dateTimeFormat($run->updated_at) : null,
            ];
        }

        if ($this->relationLoaded('activeSubscription') && $this->activeSubscription) {
            $items[] = [
                'type' => 'subscription',
                'title' => 'Subscription '.$this->activeSubscription->status,
                'message' => $this->activeSubscription->plan?->name ?? 'Plan not assigned',
                'created_at' => $this->activeSubscription->updated_at ? dateTimeFormat($this->activeSubscription->updated_at) : null,
            ];
        }

        return collect($items)
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    private function customer360Payload(): array
    {
        $branchIds = $this->relationLoaded('branches') ? $this->branches->pluck('id')->filter() : collect();
        $userIds = $this->relationLoaded('users') ? $this->users->pluck('id')->filter() : collect();
        $orders = collect();
        $orderSummary = ['total_orders' => 0, 'sales' => 0, 'orders_30d' => 0, 'sales_30d' => 0, 'average_order' => 0];

        if (Schema::hasTable('orders') && $branchIds->isNotEmpty()) {
            $query = DB::table('orders')->whereIn('branch_id', $branchIds);
            $totals = (clone $query)->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total), 0) as sales, COALESCE(AVG(total), 0) as average_order')->first();
            $recentTotals = (clone $query)->where('created_at', '>=', now()->subDays(30))->selectRaw('COUNT(*) as total_orders, COALESCE(SUM(total), 0) as sales')->first();
            $orderSummary = [
                'total_orders' => (int) ($totals->total_orders ?? 0), 'sales' => (float) ($totals->sales ?? 0),
                'orders_30d' => (int) ($recentTotals->total_orders ?? 0), 'sales_30d' => (float) ($recentTotals->sales ?? 0),
                'average_order' => round((float) ($totals->average_order ?? 0), 2),
            ];
            $orders = (clone $query)->latest()->limit(20)->get(['id', 'branch_id', 'reference_no', 'status', 'payment_status', 'currency', 'total', 'created_at'])
                ->map(fn ($order) => (array) $order)->values();
        }

        $security = collect();
        if (Schema::hasTable('authentication_log') && $userIds->isNotEmpty()) {
            $security = DB::table('authentication_log')->whereIn('authenticatable_id', $userIds)
                ->latest('login_at')->limit(20)->get(['id', 'authenticatable_id as user_id', 'ip_address', 'login_at', 'logout_at'])
                ->map(fn ($log) => (array) $log)->values();
        }

        $timeline = collect($this->activityPayload());
        if (Schema::hasTable('activity_log')) {
            $timeline = $timeline->concat(DB::table('activity_log')->where('subject_type', Tenant::class)->where('subject_id', $this->id)
                ->latest()->limit(30)->get(['event', 'description', 'created_at'])->map(fn ($event) => [
                    'type' => $event->event ?: 'activity', 'title' => Str::headline($event->event ?: 'Activity'),
                    'message' => $event->description, 'created_at' => $event->created_at,
                ]));
        }

        return [
            'orders' => $orders,
            'reports' => $orderSummary,
            'security' => [
                'mfa_users' => $this->relationLoaded('users') ? $this->users->where('mfa_enabled', true)->count() : 0,
                'total_users' => $userIds->count(),
                'recent_logins' => $security,
            ],
            'timeline' => $timeline->sortByDesc('created_at')->take(40)->values()->all(),
        ];
    }
}
