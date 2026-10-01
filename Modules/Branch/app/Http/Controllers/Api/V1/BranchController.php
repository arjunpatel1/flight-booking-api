<?php

namespace Modules\Branch\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Http\Requests\Api\V1\SaveBranchRequest;
use Modules\Branch\Services\Branch\BranchServiceInterface;
use Modules\Branch\Transformers\Api\V1\BranchResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class BranchController extends Controller
{
    /**
     * Create a new instance of BranchController
     *
     * @param BranchServiceInterface $service
     */
    public function __construct(protected BranchServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Branch models.
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
            resource: BranchResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
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

    /**
     * This method retrieves and returns a single Branch model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new BranchResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Branch model.
     *
     * @param SaveBranchRequest $request
     * @return JsonResponse
     */
    public function store(SaveBranchRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new BranchResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Branch model.
     *
     * @param SaveBranchRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveBranchRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new BranchResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Branch model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label(),
            message: __("branch::messages.branches_cannot_be_destroyed")
        );
    }
}
