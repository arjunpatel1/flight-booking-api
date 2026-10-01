<?php

namespace Modules\Inventory\Services\StockMovement;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Inventory\Models\StockMovement;

interface StockMovementServiceInterface
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
     * @return StockMovement
     */
    public function getModel(): StockMovement;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return StockMovement|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): StockMovement|Builder|EloquentCollection|array;

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
     * @return StockMovement
     * @throws ModelNotFoundException
     */
    public function show(int $id): StockMovement;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return StockMovement
     */
    public function store(array $data): StockMovement;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return StockMovement
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): StockMovement;

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
}
