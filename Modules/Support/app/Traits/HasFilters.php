<?php

namespace Modules\Support\Traits;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Modules\Printer\Models\Printer;
use Modules\Support\Enums\GroupDateType;

/**
 * @method static Builder whereFromDate(string $date, Expression|string $column = "created_at")
 * @method static Builder whereToDate(string $date, Expression|string $column = "created_at")
 * @method static Builder filters(array $filters)
 */
trait HasFilters
{
    use HasLikeFilters;

    /**
     * Scope a query Filters
     *
     * @param Builder $query
     * @param array $filters
     * @return void
     */
    public function scopeFilters(Builder $query, array $filters): void
    {
        $allowFilters = $this->allowedFilterKeys();

        foreach ($filters as $key => $filter) {
            if ((!is_null($allowFilters) && !in_array($key, $allowFilters)) || ($key != "is_active" && $key != "built_in" && is_null($filter))) {
                continue;
            }

            if ($key === "role" && $this->getMorphClass() != Printer::class) {
                $filter = (int)$filter;
            }

            $studlyKey = str($key)->studly()->toString();
            $method = in_array(strtolower($studlyKey), ["from", "to"]) ? "Where{$studlyKey}Date" : "Where$studlyKey";

            if (method_exists($this, "scope$studlyKey")) {
                $query->{$studlyKey}($filter);
            } elseif (method_exists($this, "scope$method")) {
                $query->{$method}($filter);
            } else {
                $query->where($key, $filter);
            }
        }
    }

    /**
     * Get default allowed filters
     *
     * @return array
     */
    public function allowedFilterKeys(): array
    {
        return [];
    }

    /**
     * Scope a query to get from date.
     *
     * @param Builder $query
     * @param string $date
     * @param Expression|string $column
     * @return void
     */
    public function scopeWhereFromDate(Builder $query, string $date, Expression|string $column = "created_at"): void
    {
        $query->whereDate(self::$defaultDateColumn ?? $column, ">=", date("Y-m-d", strtotime($date)));
    }

    /**
     * Scope a query to get to date
     *
     * @param Builder $query
     * @param Expression|string $column
     * @param string $date
     * @return void
     */
    public function scopeWhereToDate(Builder $query, string $date, Expression|string $column = "created_at"): void
    {
        $query->whereDate(self::$defaultDateColumn ?? $column, "<=", date("Y-m-d", strtotime($date)));
    }

    /**
     * Scope a query to get updated date.
     *
     * @param Builder $query
     * @param string $date
     * @param Expression|string $column
     * @return void
     */
    public function scopeWhereUpdatedFromDate(Builder $query, string $date, Expression|string $column = "updated_at"): void
    {
        $query->whereDate($column, ">=", date("Y-m-d", strtotime($date)));
    }

    /**
     * Scope a query to get updated date
     *
     * @param Builder $query
     * @param Expression|string $column
     * @param string $date
     * @return void
     */
    public function scopeWhereUpdatedToDate(Builder $query, string $date, Expression|string $column = "updated_at"): void
    {
        $query->whereDate($column, "<=", date("Y-m-d", strtotime($date)));
    }

    /**
     * Group by date
     *
     * @param Builder $query
     * @param string $group
     * @return void
     */
    public function scopeGroupByDate(Builder $query, string $group): void
    {
        if (in_array($group, GroupDateType::values())) {
            $query->{"groupBy{$group}"}();
        }
    }

    /**
     * Group by years
     *
     * @param Builder $query
     * @return void
     */
    public function scopeGroupByYears(Builder $query): void
    {
        $query->groupAndOrderBy('YEAR');
    }

    /**
     * Group and order by
     *
     * @param Builder $query
     * @param string $part
     * @return void
     */
    public function scopeGroupAndOrderBy(Builder $query, string $part): void
    {
        $dateColumn = self::$defaultDateColumn ?? "created_at";
        $query->selectRaw("EXTRACT($part FROM $dateColumn) as $part")
            ->groupByRaw("EXTRACT($part FROM $dateColumn)")
            ->orderbyDesc($part);
    }

    /**
     * Group by months
     *
     * @param Builder $query
     * @return void
     */
    public function scopeGroupByMonths(Builder $query): void
    {
        $query->groupAndOrderBy('YEAR')->groupAndOrderBy('MONTH');
    }

    /**
     * Group by weeks
     *
     * @param Builder $query
     * @return void
     */
    public function scopeGroupByWeeks(Builder $query): void
    {
        $query->groupAndOrderBy('YEAR')->groupAndOrderBy('MONTH')->groupAndOrderBy('WEEK');
    }

    /**
     * Group by days
     *
     * @param Builder $query
     * @return void
     */
    public function scopeGroupByDays(Builder $query): void
    {
        $query->groupAndOrderBy('YEAR')
            ->groupAndOrderBy('MONTH')
            ->groupAndOrderBy('WEEK')
            ->groupAndOrderBy('DAY');
    }
}
