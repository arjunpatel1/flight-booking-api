<?php

namespace Modules\Translation\Traits;

use App\NexDine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Spatie\Translatable\HasTranslations;

/**
 * @method static Builder whereLikeTranslation(string $column, mixed $value, string $boolean = 'and')
 * @method static Builder orWhereLikeTranslation(string $column, mixed $value)
 * @method static Builder search(mixed $value)
 */
trait Translatable
{
    use HasTranslations;

    const AND = 'and';
    const OR = 'or';

    /**
     * Scope a query to where like translations.
     *
     * @param Builder $query
     * @param string $column
     * @param mixed $value
     * @param string $boolean
     * @return void
     */
    public function scopeWhereLikeTranslation(Builder $query, string $column, mixed $value, string $boolean = self::AND): void
    {
        $query->whereRaw("LOWER({$this->prepareLikeParams($column)}) LIKE ?", ["%" . strtolower($value) . "%"]);
    }

    /**
     * Prepare Like parameters
     *
     * @param string $column
     * @return string
     */
    private function prepareLikeParams(string $column): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $column = "$column->" . locale();
        return $grammar->wrap($column);
    }

    /**
     * Scope a query to where like translations.
     *
     * @param Builder $query
     * @param string $column
     * @param mixed $value
     * @return void
     */
    public function scopeOrWhereLikeTranslation(Builder $query, string $column, mixed $value): void
    {
        /** @var self $query */
        $query->whereLikeTranslation($column, $value, self::OR);
    }

    /**
     * Scope search
     *
     * @param Builder $query
     * @param mixed $value
     * @return void
     */
    public function scopeSearch(Builder $query, mixed $value): void
    {
        /** @var self $query */
        $query->whereLikeTranslation($this->getSearchColumnName(), $value);
    }

    /**
     * Get search column name
     *
     * @return string
     */
    public function getSearchColumnName(): string
    {
        return "name";
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * @param string $key
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getAttributeValue($key): mixed
    {
        if (!$this->isTranslatableAttribute($key) || NexDine::returnAllTranslations()) {
            return parent::getAttributeValue($key);
        }

        return $this->getTranslation($key, $this->getLocale(), $this->useFallbackLocale());
    }
}
