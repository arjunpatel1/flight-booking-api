<?php

namespace Modules\SeatingPlan\Services\TableMerge;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\User;

class TableMergeService implements TableMergeServiceInterface
{
    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name",
                "createdBy:id,name",
                "closedBy:id,name",
                "members" => fn($query) => $query->with("table:id,name")
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): TableMerge
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return TableMerge::class;
    }

    /** @inheritDoc */
    public function show(int $id): TableMerge
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name",
                "createdBy:id,name",
                "closedBy:id,name",
                "members" => fn($query) => $query->with("table:id,name")
            ])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'type',
                "label" => __('seatingplan::table_merges.filters.type'),
                "type" => 'select',
                "options" => TableMergeType::toArrayTrans()
            ],
            [
                "key" => 'created_by',
                "label" => __('seatingplan::table_merges.filters.created_by'),
                "type" => 'select',
                "options" => !is_null($branchId) ? User::list($branchId) : [],
                "depends" => "branch_id"
            ],
            [
                "key" => 'closed_by',
                "label" => __('seatingplan::table_merges.filters.closed_by'),
                "type" => 'select',
                "options" => !is_null($branchId) ? User::list($branchId) : [],
                "depends" => "branch_id"
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

}
