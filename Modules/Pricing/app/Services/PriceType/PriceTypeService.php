<?php

namespace Modules\Pricing\Services\PriceType;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Pricing\Enums\PriceTypeRuleType;
use Modules\Pricing\Models\PriceType;
use Modules\Support\GlobalStructureFilters;

class PriceTypeService implements PriceTypeServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __('pricing::price_types.price_type');
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with('createdBy:id,name')
            ->withoutGlobalActive()
            ->filters($filters, [])
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): PriceType
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PriceType::class;
    }

    /** @inheritDoc */
    public function show(int $id): PriceType
    {
        return $this->findOrFail($id)->load('createdBy:id,name');
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|PriceType
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): PriceType
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): PriceType
    {
        $priceType = $this->findOrFail($id);

        $priceType->update($data);

        return $priceType;
    }

    /** @inheritDoc */
    public function toggleStatus(int $id): PriceType
    {
        $priceType = $this->findOrFail($id);
        $priceType->update([
            PriceType::ACTIVE_COLUMN_NAME => ! $priceType->is_active,
        ]);

        return $priceType;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            [
                'key' => 'rule_type',
                'label' => __('pricing::price_types.filters.rule_type'),
                'type' => 'select',
                'options' => PriceTypeRuleType::toArrayTrans(),
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
            'rule_types' => PriceTypeRuleType::toArrayTrans(),
        ];
    }
}
