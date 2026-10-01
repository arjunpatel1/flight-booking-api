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

class CategoryBoostPromotionsReport extends LoyaltyReport
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
        return "loyalty_category_boost_promotions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "usage_count",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.name as name",
            "COALESCE(loyalty_promotions.total_used, 0) as usage_count",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "usage_count" => (int)$model->usage_count,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where('loyalty_promotions.type', LoyaltyPromotionType::CategoryBoost)
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
