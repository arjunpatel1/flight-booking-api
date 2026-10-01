<?php

namespace Modules\SeatingPlan\Services\Table;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\SeatingPlan\Enums\TableShape;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Modules\Support\GlobalStructureFilters;

class TableService implements TableServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("seatingplan::tables.table");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->with([
                "branch:id,name,tenant_id",
                "branch.tenant:id,name,settings",
                "floor:id,name",
                "zone:id,name",
                "branch.onlineMenus:id,branch_id,slug,is_active",
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Table
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Table::class;
    }

    /** @inheritDoc */
    public function show(int $id): Table
    {
        return $this->getModel()
            ->query()
            ->with([
                "branch:id,name,tenant_id",
                "branch.tenant:id,name,settings",
                "floor:id,name",
                "zone:id,name",
                "branch.onlineMenus:id,branch_id,slug,is_active",
            ])
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id, bool $withBranch = false): Builder|array|EloquentCollection|Table
    {
        return $this->getModel()
            ->query()
            ->when($withBranch, fn($query) => $query->with("branch"))
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Table
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Table
    {
        $table = $this->findOrFail($id);
        $table->update($data);

        return $table;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null, ?int $floorId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'floor_id',
                "label" => __('seatingplan::tables.filters.floor'),
                "type" => 'select',
                "options" => !is_null($branchId) ? Floor::list($branchId) : [],
                "depends" => "branch_id"
            ],
            [
                "key" => 'zone_id',
                "label" => __('seatingplan::tables.filters.zone'),
                "type" => 'select',
                "options" => !is_null($branchId) && !is_null($floorId) ? Zone::list($branchId, $floorId) : [],
                "depends" => "floor_id"
            ],
            [
                "key" => 'status',
                "label" => __('admin::admin.filters.status'),
                "type" => 'select',
                "options" => TableStatus::toArrayTrans(),
            ],
            [
                "key" => 'shape',
                "label" => __('seatingplan::tables.filters.shape'),
                "type" => 'select',
                "options" => TableShape::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null, ?int $floorId = null): array
    {
        $shapes = TableShape::toArrayTrans();

        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
                "shapes" => $shapes,
            ];
        } else if ($branchId && is_null($floorId)) {
            return [
                "shapes" => $shapes,
                "floors" => Floor::list($branchId),
            ];
        } else if ($branchId && $floorId) {
            return [
                "shapes" => $shapes,
                "zones" => Zone::list($branchId, $floorId),
            ];
        }
        return ["shapes" => $shapes];
    }

    /** @inheritDoc */
    public function getStatusLogs($id): LengthAwarePaginator
    {
        return $this->findOrFail($id)
            ->statusLogs()
            ->with(["changedBy:id,name"])
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }
}
