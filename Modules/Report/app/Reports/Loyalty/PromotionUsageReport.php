<?php

namespace Modules\Report\Reports\Loyalty;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\GlobalStructureFilters;

class PromotionUsageReport extends LoyaltyReport
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
        return "loyalty_promotion_usage";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "promotion_name",
            "total_usage",
            "total_customers",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_promotions.name as name",
            "COALESCE(loyalty_promotions.total_used, 0) as total_usage",
            "COALESCE(loyalty_promotions.total_customers, 0) as total_customers",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "promotion_name" => $model->name,
            "total_usage" => (int)$model->total_usage,
            "total_customers" => (int)$model->total_customers,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [];
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
