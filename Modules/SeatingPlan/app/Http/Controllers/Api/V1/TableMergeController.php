<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\SeatingPlan\Services\TableMerge\TableMergeServiceInterface;
use Modules\SeatingPlan\Transformers\Api\V1\TableMergeResource;
use Modules\Support\ApiResponse;

class TableMergeController extends Controller
{
    /**
     * Create a new instance of TableMergeController
     *
     * @param TableMergeServiceInterface $service
     */
    public function __construct(protected TableMergeServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of TableMerge models.
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
            resource: TableMergeResource::class,
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
     * This method retrieves and returns a single TableMerge model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new TableMergeResource($this->service->show($id))
        );
    }
}
