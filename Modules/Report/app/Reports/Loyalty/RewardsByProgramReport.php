<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Support\Enums\DateTimeFormat;

class RewardsByProgramReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_rewards.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyReward::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_rewards_by_program";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "reward_name",
            "reward_type",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lp.name as program_name",
            "loyalty_rewards.name as reward_name",
            "loyalty_rewards.type as type",
            "MIN(loyalty_rewards.created_at) as start_date",
            "MAX(loyalty_rewards.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "program_name" => $model->program_name,
            "reward_name" => $model->reward_name,
            "reward_type" => $model->type?->trans() ?? $model->type,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_programs as lp', 'lp.id', '=', 'loyalty_rewards.loyalty_program_id')
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('loyalty_rewards.loyalty_program_id', $filters['loyalty_program_id']))
                ->when(isset($filters['from']), fn($q) => $q->whereDate('loyalty_rewards.created_at', '>=', date("Y-m-d", strtotime($filters['from']))))
                ->when(isset($filters['to']), fn($q) => $q->whereDate('loyalty_rewards.created_at', '<=', date("Y-m-d", strtotime($filters['to']))))
        ];
    }
}
