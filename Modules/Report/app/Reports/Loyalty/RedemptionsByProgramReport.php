<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Support\Enums\DateTimeFormat;

class RedemptionsByProgramReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_gifts.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_redemptions_by_program";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "total_redemptions",
            "total_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lp.name as program_name",
            "COUNT(loyalty_gifts.id) as total_redemptions",
            "COALESCE(SUM(loyalty_gifts.points_spent), 0) as total_points",
            "MIN(loyalty_gifts.created_at) as start_date",
            "MAX(loyalty_gifts.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "program_name" => $model->program_name,
            "total_redemptions" => (int)$model->total_redemptions,
            "total_points" => $model->total_points . " Pts",
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_programs as lp', 'lp.id', '=', 'loyalty_gifts.loyalty_program_id')
                ->groupBy('lp.id')
        ];
    }
}
