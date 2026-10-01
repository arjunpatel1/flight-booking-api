<?php

namespace Modules\Tax\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Tax\Http\Requests\Api\V1\SaveTaxRequest;
use Modules\Tax\Transformers\Api\V1\TaxResource;
use Modules\Tax\Services\Tax\TaxServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;

class TaxController extends Controller
{
    /**
     * Create a new instance of TaxController
     *
     * @param TaxServiceInterface $service
     */
    public function __construct(protected TaxServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Tax models.
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
            resource: TaxResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Tax model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new TaxResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the Tax model.
     *
     * @param SaveTaxRequest $request
     * @return JsonResponse
     */
    public function store(SaveTaxRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new TaxResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Tax model.
     *
     * @param SaveTaxRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveTaxRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new TaxResource($this->service->update($id,$request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Tax model based on the provided ids.
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
