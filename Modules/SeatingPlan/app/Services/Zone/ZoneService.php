<?php

namespace Modules\SeatingPlan\Services\Zone;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Pricing\Models\PriceType;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Zone;
use Modules\Support\GlobalStructureFilters;

class ZoneService implements ZoneServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("seatingplan::zones.zone");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->with(["branch:id,name", "floor:id,name", "priceType:id,name"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Zone
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Zone::class;
    }

    /** @inheritDoc */
    public function show(int $id): Zone
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->with(["priceType:id,name"])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Zone
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Zone
    {
        return $this->getModel()
            ->query()
            ->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Zone
    {
        $zone = $this->findOrFail($id);
        $zone->update($data);

        return $zone;
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
    public function getStructureFilters(?int $branchId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'floor_id',
                "label" => __('seatingplan::zones.filters.floor'),
                "type" => 'select',
                "options" => !is_null($branchId) ? Floor::list($branchId) : [],
                "depends" => "branch_id"
            ],
            [
                "key" => 'price_type_id',
                "label" => __('seatingplan::zones.filters.payment_type'),
                "type" => 'select',
                "options" => PriceType::list(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
                "payment_types" => PriceType::list(),
            ];
        } else {
            return [
                "floors" => Floor::list($branchId),
                "payment_types" => PriceType::list(),
            ];
        }
    }
}
