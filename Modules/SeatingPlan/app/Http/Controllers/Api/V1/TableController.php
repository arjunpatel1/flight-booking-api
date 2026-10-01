<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveTableRequest;
use Modules\SeatingPlan\Services\Table\TableServiceInterface;
use Modules\SeatingPlan\Transformers\Api\V1\TableResource;
use Modules\SeatingPlan\Transformers\Api\V1\TableStatusLogResource;
use Modules\Support\ApiResponse;

class TableController extends Controller
{
    /**
     * Create a new instance of TableController
     *
     * @param TableServiceInterface $service
     */
    public function __construct(protected TableServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Table models.
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
            resource: TableResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters(
                    branchId: auth()->user()->assignedToBranch()
                        ? auth()->user()->branch_id
                        : $request->get('filters')['branch_id'] ?? null,
                    floorId: $request->get('filters')['floor_id'] ?? null,
                )
                : null
        );
    }

    /**
     * Get table status logs
     *
     * @param int $id
     * @return JsonResponse
     */
    public function getStatusLogs(int $id): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->getStatusLogs($id),
            resource: TableStatusLogResource::class,
        );
    }

    /**
     * This method retrieves and returns a single Table model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new TableResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Table model.
     *
     * @param SaveTableRequest $request
     * @return JsonResponse
     */
    public function store(SaveTableRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new TableResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Table model.
     *
     * @param SaveTableRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveTableRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new TableResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Table model based on the provided ids.
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
                branchId: auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id'),
                floorId: $request->get('floor_id'),
            )
        );
    }
}
