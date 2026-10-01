<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Http\Requests\Api\V1\SaveUnitRequest;
use Modules\Inventory\Services\Unit\UnitServiceInterface;
use Modules\Inventory\Transformers\Api\V1\UnitResource;
use Modules\Support\ApiResponse;

class UnitController extends Controller
{
    /**
     * Create a new instance of UnitController
     *
     * @param UnitServiceInterface $service
     */
    public function __construct(protected UnitServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Unit models.
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
            resource: UnitResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Unit model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new UnitResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Unit model.
     *
     * @param SaveUnitRequest $request
     * @return JsonResponse
     */
    public function store(SaveUnitRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new UnitResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Unit model.
     *
     * @param SaveUnitRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveUnitRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new UnitResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Unit model based on the provided ids.
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
