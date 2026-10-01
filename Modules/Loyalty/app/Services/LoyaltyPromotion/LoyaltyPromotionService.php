<?php

namespace Modules\Loyalty\Services\LoyaltyPromotion;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Support\Enums\Day;
use Modules\Support\GlobalStructureFilters;

class LoyaltyPromotionService implements LoyaltyPromotionServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_promotions.loyalty_promotion");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["loyaltyProgram:id,name"])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyPromotion
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyPromotion::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyPromotion
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyPromotion
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): LoyaltyPromotion
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): LoyaltyPromotion
    {
        $loyaltyPromotion = $this->findOrFail($id);
        $loyaltyPromotion->update($data);

        return $loyaltyPromotion;
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
                "label" => __('loyalty::loyalty_promotions.filters.type'),
                "type" => 'select',
                "options" => LoyaltyPromotionType::toArrayTrans(),
            ],
            [
                "key" => 'loyalty_program_id',
                "label" => __('loyalty::loyalty_promotions.filters.loyalty_program'),
                "type" => 'select',
                "options" => LoyaltyProgram::list(),
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
            "types" => LoyaltyPromotionType::toArrayTrans(),
            "loyalty_programs" => LoyaltyProgram::list(),
            "days" => Day::toArrayTrans(),
            "categories" => Category::treeListWithSlug(),
            "branches" => Branch::list(),
        ];
    }
}
