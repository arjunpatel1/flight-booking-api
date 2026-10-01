<?php

namespace Modules\Loyalty\Services\LoyaltyReward;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Product\Models\Product;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;

class LoyaltyRewardService implements LoyaltyRewardServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_rewards.loyalty_reward");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["loyaltyProgram:id,name", "files"])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyReward
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyReward::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyReward
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyReward
    {
        return $this->getModel()
            ->query()
            ->with(["files"])
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): LoyaltyReward
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): LoyaltyReward
    {
        $loyaltyReward = $this->findOrFail($id);
        $loyaltyReward->update($data);

        return $loyaltyReward;
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
    public function getStructureFilters(?int $programId = null): array
    {
        return [
            [
                "key" => 'type',
                "label" => __('loyalty::loyalty_rewards.filters.type'),
                "type" => 'select',
                "options" => LoyaltyRewardType::toArrayTrans(),
            ],
            [
                "key" => 'loyalty_program_id',
                "label" => __('loyalty::loyalty_rewards.filters.loyalty_program'),
                "type" => 'select',
                "options" => LoyaltyProgram::list(),
            ],
            [
                "key" => 'loyalty_tier_id',
                "label" => __('loyalty::loyalty_customers.filters.loyalty_tier'),
                "type" => 'select',
                "options" => !is_null($programId) ? LoyaltyTier::list($programId) : [],
                "depends" => "loyalty_program_id"
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $programId = null): array
    {
        if (is_null($programId)) {
            return [
                "types" => LoyaltyRewardType::toArrayTrans(),
                "loyalty_programs" => LoyaltyProgram::list(),
                "price_types" => PriceType::toArrayTrans(),
                "days" => Day::toArrayTrans(),
                "products" => Product::listBySku(),
                "branches" => Branch::list(),
            ];
        } else {
            return [
                "loyalty_tiers" => LoyaltyTier::list($programId),
            ];
        }
    }
}
