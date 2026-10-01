<?php

namespace Modules\Inventory\Services\Purchase;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Inventory\Models\Purchase;
use Throwable;

interface PurchaseServiceInterface
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
     * @return Purchase
     */
    public function getModel(): Purchase;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Purchase|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Purchase|Builder|EloquentCollection|array;

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
     * @return Purchase
     * @throws ModelNotFoundException
     */
    public function show(int $id): Purchase;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Purchase
     */
    public function store(array $data): Purchase;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Purchase
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Purchase;

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
     * @param int|null $branchId
     * @return array
     */
    public function getStructureFilters(?int $branchId = null): array;

    /**
     * Get form meta
     *
     * @param int|null $branchId
     * @return array
     */
    public function getFormMeta(?int $branchId = null): array;

    /**
     * Mark as received
     *
     * @param int $id
     * @param array $data
     * @return void
     * @throws Throwable
     */
    public function markAsReceived(int $id, array $data): void;
}
