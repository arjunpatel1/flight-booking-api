<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyPromotion;

class HighestImpactPromotionsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_promotions.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyPromotion::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_highest_impact_promotions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "total_points_generated",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.name as name",
            "COALESCE(loyalty_promotions.total_used * COALESCE(loyalty_promotions.bonus_points, 1), 0) as total_points_generated",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "total_points_generated" => (int)$model->total_points_generated,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->orderByDesc('total_points_generated')
        ];
    }
}
