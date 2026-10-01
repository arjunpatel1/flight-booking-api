<?php

namespace Modules\Saas\Services\Tenant;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Models\Tenant;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\User;

class TenantService implements TenantServiceInterface
{
    public function label(): string
    {
        return __('saas::tenants.tenant');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $query = Tenant::query()->withoutGlobalActive();
        $this->withDeliveryWalletBalances($query);
        $sorts = $this->applyRegistrySorts($query, $sorts ?? []);

        return $query
            ->with($this->indexRelations())
            ->withCount($this->countRelations())
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /**
     * Authoritative registry counters. These deliberately use the same base
     * query and scopes as the registry rather than counting the current UI
     * page, so cards, filters and exports describe the same restaurant set.
     */
    public function registrySummary(): array
    {
        $base = Tenant::query()->withoutGlobalActive();
        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();
        $suspended = (clone $base)->where('is_active', false)->count();
        $inSetup = (clone $base)->onboardingStatus('pending')->count();
        $onboarded = (clone $base)->onboardingStatus('completed')->count();
        $needsAttention = (clone $base)->needsAttention(true)->count();
        $activeNeedsAttention = (clone $base)
            ->where('is_active', true)
            ->needsAttention(true)
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'suspended' => $suspended,
            'in_setup' => $inSetup,
            'onboarded' => $onboarded,
            'needs_attention' => $needsAttention,
            'healthy' => max(0, $active - $activeNeedsAttention),
        ];
    }

    public function show(int $id): Tenant
    {
        $query = Tenant::query()->withoutGlobalActive();
        $this->withDeliveryWalletBalances($query);

        return $query
            ->with($this->showRelations())
            ->withCount($this->countRelations())
            ->findOrFail($id);
    }

