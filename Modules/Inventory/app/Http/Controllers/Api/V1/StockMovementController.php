<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Http\Requests\Api\V1\SaveStockMovementRequest;
use Modules\Inventory\Services\StockMovement\StockMovementServiceInterface;
use Modules\Inventory\Transformers\Api\V1\StockMovementResource;
use Modules\Support\ApiResponse;

class StockMovementController extends Controller
{
    /**
     * Create a new instance of StockMovementController
     *
     * @param StockMovementServiceInterface $service
     */
    public function __construct(protected StockMovementServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of StockMovement models.
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
            resource: StockMovementResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters(
                    auth()->user()->assignedToBranch()
                        ? auth()->user()->branch_id
                        : $request->get('filters')['branch_id'] ?? null
                )
                : null
        );
    }

    /**
     * This method retrieves and returns a single StockMovement model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new StockMovementResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the StockMovement model.
     *
     * @param SaveStockMovementRequest $request
     * @return JsonResponse
     */
    public function store(SaveStockMovementRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new StockMovementResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the StockMovement model.
     *
     * @param SaveStockMovementRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveStockMovementRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new StockMovementResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the StockMovement model based on the provided ids.
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
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->getFormMeta(
                auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }
}
