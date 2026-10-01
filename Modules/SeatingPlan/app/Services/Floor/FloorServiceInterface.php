<?php

namespace Modules\SeatingPlan\Services\Floor;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\SeatingPlan\Models\Floor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

interface FloorServiceInterface
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
     * @return Floor
     */
    public function getModel(): Floor;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Floor|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Floor|Builder|EloquentCollection|array;

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
     * @return Floor
     * @throws ModelNotFoundException
     */
    public function show(int $id): Floor;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Floor
     */
    public function store(array $data): Floor;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Floor
     * @throws ModelNotFoundException
     */
    public function update(int $id,array $data): Floor;

    /**
     * Update the floor map settings.
     *
     * @param int $id
     * @param array $data
     * @return Floor
     * @throws ModelNotFoundException
     */
    public function updateMapSettings(int $id, array $data): Floor;

    /**
     * Load the advanced floor planner snapshot plus live floor entities.
     *
     * @param int $id
     * @return array
     * @throws ModelNotFoundException
     */
    public function getPlannerSnapshot(int $id): array;

    /**
     * Persist the advanced floor planner snapshot and sync live table positions.
     *
     * @param int $id
     * @param array $snapshot
     * @return Floor
     * @throws ModelNotFoundException
     */
    public function updatePlannerSnapshot(int $id, array $snapshot): Floor;

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
