<?php

namespace Modules\Loyalty\Services\LoyaltyTier;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Support\GlobalStructureFilters;

class LoyaltyTierService implements LoyaltyTierServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_tiers.loyalty_tier");
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
    public function getModel(): LoyaltyTier
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyTier::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyTier
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyTier
    {
        return $this->getModel()
            ->query()
            ->with(["files"])
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): LoyaltyTier
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): LoyaltyTier
    {
        $loyaltyTier = $this->findOrFail($id);
        $loyaltyTier->update($data);

        return $loyaltyTier;
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
                "key" => 'loyalty_program_id',
                "label" => __('loyalty::loyalty_tiers.filters.loyalty_program'),
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
            "loyalty_programs" => LoyaltyProgram::list(),
        ];
    }
}
