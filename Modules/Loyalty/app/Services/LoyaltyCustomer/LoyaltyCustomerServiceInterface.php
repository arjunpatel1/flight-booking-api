<?php

namespace Modules\Loyalty\Services\LoyaltyCustomer;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Loyalty\Models\LoyaltyCustomer;

interface LoyaltyCustomerServiceInterface
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
     * @return LoyaltyCustomer
     */
    public function getModel(): LoyaltyCustomer;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return LoyaltyCustomer|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): LoyaltyCustomer|Builder|EloquentCollection|array;

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
     * @return LoyaltyCustomer
     * @throws ModelNotFoundException
     */
    public function show(int $id): LoyaltyCustomer;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $programId
     * @return array
     */
    public function getStructureFilters(?int $programId = null): array;
}
