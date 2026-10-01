<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\GlobalStructureFilters;

class BonusVsMultiplierComparisonReport extends LoyaltyReport
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
        return "loyalty_bonus_vs_multiplier_comparison";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_type",
            "total_promotions",
            "total_usage",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.type as type",
            "COUNT(*) as total_promotions",
            "COALESCE(SUM(loyalty_promotions.total_used), 0) as total_usage",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_type" => $model->type->trans(),
            "total_promotions" => (int)$model->total_promotions,
            "total_usage" => (int)$model->total_usage,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereIn('loyalty_promotions.type', [LoyaltyPromotionType::BonusPoints->value, LoyaltyPromotionType::Multiplier->value])
                ->groupBy('loyalty_promotions.type')
        ];
    }

    /** @inheritDoc */
    public function globalFilters(): array
    {
        return [
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
            $this->programFilter(),
        ];
    }
}
