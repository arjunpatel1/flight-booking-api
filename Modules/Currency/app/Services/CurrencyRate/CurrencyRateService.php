<?php

namespace Modules\Currency\Services\CurrencyRate;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Currency\Models\CurrencyRate;
use Modules\Currency\Services\CurrencyRateExchanger;

class CurrencyRateService implements CurrencyRateServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("currency::currency_rates.currency_rate");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->filters($filters, [])
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): CurrencyRate
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return CurrencyRate::class;
    }

    /** @inheritDoc */
    public function show(int $id): CurrencyRate
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|CurrencyRate
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): CurrencyRate
    {
        $currencyRate = $this->findOrFail($id);
        $currencyRate->update($data);

        return $currencyRate;
    }

    /** @inheritDoc */
    public function refresh(CurrencyRateExchanger $exchanger): void
    {
        if (is_null(setting('currency_rate_exchange_service'))) {
            abort(400, __('currency::messages.exchange_service_is_not_configured'));
        }

        CurrencyRate::refreshRates($exchanger);
    }
}
