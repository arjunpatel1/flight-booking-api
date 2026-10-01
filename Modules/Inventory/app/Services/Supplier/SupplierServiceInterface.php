<?php

namespace Modules\Inventory\Services\Supplier;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Inventory\Models\Supplier;

interface SupplierServiceInterface
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
     * @return Supplier
     */
    public function getModel(): Supplier;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Supplier|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Supplier|Builder|EloquentCollection|array;

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
     * @return Supplier
     * @throws ModelNotFoundException
     */
    public function show(int $id): Supplier;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Supplier
     */
    public function store(array $data): Supplier;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Supplier
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Supplier;

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
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Get form meta
     *
     * @return array
     */
    public function getFormMeta(): array;
}
