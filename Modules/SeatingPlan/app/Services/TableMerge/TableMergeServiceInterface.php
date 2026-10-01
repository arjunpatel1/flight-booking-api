<?php

namespace Modules\SeatingPlan\Services\TableMerge;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SeatingPlan\Models\TableMerge;

interface TableMergeServiceInterface
{
    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return TableMerge
     */
    public function getModel(): TableMerge;

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
     * @return TableMerge
     * @throws ModelNotFoundException
     */
    public function show(int $id): TableMerge;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $branchId
     * @return array
     */
    public function getStructureFilters(?int $branchId = null): array;
}
