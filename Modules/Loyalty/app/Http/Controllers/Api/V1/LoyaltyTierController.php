<?php

namespace Modules\Loyalty\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Loyalty\Http\Requests\Api\V1\SaveLoyaltyTierRequest;
use Modules\Loyalty\Services\LoyaltyTier\LoyaltyTierServiceInterface;
use Modules\Loyalty\Transformers\Api\V1\LoyaltyTierResource;
use Modules\Support\ApiResponse;

class LoyaltyTierController extends Controller
{
    /**
     * Create a new instance of LoyaltyTierController
     *
     * @param LoyaltyTierServiceInterface $service
     */
    public function __construct(protected LoyaltyTierServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of LoyaltyTier models.
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
            resource: LoyaltyTierResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single LoyaltyTier model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new LoyaltyTierResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the LoyaltyTier model.
     *
     * @param SaveLoyaltyTierRequest $request
     * @return JsonResponse
     */
    public function store(SaveLoyaltyTierRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new LoyaltyTierResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the LoyaltyTier model.
     *
     * @param SaveLoyaltyTierRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveLoyaltyTierRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new LoyaltyTierResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the LoyaltyTier model based on the provided ids.
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
