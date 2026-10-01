<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Http\Requests\Api\V1\SaveKitchenStationRequest;
use Modules\Pos\Services\KitchenStation\KitchenStationServiceInterface;
use Modules\Support\ApiResponse;

class KitchenStationController extends Controller
{
    public function __construct(
        private readonly KitchenStationServiceInterface $stationService
    ) {
    }

    /**
     * Get active stations.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id,deleted_at,NULL',
            'active_only' => 'nullable|boolean',
        ]);

        $stations = $this->stationService->getStations(
            $request->integer('branch_id') ?: null,
            $request->boolean('active_only')
        );

        return ApiResponse::success($stations);
    }

    /**
     * Create a station.
     */
    public function store(SaveKitchenStationRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: $this->stationService->store($request->validated()),
            resource: __('pos::pos.kitchen_station')
        );
    }

    /**
     * Update a station.
     */
    public function update(SaveKitchenStationRequest $request, int $stationId): JsonResponse
    {
        return ApiResponse::updated(
            body: $this->stationService->update($stationId, $request->validated()),
            resource: __('pos::pos.kitchen_station')
        );
    }

    /**
     * Delete stations.
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->stationService->destroy($ids),
            resource: __('pos::pos.kitchen_station')
        );
    }

    /**
     * Get form metadata.
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id,deleted_at,NULL',
        ]);

        return ApiResponse::success($this->stationService->getFormMeta($request->integer('branch_id') ?: null));
    }

    /**
     * Get items for a specific station.
     */
    public function getItems(Request $request, int $stationId): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:pending,preparing,completed',
        ]);

        $items = $this->stationService->getStationItems(
            $stationId,
            $request->get('status')
        );

        return ApiResponse::success($items);
    }

    /**
     * Start prep for an item.
     */
    public function startPrep(Request $request, int $stationId, int $itemId): JsonResponse
    {
        $item = $this->stationService->startPrep($itemId, auth()->user()?->id, $stationId);

        return ApiResponse::success($item);
    }

    /**
     * Complete/bump an item.
     */
    public function completeItem(Request $request, int $stationId, int $itemId): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $item = $this->stationService->completeItem(
            $itemId,
            auth()->user()?->id,
            $request->get('notes'),
            $stationId
        );

        return ApiResponse::success($item);
    }

    /**
     * Recall (un-bump) a completed item back to the preparing queue.
     */
    public function recallItem(Request $request, int $stationId, int $itemId): JsonResponse
    {
        $item = $this->stationService->recallItem($itemId, auth()->user()?->id, $stationId);

        return ApiResponse::success($item);
    }

    /**
     * Complete/bump multiple station items.
     */
    public function completeItems(Request $request, int $stationId): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer', 'distinct', 'exists:kitchen_station_order_products,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success(
            $this->stationService->completeItems(
                $validated['item_ids'],
                auth()->user()?->id,
                $validated['notes'] ?? null,
                $stationId
            )
        );
    }

    /**
     * Update item priority.
     */
    public function updatePriority(Request $request, int $stationId, int $itemId): JsonResponse
    {
        $request->validate([
            'priority' => 'required|integer|min:1|max:10',
        ]);

        $item = $this->stationService->updateItemPriority(
            $itemId,
            $request->get('priority'),
            $stationId
        );

        return ApiResponse::success($item);
    }

    /**
     * Get station statistics.
     */
    public function getStats(Request $request, int $stationId): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $stats = $this->stationService->getStationStats(
            $stationId,
            $request->get('from'),
            $request->get('to')
        );

        return ApiResponse::success($stats);
    }

    /**
     * Get delayed items across all stations.
     */
    public function getDelayedItems(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id,deleted_at,NULL',
        ]);

        $items = $this->stationService->getDelayedItems($request->integer('branch_id') ?: null);

        return ApiResponse::success($items);
    }

    /**
     * Auto-bump completed items.
     */
    public function autoBump(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id,deleted_at,NULL',
        ]);

        $bumpedCount = $this->stationService->autoBumpCompletedItems(
            $request->integer('branch_id') ?: null
        );

        return ApiResponse::success([
            'bumped_count' => $bumpedCount,
            'message' => __('pos::pos_viewer.kitchen_auto_bumped', ['count' => $bumpedCount]),
        ]);
    }
}
