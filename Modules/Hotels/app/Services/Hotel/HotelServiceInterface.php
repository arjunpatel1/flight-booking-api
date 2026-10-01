<?php

namespace Modules\Hotels\Services\Hotel;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Hotels\Models\Hotel;

interface HotelServiceInterface
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
     * Get a new instance of model.
     *
     * @return Hotel
     */
    public function getModel(): Hotel;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Hotel|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Hotel|Builder|EloquentCollection|array;

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
     * @return Hotel
     * @throws ModelNotFoundException
     */
    public function show(int $id): Hotel;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Hotel
     */
    public function store(array $data): Hotel;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Hotel
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Hotel;

    /**
     * Destroy the resource's by given id.
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
    public function getStructureFilters(?int $branchId): array;

    /**
     * Get form meta
     *
     * @param int|null $branchId
     * @return array
     */
    public function getFormMeta(?int $branchId): array;
}
