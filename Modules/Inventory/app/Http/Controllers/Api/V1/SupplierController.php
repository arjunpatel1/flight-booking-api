<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Http\Requests\Api\V1\SaveSupplierRequest;
use Modules\Inventory\Services\Supplier\SupplierServiceInterface;
use Modules\Inventory\Transformers\Api\V1\SupplierResource;
use Modules\Support\ApiResponse;

class SupplierController extends Controller
{
    /**
     * Create a new instance of SupplierController
     *
     * @param SupplierServiceInterface $service
     */
    public function __construct(protected SupplierServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Supplier models.
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
            resource: SupplierResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Supplier model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new SupplierResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Supplier model.
     *
     * @param SaveSupplierRequest $request
     * @return JsonResponse
     */
    public function store(SaveSupplierRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new SupplierResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Supplier model.
     *
     * @param SaveSupplierRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveSupplierRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new SupplierResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Supplier model based on the provided ids.
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
