<?php

namespace Modules\Loyalty\Services\LoyaltyTransaction;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Loyalty\Models\LoyaltyTransaction;

interface LoyaltyTransactionServiceInterface
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
     * @return LoyaltyTransaction
     */
    public function getModel(): LoyaltyTransaction;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return LoyaltyTransaction|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): LoyaltyTransaction|Builder|EloquentCollection|array;

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
     * @return LoyaltyTransaction
     * @throws ModelNotFoundException
     */
    public function show(int $id): LoyaltyTransaction;

    /**
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;
}
