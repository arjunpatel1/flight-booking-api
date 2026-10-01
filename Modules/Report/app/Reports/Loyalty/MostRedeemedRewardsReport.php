<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Support\Enums\DateTimeFormat;

class MostRedeemedRewardsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_gifts.used_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_most_redeemed_rewards";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "reward_name",
            "reward_type",
            "total_redemptions",
            "total_points_spent",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_gifts.loyalty_reward_id",
            "lr.name as reward_name",
            "lr.type as type",
            "COUNT(loyalty_gifts.id) as total_redemptions",
            "COALESCE(SUM(loyalty_gifts.points_spent), 0) as total_points_spent",
            "MIN(loyalty_gifts.used_at) as start_date",
            "MAX(loyalty_gifts.used_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "reward_name" => $model->reward_name,
            "reward_type" => $model->type?->trans() ?? $model->type,
            "total_redemptions" => (int)$model->total_redemptions,
            "total_points_spent" => (int)$model->total_points_spent,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_rewards as lr', 'lr.id', '=', 'loyalty_gifts.loyalty_reward_id')
                ->whereNotNull('loyalty_gifts.used_at')
                ->groupBy('loyalty_gifts.loyalty_reward_id', 'lr.type')
                ->orderByDesc('total_redemptions')
        ];
    }
}
