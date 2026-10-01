<?php

namespace Modules\Order\Services\Reason;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Order\Models\Reason;

interface ReasonServiceInterface
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
     * @return Reason
     */
    public function getModel(): Reason;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Reason|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Reason|Builder|EloquentCollection|array;

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
     * @return Reason
     * @throws ModelNotFoundException
     */
    public function show(int $id): Reason;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Reason
     */
    public function store(array $data): Reason;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Reason
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Reason;

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
