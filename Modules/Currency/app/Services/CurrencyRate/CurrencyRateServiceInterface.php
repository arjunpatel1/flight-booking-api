<?php

namespace Modules\Currency\Services\CurrencyRate;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Currency\Models\CurrencyRate;
use Modules\Currency\Services\CurrencyRateExchanger;

interface CurrencyRateServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return CurrencyRate
     */
    public function getModel(): CurrencyRate;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return CurrencyRate|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): CurrencyRate|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return CurrencyRate
     * @throws ModelNotFoundException
     */
    public function show(int $id): CurrencyRate;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return CurrencyRate
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): CurrencyRate;

    /**
     * Refresh currency rates.
     *
     * @param CurrencyRateExchanger $exchanger
     * @return void
     */
    public function refresh(CurrencyRateExchanger $exchanger): void;
}
