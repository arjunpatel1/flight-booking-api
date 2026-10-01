<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveFloorMapSettingsRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveFloorPlannerSnapshotRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveFloorRequest;
use Modules\SeatingPlan\Transformers\Api\V1\FloorResource;
use Modules\SeatingPlan\Services\Floor\FloorServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;

class FloorController extends Controller
{
    /**
     * Create a new instance of FloorController
     *
     * @param FloorServiceInterface $service
     */
    public function __construct(protected FloorServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Floor models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: FloorResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Floor model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new FloorResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Floor model.
     *
     * @param SaveFloorRequest $request
     * @return JsonResponse
     */
    public function store(SaveFloorRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new FloorResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Floor model.
     *
     * @param SaveFloorRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveFloorRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new FloorResource($this->service->update($id,$request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates map settings for the Floor model.
     *
     * @param SaveFloorMapSettingsRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateMapSettings(SaveFloorMapSettingsRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new FloorResource($this->service->updateMapSettings($id, $request->validated())),
            resource: __("seatingplan::tables.map_settings.title")
        );
    }

    /**
     * Load the advanced floor planner snapshot.
     */
    public function plannerSnapshot(int $id): JsonResponse
    {
        $data = $this->service->getPlannerSnapshot($id);

        return ApiResponse::success([
            'floor' => new FloorResource($data['floor']),
            'snapshot' => $data['snapshot'],
            'live' => $data['live'],
            'baseVersion' => optional($data['floor']->updated_at)?->valueOf(),
        ]);
    }

    /**
     * Persist the advanced floor planner snapshot.
     */
    public function updatePlannerSnapshot(SaveFloorPlannerSnapshotRequest $request, int $id): JsonResponse
    {
        $floor = $this->service->updatePlannerSnapshot($id, $request->validated());

        return ApiResponse::updated(
            body: [
                'floor' => new FloorResource($floor),
                'baseVersion' => optional($floor->updated_at)?->valueOf(),
            ],
            resource: __("seatingplan::tables.map_settings.title")
        );
    }

    /**
     * This method deletes the Floor model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    /**
     * Get form meta
     *
     * @return JsonResponse
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }
}
