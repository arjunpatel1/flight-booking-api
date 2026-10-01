<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * @method static Builder sortBy(array|null $sorts = [])
 */
trait HasSortBy
{
    const DESC = 'DESC';
    const ASC = 'ASC';

    /**
     * Apply sorting to the query based on specified columns.
     *
     * @param Builder $query
     * @param array|null $sorts
     * @return void
     */
    public function scopeSortBy(Builder $query, ?array $sorts = []): void
    {
        $sorts = $this->validateAndResolveSorts($sorts);

        if ($sorts->isEmpty()) {
            $query->latest(self::$defaultDateColumn ?? null)
                ->orderBy("id", "desc");
            return;
        }

        foreach ($sorts as $column => $direction) {
            if ($this->isColumnTranslatable($column)) {
                $query->orderBy("$column->" . app()->getLocale(), $direction);
                continue;
            }

            $query->orderBy($column, $direction);
        }
    }

    /**
     * Validate and resolve sortable columns and their directions.
     *
     * @param array|null $sorts
     * @return Collection
     */
    public function validateAndResolveSorts(?array $sorts): Collection
    {
        if (empty($sorts)) {
            return collect();
        }

        $sortableColumns = $this->getSortableColumnsCollection();
        return collect($sorts)->mapWithKeys(function ($sort) use ($sortableColumns) {
            $column = $sort['key'];
            $direction = $sort['order'];

            // Validate the column is allowed to be sorted by.
            if (!$sortableColumns->has($column)) {
                return [];
            }

            // Use the default direction if none is provided, use "ASC"
            $direction = strtoupper($direction);
            if (!in_array($direction, [self::ASC, self::DESC])) {
                if (in_array($sortableColumns->get($column), [self::ASC, self::DESC])) {
                    $direction = $sortableColumns->get($column);
                } else {
                    $direction = self::ASC;
                }
            }

            return [$column => $direction];
        });
    }

    /**
     * Get a collection of all sortable columns.
     *
     * @return Collection
     */
    public function getSortableColumnsCollection(): Collection
    {
        return collect(array_unique([
            ...$this->getSortableAttributes(),
            ...$this->getDefaultSortableColumns()
        ]))->mapWithKeys(function ($direction, $column) {
            if (is_numeric($column)) {
                $column = $direction;
                $direction = null;
            }

            return [$column => strtoupper($direction)];
        });
    }

    /**
     * The attributes that can be used for sorting.
     *
     * @return array
     */
    protected function getSortableAttributes(): array
    {
        return [];
    }

    /**
     * Get the default sortable columns.
     *
     * @return array
     */
    private function getDefaultSortableColumns(): array
    {
        // Include the primary key and default timestamp columns if applicable.
        $defaults = Arr::wrap($this->getKeyName());

        if ($this->usesTimestamps()) {
            $defaults[] = 'created_at';
            $defaults[] = 'updated_at';
        }

        if (in_array(HasOrder::class, class_uses_recursive($this))) {
            $defaults[] = 'order';
        }

        return $defaults;
    }

    /**
     * Check if the column is translatable.
     *
     * @param $column
     * @return bool
     */
    private function isColumnTranslatable($column): bool
    {
        return method_exists($this, 'isTranslatableAttribute') && $this->isTranslatableAttribute($column);
    }
}
