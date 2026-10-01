<?php

namespace Modules\Pricing\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pricing\Http\Requests\Api\V1\SavePriceTypeRequest;
use Modules\Pricing\Services\PriceType\PriceTypeServiceInterface;
use Modules\Pricing\Transformers\Api\V1\PriceTypeResource;
use Modules\Support\ApiResponse;

class PriceTypeController extends Controller
{
    /**
     * Create a new instance of PriceTypeController.
     */
    public function __construct(protected PriceTypeServiceInterface $service)
    {
    }

    /**
     * Retrieve price types.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: PriceTypeResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * Show a price type.
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PriceTypeResource($this->service->show($id))
        );
    }

    /**
     * Store a price type.
     */
    public function store(SavePriceTypeRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new PriceTypeResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Update a price type.
     */
    public function update(SavePriceTypeRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PriceTypeResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Toggle price type status.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PriceTypeResource($this->service->toggleStatus($id)),
            resource: $this->service->label()
        );
    }

    /**
     * Delete price types.
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    /**
     * Get form meta.
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }
}
