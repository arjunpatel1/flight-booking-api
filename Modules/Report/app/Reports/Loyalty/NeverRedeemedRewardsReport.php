<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyReward;

class NeverRedeemedRewardsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_rewards.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyReward::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_never_redeemed_rewards";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "reward_name",
            "points_cost",
            "created_date",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_rewards.id",
            "loyalty_rewards.name as name",
            "loyalty_rewards.points_cost",
            "loyalty_rewards.created_at as created_date",
            "COALESCE(SUM(CASE WHEN loyalty_gifts.used_at IS NOT NULL THEN 1 ELSE 0 END), 0) as used_count",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "reward_name" => $model->name,
            "points_cost" => (int)$model->points_cost,
            "created_date" => $model->created_date,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin('loyalty_gifts', 'loyalty_gifts.loyalty_reward_id', '=', 'loyalty_rewards.id')
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('loyalty_rewards.loyalty_program_id', $filters['loyalty_program_id']))
                ->groupBy('loyalty_rewards.id', 'loyalty_rewards.name', 'loyalty_rewards.points_cost', 'loyalty_rewards.created_at')
                ->havingRaw('COALESCE(SUM(CASE WHEN loyalty_gifts.used_at IS NOT NULL THEN 1 ELSE 0 END),0) = 0')
        ];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function globalFilters(): array
    {
        return [
            $this->programFilter(),
        ];
    }
}
