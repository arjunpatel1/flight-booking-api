<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\GlobalStructureFilters;

class ActivePromotionsReport extends LoyaltyReport
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
        return "loyalty_active_promotions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "promotion_type",
            "usage_count",
            "start_date",
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
            "loyalty_promotions.total_used as usage_count",
            "loyalty_promotions.starts_at as start_date",
            "loyalty_promotions.ends_at as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "promotion_type" => $model->type->trans(),
            "usage_count" => (int)$model->usage_count,
            "start_date" => dateTimeFormat($model->start_date),
            "end_date" => dateTimeFormat($model->end_date),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where('loyalty_promotions.is_active', 1)
                ->where(function ($q) {
                    $q->whereNull('loyalty_promotions.starts_at')->orWhereDate('loyalty_promotions.starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('loyalty_promotions.ends_at')->orWhereDate('loyalty_promotions.ends_at', '>=', now());
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
