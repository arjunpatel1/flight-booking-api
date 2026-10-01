<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Models\PosCashMovement;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;

class CashMovementReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "cash_movement";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "register_name",
            "user_name",
            "direction",
            "reason",
            "amount",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            'pos_register_id',
            'created_by',
            'reason',
            'direction',
            'currency',
            "SUM(amount) as amount",
            "MIN(occurred_at) as start_date",
            "MAX(occurred_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "register_name" => $model->posRegister->name,
            "user_name" => $model->createdBy?->name,
            "direction" => $model->direction->trans(),
            "reason" => $model->reason->trans(),
            "amount" => $model->amount
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->groupBy(["pos_register_id", "created_by", "reason", "direction", "currency"])
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'direction',
                "label" => __('report::reports.filters.direction'),
                "type" => 'select',
                "options" => PosCashDirection::toArrayTrans(),
            ],
            [
                "key" => 'reason',
                "label" => __('report::reports.filters.reason'),
                "type" => 'select',
                "options" => PosCashReason::toArrayTrans(),
            ]
        ];
    }

    /** @inheritDoc */
    public function with(): array
    {
        return [
            'posRegister:id,name',
            'createdBy:id,name',
        ];
    }

    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "occurred_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PosCashMovement::class;
    }
}
