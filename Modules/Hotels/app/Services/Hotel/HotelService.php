<?php

namespace Modules\Hotels\Services\Hotel;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Modules\Branch\Models\Branch;
use Modules\Hotels\Models\Hotel;
use Modules\Menu\Models\OnlineMenu;
use Modules\Support\GlobalStructureFilters;

class HotelService implements HotelServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("hotels::hotels.hotel");
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

    /** @inheritDoc */
    public function getModel(): Hotel
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Hotel::class;
    }

    /** @inheritDoc */
    public function show(int $id): Hotel
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Hotel
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Hotel
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Hotel
    {
        $hotel = $this->findOrFail($id);
        $hotel->update($data);

        return $hotel;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        $hotelIds = parseIds($ids);
        
        // Check if any hotel is linked to online menus
        $linkedHotels = OnlineMenu::whereIn('hotel_branch_id', $hotelIds)->exists();
        
        if ($linkedHotels) {
            throw new \LogicException(__('hotels::messages.cannot_delete_linked_hotel'));
        }

        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn("id", $hotelIds)
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId): array
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
    public function getFormMeta(?int $branchId): array
    {
        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
            ];
        }

        // Get hotels for the specific branch with caching
        $cacheKey = makeCacheKey(['hotels', 'branch', $branchId]);

        $hotels = Cache::tags("hotels")
            ->rememberForever($cacheKey,
                fn() => $this->getModel()
                    ->select('id', 'name', 'branch_id')
                    ->where('is_active', true)
                    ->where('branch_id', $branchId)
                    ->get()
                    ->map(fn($hotel) => [
                        'id' => $hotel->id,
                        'name' => $hotel->name,
                    ])
            );

        return [
            "hotels" => $hotels,
        ];
    }
}
