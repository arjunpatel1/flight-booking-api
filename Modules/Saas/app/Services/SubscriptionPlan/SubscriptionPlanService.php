<?php

namespace Modules\Saas\Services\SubscriptionPlan;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Support\GlobalStructureFilters;

class SubscriptionPlanService implements SubscriptionPlanServiceInterface
{
    public function label(): string
    {
        return __('saas::subscription_plans.subscription_plan');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return SubscriptionPlan::query()
            ->withoutGlobalActive()
            ->withCount('subscriptions')
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    public function store(array $data): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create($this->normalize($data));
    }

    public function update(int $id, array $data): SubscriptionPlan
    {
        $plan = $this->show($id);
        $plan->update($this->normalize($data));

        return $plan->refresh();
    }

    public function destroy(int|array|string $ids): bool
    {
        return SubscriptionPlan::query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'billing_cycle',
                'label' => __('saas::subscription_plans.filters.billing_cycle'),
                'type' => 'select',
                'options' => $this->billingCycles(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function getFormMeta(): array
    {
        return [
            'billing_cycles' => $this->billingCycles(),
            'access_scopes' => [
                ['id' => 'branch', 'name' => 'Single branch'],
                ['id' => 'tenant', 'name' => 'Whole restaurant / multi-branch'],
            ],
            'features' => collect(config('saas.default_features', []))
                ->merge(config('saas.enterprise_features', []))
                ->unique()
                ->map(fn(string $feature) => [
                    'id' => $feature,
                    'name' => __("saas::subscription_plans.features.{$feature}"),
                ])
                ->values(),
        ];
    }

    private function billingCycles(): array
    {
        return [
            ['id' => 'monthly', 'name' => __('saas::subscription_plans.billing_cycles.monthly')],
            ['id' => 'quarterly', 'name' => __('saas::subscription_plans.billing_cycles.quarterly')],
            ['id' => 'yearly', 'name' => __('saas::subscription_plans.billing_cycles.yearly')],
            ['id' => 'lifetime', 'name' => __('saas::subscription_plans.billing_cycles.lifetime')],
        ];
    }

    private function normalize(array $data): array
    {
        $limits = [];
        foreach (($data['limits'] ?? []) as $key => $value) {
            if ($value !== null && $value !== '') {
                $limits[$key] = (int) $value;
            }
        }
        if (($data['access_scope'] ?? 'tenant') === 'branch') {
            $limits['branches'] = 1;
        }

        return [
            ...$data,
            'features' => array_values($data['features'] ?? []),
            'limits' => $limits,
        ];
    }
}