    public function store(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::query()->create([
                ...$data,
                'slug' => $data['slug'] ?? Str::slug($data['name']),
            ]);

            foreach (config('saas.default_features', []) as $feature) {
                $tenant->featureLimits()->firstOrCreate(['feature' => $feature], [
                    'limit' => null,
                    'used' => 0,
                ]);
            }

            return $tenant;
        });
    }

    public function update(int $id, array $data): Tenant
    {
        $tenant = $this->show($id);
        $ownerCredentials = [
            'email' => $data['owner_login_email'] ?? null,
            'username' => $data['owner_login_username'] ?? null,
            'password' => $data['owner_login_password'] ?? null,
        ];

        unset($data['owner_login_email'], $data['owner_login_username'], $data['owner_login_password']);

        $tenant->update([
            ...$data,
            'slug' => $data['slug'] ?? $tenant->slug,
        ]);

        $this->updateOwnerCredentials($tenant, $ownerCredentials);

        return $tenant->refresh();
    }

    private function withDeliveryWalletBalances(Builder $query): void
    {
        if (! Schema::hasTable('delivery_wallet_accounts')) {
            return;
        }

        $query->addSelect([
            'delivery_wallet_available_balance' => DB::table('delivery_wallet_accounts')
                ->select('available_balance')
                ->whereColumn('delivery_wallet_accounts.tenant_id', 'tenants.id')
                ->limit(1),
            'delivery_wallet_reserved_balance' => DB::table('delivery_wallet_accounts')
                ->select('reserved_balance')
                ->whereColumn('delivery_wallet_accounts.tenant_id', 'tenants.id')
                ->limit(1),
        ]);
    }

    private function updateOwnerCredentials(Tenant $tenant, array $credentials): void
    {
        if (blank($credentials['email']) && blank($credentials['username']) && blank($credentials['password'])) {
            return;
        }

        $owner = $tenant->users()
            ->withoutGlobalActive()
            ->oldest('id')
            ->first();

        if (! $owner instanceof User) {
            return;
        }

        $updates = [];
        if (filled($credentials['email'])) {
            $updates['email'] = $credentials['email'];
        }
        if (filled($credentials['username'])) {
            $updates['username'] = $credentials['username'];
        }
        if (filled($credentials['password'])) {
            $updates['password'] = Hash::make($credentials['password']);
        }

        if ($updates) {
            $owner->forceFill($updates)->save();
        }
    }

    public function destroy(int|array|string $ids): bool
    {
        return Tenant::query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    public function getStructureFilters(): array
    {
        $filters = [
            GlobalStructureFilters::active(),
            [
                'key' => 'plan_id',
                'label' => 'Plan',
                'type' => 'select',
                'options' => Schema::hasTable('subscription_plans')
                    ? DB::table('subscription_plans')->orderBy('name')->get(['id', 'name'])->map(fn ($plan) => ['id' => $plan->id, 'name' => $plan->name])->all()
                    : [],
            ],
            [
                'key' => 'lifecycle_stage',
                'label' => 'Lifecycle',
                'type' => 'select',
                'options' => array_map(
                    fn (CustomerLifecycleStage $stage) => ['id' => $stage->value, 'name' => $stage->label()],
                    CustomerLifecycleStage::cases(),
                ),
            ],
            [
                'key' => 'onboarding_status',
                'label' => 'Onboarding',
                'type' => 'select',
                'options' => [
                    ['id' => 'pending', 'name' => 'Needs setup'],
                    ['id' => 'completed', 'name' => 'Completed'],
                    ['id' => 'failed', 'name' => 'Failed'],
                ],
            ],
            [
                'key' => 'subscription_status',
                'label' => 'Subscription',
                'type' => 'select',
                'options' => [
                    ['id' => 'trial', 'name' => 'Trial'],
                    ['id' => 'active', 'name' => 'Active'],
                    ['id' => 'past_due', 'name' => 'Past due'],
                    ['id' => 'cancelled', 'name' => 'Cancelled'],
                ],
            ],
            [
                'key' => 'renewal_status',
                'label' => 'Renewal',
                'type' => 'select',
                'options' => [
                    ['id' => 'due_7', 'name' => 'Due in 7 days'],
                    ['id' => 'due_30', 'name' => 'Due in 30 days'],
                ],
            ],
            [
                'key' => 'payment_status',
                'label' => 'Payment',
                'type' => 'select',
                'options' => [['id' => 'due', 'name' => 'Payment due']],
            ],
            [
                'key' => 'health_status',
                'label' => 'Health',
                'type' => 'select',
                'options' => [
                    ['id' => 'healthy', 'name' => 'Healthy'],
                    ['id' => 'unhealthy', 'name' => 'Unhealthy'],
                ],
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'city')) {
            $cities = DB::table('branches')->whereNotNull('city')->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city');
            array_splice($filters, -2, 0, [[
                'key' => 'city',
                'label' => 'City',
                'type' => 'select',
                'options' => $cities->map(fn ($city) => ['id' => $city, 'name' => $city])->all(),
            ]]);
        }

        return $filters;
    }

    private function indexRelations(): array
    {
        return array_filter([
            Schema::hasTable('tenant_subscriptions')
                ? 'activeSubscription.plan:id,name,code'
                : null,
            $this->hasProvisioningRunsTable()
                ? 'latestProvisioningRun:id,uuid,saas_provisioning_runs.tenant_id,status,progress,current_step,error,started_at,completed_at,failed_at,updated_at'
                : null,
        ]);
    }

    private function showRelations(): array
    {
        return array_filter([
            Schema::hasTable('tenant_subscriptions')
                ? 'activeSubscription.plan:id,name,code,features,limits'
                : null,
            'branches:id,tenant_id,name,currency,is_active',
            Schema::hasTable('tenant_feature_limits') ? 'featureLimits' : null,
            'users:id,tenant_id,branch_id,name,username,email,is_active,mfa_enabled,created_at,updated_at',
            'users.roles:id,name',
            $this->hasProvisioningRunsTable()
                ? 'latestProvisioningRun:id,uuid,saas_provisioning_runs.tenant_id,status,progress,current_step,error,started_at,completed_at,failed_at,updated_at'
                : null,
            Schema::hasTable('tenant_subscriptions')
                ? 'subscriptions.plan:id,name,code'
                : null,
        ]);
    }

    private function countRelations(): array
    {
        return array_filter([
            Schema::hasTable('branches') ? 'branches' : null,
            Schema::hasTable('tenant_subscriptions') ? 'subscriptions' : null,
            Schema::hasTable('users') ? 'users' : null,
        ]);
    }

    private function hasProvisioningRunsTable(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasTable('saas_provisioning_runs');
    }

    private function applyRegistrySorts(Builder $query, array $sorts): array
    {
        foreach ($sorts as $index => $sort) {
            $key = $sort['key'] ?? null;
            $direction = strtoupper((string) ($sort['order'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';

            if ($key === 'onboarding') {
                $this->sortByOnboarding($query, $direction);
                unset($sorts[$index]);
            }

            if ($key === 'active_plan_name') {
                $this->sortByPlan($query, $direction);
                unset($sorts[$index]);
            }
        }

        return array_values($sorts);
    }

    private function sortByOnboarding(Builder $query, string $direction): void
    {
        if (! $this->hasProvisioningRunsTable()) {
            return;
        }

        $latestProvisioning = DB::table('saas_provisioning_runs')
            ->selectRaw('MAX(id) as id, tenant_id')
            ->groupBy('tenant_id');

        $query
            ->leftJoinSub($latestProvisioning, 'latest_provisioning_sort', function ($join) {
                $join->on('latest_provisioning_sort.tenant_id', '=', 'tenants.id');
            })
            ->leftJoin('saas_provisioning_runs as provisioning_sort', 'provisioning_sort.id', '=', 'latest_provisioning_sort.id')
            ->orderByRaw(
                "CASE provisioning_sort.status WHEN 'failed' THEN 0 WHEN 'running' THEN 1 WHEN 'pending' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END {$direction}"
            )
            ->select('tenants.*');
    }

    private function sortByPlan(Builder $query, string $direction): void
    {
        if (! Schema::hasTable('tenant_subscriptions') || ! Schema::hasTable('subscription_plans')) {
            return;
        }

        $latestSubscription = DB::table('tenant_subscriptions')
            ->selectRaw('MAX(id) as id, tenant_id')
            ->whereIn('status', ['trial', 'active'])
            ->groupBy('tenant_id');

        $query
            ->leftJoinSub($latestSubscription, 'latest_subscription_sort', function ($join) {
                $join->on('latest_subscription_sort.tenant_id', '=', 'tenants.id');
            })
            ->leftJoin('tenant_subscriptions as subscription_sort', 'subscription_sort.id', '=', 'latest_subscription_sort.id')
            ->leftJoin('subscription_plans as plan_sort', 'plan_sort.id', '=', 'subscription_sort.subscription_plan_id')
            ->orderBy('plan_sort.name', $direction)
            ->select('tenants.*');
    }
}
