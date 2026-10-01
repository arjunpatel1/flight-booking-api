<?php

namespace Modules\Saas\Services\TenantSubscription;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Support\GlobalStructureFilters;

class TenantSubscriptionService implements TenantSubscriptionServiceInterface
{
    public function label(): string
    {
        return __('saas::tenant_subscriptions.tenant_subscription');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $latestForTenant = TenantSubscription::query()
            ->withoutGlobalScopes()
            ->selectRaw('MAX(id) AS id')
            ->whereHas('tenant')
            ->groupBy('tenant_id');

        return TenantSubscription::query()
            ->whereIn('id', $latestForTenant)
            ->whereHas('tenant')
            ->with(['tenant:id,name,slug', 'plan:id,name,code'])
            ->filters($filters)
            ->sortBy($sorts)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): TenantSubscription
    {
        return TenantSubscription::query()
            ->with(['tenant:id,name,slug', 'plan:id,name,code,features,limits'])
            ->findOrFail($id);
    }

    public function store(array $data): TenantSubscription
    {
        return DB::transaction(function () use ($data) {
            if (($data['status'] ?? null) === 'active') {
                $this->closeActiveSubscriptions((int) $data['tenant_id']);
            }

            return TenantSubscription::query()->create($this->normalize($data));
        });
    }

    public function update(int $id, array $data): TenantSubscription
    {
        return DB::transaction(function () use ($id, $data) {
            $subscription = $this->show($id);

            if (($data['status'] ?? null) === 'active') {
                $this->closeActiveSubscriptions((int) ($data['tenant_id'] ?? $subscription->tenant_id), $subscription->id);
            }

            $subscription->update($this->normalize($data));

            return $subscription->refresh();
        });
    }

    public function destroy(int|array|string $ids): bool
    {
        return TenantSubscription::query()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'tenant_id',
                'label' => __('saas::tenant_subscriptions.filters.tenant'),
                'type' => 'select',
                'options' => Tenant::query()->select('id', 'name')->get(),
            ],
            [
                'key' => 'subscription_plan_id',
                'label' => __('saas::tenant_subscriptions.filters.plan'),
                'type' => 'select',
                'options' => SubscriptionPlan::query()->withoutGlobalActive()->select('id', 'name')->get(),
            ],
            [
                'key' => 'status',
                'label' => __('saas::tenant_subscriptions.filters.status'),
                'type' => 'select',
                'options' => $this->statuses(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function getFormMeta(): array
    {
        return [
            'tenants' => Tenant::query()->select('id', 'name', 'slug')->get(),
            'plans' => SubscriptionPlan::query()->withoutGlobalActive()->select('id', 'name', 'code', 'access_scope', 'features', 'limits')->get(),
            'statuses' => $this->statuses(),
            'features' => collect(config('saas.default_features', []))
                ->merge(config('saas.enterprise_features', []))
                ->unique()
                ->map(fn (string $feature) => [
                    'id' => $feature,
                    'name' => __("saas::subscription_plans.features.{$feature}"),
                ])
                ->values(),
        ];
    }

    private function statuses(): array
    {
        return collect(['trial', 'active', 'past_due', 'cancelled', 'expired'])
            ->map(fn (string $status) => [
                'id' => $status,
                'name' => __("saas::tenant_subscriptions.statuses.{$status}"),
            ])
            ->all();
    }

    private function normalize(array $data): array
    {
        $overrides = [];
        foreach (($data['overrides'] ?? []) as $key => $value) {
            if ($value !== null && $value !== '') {
                $overrides[$key] = (int) $value;
            }
        }

        $allowedFeatures = collect(config('saas.default_features', []))
            ->merge(config('saas.enterprise_features', []))
            ->mapWithKeys(fn (string $feature) => [$feature => true]);
        $featureAccess = collect($data['feature_access'] ?? [])
            ->filter(fn ($value, string $feature) => $value !== null && $allowedFeatures->has($feature))
            ->map(fn ($value) => (bool) $value)
            ->all();

        if ($featureAccess !== []) {
            $overrides['_features'] = $featureAccess;
        }

        unset($data['feature_access']);

        return [
            ...$data,
            'overrides' => $overrides,
        ];
    }

    private function closeActiveSubscriptions(int $tenantId, ?int $exceptId = null): void
    {
        TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update([
                'status' => 'expired',
                'ends_at' => now(),
            ]);
    }
}
