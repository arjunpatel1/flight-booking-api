<?php

namespace Modules\Loyalty\Services\LoyaltyReward;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Loyalty\Models\LoyaltyReward;

interface LoyaltyRewardServiceInterface
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
     * @return LoyaltyReward
     */
    public function getModel(): LoyaltyReward;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return LoyaltyReward|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): LoyaltyReward|Builder|EloquentCollection|array;

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
     * @return LoyaltyReward
     * @throws ModelNotFoundException
     */
    public function show(int $id): LoyaltyReward;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return LoyaltyReward
     */
    public function store(array $data): LoyaltyReward;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return LoyaltyReward
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): LoyaltyReward;

    /**
     * Destroy resource's by given id.
     *
     * @param int|array|string $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|array|string $ids): bool;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $programId
     * @return array
     */
    public function getStructureFilters(?int $programId = null): array;

    /**
     * Get form meta
     *
     * @param int|null $programId
     * @return array
     */
    public function getFormMeta(?int $programId = null): array;
}
