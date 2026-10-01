<?php

namespace Modules\Option\Services\Option;

use App\NexDine;
use Arr;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Option\Enums\OptionType;
use Modules\Option\Models\Option;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;

class OptionService implements OptionServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("option::options.option");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->globals()
            ->with(["branch:id,name"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Option
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Option::class;
    }

    /** @inheritDoc */
    public function show(int $id): Option
    {
        return $this->getModel()
            ->query()
            ->globals()
            ->with("values")
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Option
    {
        return $this->getModel()
            ->query()
            ->globals()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Option
    {
        $option = $this->getModel()->query()->globals()->create(Arr::except($data, ['values']));

        $option->saveValues($data['values']);

        return $option;
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Option
    {
        $option = $this->findOrFail($id);

        $option->update(Arr::except($data, ['values']));

        $option->saveValues($data['values']);
        return $option;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->globals()
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'type',
                "label" => __('option::options.filters.type'),
                "type" => 'select',
                "options" => OptionType::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
            "types" => OptionType::toArrayTrans(),
            "price_types" => PriceType::toArrayTrans(),
        ];
    }
}
