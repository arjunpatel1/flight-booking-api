<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Http\Requests\Api\V1\SaveReasonRequest;
use Modules\Order\Services\Reason\ReasonServiceInterface;
use Modules\Order\Transformers\Api\V1\ReasonResource;
use Modules\Support\ApiResponse;

class ReasonController extends Controller
{
    /**
     * Create a new instance of ReasonController
     *
     * @param ReasonServiceInterface $service
     */
    public function __construct(protected ReasonServiceInterface $service)
    {
    }
    
    /**
     * This method retrieves and returns a list of reason models.
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
            resource: ReasonResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single reason model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ReasonResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the reason model.
     *
     * @param SaveReasonRequest $request
     * @return JsonResponse
     */
    public function store(SaveReasonRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new ReasonResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the reason model.
     *
     * @param SaveReasonRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveReasonRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ReasonResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the reason model based on the provided ids.
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
