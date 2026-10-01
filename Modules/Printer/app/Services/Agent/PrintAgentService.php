<?php

namespace Modules\Printer\Services\Agent;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Printer\Models\PrintAgent;
use Modules\Support\GlobalStructureFilters;

class PrintAgentService implements PrintAgentServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("printer::print_agents.print_agent");
    }

    /** @inheritDoc */
    public function show(int $id): PrintAgent
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|PrintAgent
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function getModel(): PrintAgent
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PrintAgent::class;
    }

    /** @inheritDoc */
    public function store(array $data): PrintAgent
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): PrintAgent
    {
        $agent = $this->findOrFail($id);
        $agent->update($data);

        return $agent;
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
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        return [
            "branches" => Branch::list(),
        ];
    }


    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name"])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }
}
