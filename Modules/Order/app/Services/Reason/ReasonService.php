<?php

namespace Modules\Order\Services\Reason;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Order\Enums\ReasonType;
use Modules\Order\Models\Reason;
use Modules\Support\GlobalStructureFilters;

class ReasonService implements ReasonServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("order::reasons.reason");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Reason
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Reason::class;
    }

    /** @inheritDoc */
    public function show(int $id): Reason
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Reason
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Reason
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Reason
    {
        $reason = $this->findOrFail($id);
        $reason->update($data);

        return $reason;
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
    public function getStructureFilters(): array
    {
        return [
            [
                "key" => 'type',
                "label" => __('order::reasons.filters.type'),
                "type" => 'select',
                "options" => ReasonType::toArrayTrans()
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "types" => ReasonType::toArrayTrans(),
        ];
    }
}
