<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\GlobalStructureFilters;

class ExpiredPromotionsReport extends LoyaltyReport
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
        return "loyalty_expired_promotions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "promotion_type",
            "end_date",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.id",
            "loyalty_promotions.name as name",
            "loyalty_promotions.type as type",
            "loyalty_promotions.ends_at as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "promotion_type" => $model->type->trans(),
            "end_date" => dateTimeFormat($model->end_date),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where(function ($q) {
                    $q->where('loyalty_promotions.is_active', false)
                        ->orWhere(function ($inner) {
                            $inner->whereNotNull('loyalty_promotions.ends_at')
                                ->whereDate('loyalty_promotions.ends_at', '<', now());
                        });
                })
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
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
            $this->programFilter(),
        ];
    }
}
