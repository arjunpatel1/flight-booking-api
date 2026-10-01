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

class NewMemberPromotionsReport extends LoyaltyReport
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
        return "loyalty_new_member_promotions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "customers_joined",
            "bonus_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.name as name",
            "COALESCE(loyalty_promotions.total_customers, 0) as customers_joined",
            "COALESCE(loyalty_promotions.bonus_points, 0) as bonus_points",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "customers_joined" => (int)$model->customers_joined,
            "bonus_points" => $model->bonus_points . " Pts",
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where('loyalty_promotions.type', LoyaltyPromotionType::NewMember)
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
