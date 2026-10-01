<?php

namespace Modules\SeatingPlan\Services\Floor;

use App\NexDine;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\Zone;
use Modules\Support\GlobalStructureFilters;

class FloorService implements FloorServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("seatingplan::floors.floor");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->with("branch:id,name")
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Floor
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Floor::class;
    }

    /** @inheritDoc */
    public function show(int $id): Floor
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Floor
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Floor
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Floor
    {
        $floor = $this->findOrFail($id);
        $floor->update($data);

        return $floor;
    }

    /** @inheritDoc */
    public function updateMapSettings(int $id, array $data): Floor
    {
        $floor = $this->scopedFloorQuery()->findOrFail($id);

        $floor->update($data);

        return $floor->refresh();
    }

    /** @inheritDoc */
    public function getPlannerSnapshot(int $id): array
    {
        $floor = $this->scopedFloorQuery()->findOrFail($id);

        return [
            'floor' => $floor,
            'snapshot' => $floor->planner_snapshot,
            'live' => [
                'zones' => Zone::query()
                    ->withoutGlobalActive()
                    ->where('floor_id', $floor->id)
                    ->where('branch_id', $floor->branch_id)
                    ->orderBy('order')
                    ->get(['id', 'name', 'color', 'floor_id', 'branch_id']),
                'tables' => Table::query()
                    ->withoutGlobalActive()
                    ->where('floor_id', $floor->id)
                    ->where('branch_id', $floor->branch_id)
                    ->orderBy('order')
                    ->get([
                        'id',
                        'name',
                        'capacity',
                        'status',
                        'shape',
                        'zone_id',
                        'floor_id',
                        'branch_id',
                        'pos_x',
                        'pos_y',
                        'rotation',
                        'scale',
                    ]),
            ],
        ];
    }

    /** @inheritDoc */
    public function updatePlannerSnapshot(int $id, array $snapshot): Floor
    {
        return DB::transaction(function () use ($id, $snapshot) {
            $floor = $this->scopedFloorQuery()
                ->lockForUpdate()
                ->findOrFail($id);

            // Optimistic locking: reject if the floor changed since the client loaded it.
            $baseVersion = $snapshot['baseVersion'] ?? null;
            unset($snapshot['baseVersion']);
            abort_if(
                $baseVersion !== null
                    && $floor->updated_at !== null
                    && (int) $floor->updated_at->valueOf() !== (int) $baseVersion,
                409,
                __('seatingplan::messages.floor_layout_conflict')
            );

            $tableObjects = collect($snapshot['objects'] ?? [])
                ->filter(fn (array $object) => ($object['type'] ?? null) === 'table' && ! empty($object['backendId']));
            $tableIds = $tableObjects->pluck('backendId')->map(fn ($value) => (int) $value)->unique()->values();

            if ($tableIds->isNotEmpty()) {
                $tables = Table::query()
                    ->withoutGlobalActive()
                    ->where('branch_id', $floor->branch_id)
                    ->where('floor_id', $floor->id)
                    ->whereIn('id', $tableIds->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                abort_if(
                    $tables->count() !== $tableIds->count(),
                    422,
                    __('seatingplan::messages.invalid_table_layout_positions')
                );

                $tableObjects->each(function (array $object) use ($tables) {
                    /** @var Table $table */
                    $table = $tables->get((int) $object['backendId']);
                    $table->update([
                        'pos_x' => round((float) $object['x'], 2),
                        'pos_y' => round((float) $object['y'], 2),
                        'rotation' => round((float) ($object['rotation'] ?? 0), 2),
                        'scale' => round((float) ($object['scale'] ?? 1), 2),
                    ]);
                });
            }

            $floor->update([
                'layout_width' => (int) data_get($snapshot, 'canvas.width', $floor->layout_width),
                'layout_height' => (int) data_get($snapshot, 'canvas.height', $floor->layout_height),
                'show_grid' => (bool) data_get($snapshot, 'grid.visible', $floor->show_grid),
                'zone_layouts' => $this->zoneLayoutsFromPlannerSnapshot($snapshot, $floor->zone_layouts ?: []),
                'planner_snapshot' => $snapshot,
            ]);

            return $floor->refresh();
        });
    }

    private function scopedFloorQuery(): Builder
    {
        $user = auth()->user();

        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->when($user?->assignedToBranch(), fn ($query) => $query->whereBranch($user->branch_id))
            ->when($user?->assignedToTenant(), fn ($query) => $query->whereHas('branch',
                fn ($branch) => $branch->where('tenant_id', $user->tenant_id)));
    }

    private function zoneLayoutsFromPlannerSnapshot(array $snapshot, array $fallback): array
    {
        $layouts = [];

        foreach (($snapshot['zones'] ?? []) as $zone) {
            $backendId = (int) ($zone['backendId'] ?? 0);
            $rect = $zone['rect'] ?? null;

            if ($backendId <= 0 || ! is_array($rect)) {
                continue;
            }

            $layouts[(string) $backendId] = [
                'left' => round((float) ($rect['x'] ?? 0), 2),
                'top' => round((float) ($rect['y'] ?? 0), 2),
                'width' => round((float) ($rect['width'] ?? 620), 2),
                'height' => round((float) ($rect['height'] ?? 380), 2),
            ];
        }

        return $layouts ?: $fallback;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn("id", parseIds($ids))->delete() ?: false;
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
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
        ];
    }
}
