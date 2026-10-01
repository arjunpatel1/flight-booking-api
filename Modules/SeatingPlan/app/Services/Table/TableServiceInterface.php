<?php

namespace Modules\SeatingPlan\Services\Table;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\SeatingPlan\Models\Table;

interface TableServiceInterface
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
     * @return Table
     */
    public function getModel(): Table;

    /**
     * Get specific resource
     *
     * @param int $id
     * @param bool $withBranch
     * @return Table|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id, bool $withBranch = false): Table|Builder|EloquentCollection|array;

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
     * @return Table
     * @throws ModelNotFoundException
     */
    public function show(int $id): Table;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Table
     */
    public function store(array $data): Table;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Table
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Table;

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
     * @param int|null $floorId
     * @return array
     */
    public function getStructureFilters(?int $branchId = null, ?int $floorId = null): array;

    /**
     * Get form meta
     *
     * @param int|null $branchId
     * @param int|null $floorId
     * @return array
     */
    public function getFormMeta(?int $branchId = null, ?int $floorId = null): array;

    /**
     * Get table status logs
     *
     * @param int $id
     * @return LengthAwarePaginator
     */
    public function getStatusLogs(int $id): LengthAwarePaginator;
}
