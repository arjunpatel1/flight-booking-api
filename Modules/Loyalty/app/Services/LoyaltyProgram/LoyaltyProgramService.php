<?php

namespace Modules\Loyalty\Services\LoyaltyProgram;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Saas\Support\TenantContext;
use Modules\Support\GlobalStructureFilters;

class LoyaltyProgramService implements LoyaltyProgramServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("loyalty::loyalty_programs.loyalty_program");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->when(app(TenantContext::class)->id(), fn ($query, $tenantId) => $query->forTenant($tenantId))
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyProgram
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyProgram::class;
    }

    /** @inheritDoc */
    public function show(int $id): LoyaltyProgram
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|LoyaltyProgram
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->when(app(TenantContext::class)->id(), fn ($query, $tenantId) => $query->forTenant($tenantId))
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): LoyaltyProgram
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): LoyaltyProgram
    {
        $tenantId = app(TenantContext::class)->id();
        $loyaltyProgram = $this->getModel()->query()->withoutGlobalActive()
            ->when($tenantId, fn ($query) => $query->ownedByTenant($tenantId))
            ->findOrFail($id);
        $loyaltyProgram->update($data);

        return $loyaltyProgram;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->when(app(TenantContext::class)->id(), fn ($query, $tenantId) => $query->ownedByTenant($tenantId))
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [];
    }
}
