<?php

namespace Modules\Currency\Models;


use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Modules\Currency\Services\CurrencyRateExchanger;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * @property int $id
 * @property string $currency
 * @property float $rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CurrencyRate extends Model
{
    use HasSortBy, HasFilters;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'currency',
        'rate'
    ];

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    public static function booted(): void
    {
        static::saved(function ($currencyRate) {
            Cache::forget(md5("currency_rate.$currencyRate->currency"));
        });
    }

    /**
     * Refresh all supported currencies exchange rate.
     *
     * @param CurrencyRateExchanger $exchanger
     *
     * @return void
     */
    public static function refreshRates(CurrencyRateExchanger $exchanger): void
    {
        $fromCurrency = setting('default_currency');
        foreach (setting('supported_currencies') as $toCurrency) {
            $rate = $exchanger->exchange($fromCurrency, $toCurrency);
            static::where('currency', $toCurrency)->first()->update(['rate' => $rate]);
        }
    }

    /**
     * Get currency rate for the given currency.
     *
     * @param string $currency
     * @return int|float
     */
    public static function for(string $currency): int|float
    {
        return Cache::rememberForever(md5("currency_rate.$currency"), function () use ($currency) {
            return static::where('currency', $currency)->value('rate');
        });
    }

    /**
     * Get default allowed filters
     *
     * @return array
     */
    public function allowedFilterKeys(): array
    {
        return [
            'search'
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query->like('currency', $value)->orLike('rate', $value);
        });
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            'currency',
            'rate'
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }
}
