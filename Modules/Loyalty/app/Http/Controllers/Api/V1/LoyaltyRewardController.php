<?php

namespace Modules\Loyalty\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Loyalty\Http\Requests\Api\V1\SaveLoyaltyRewardRequest;
use Modules\Loyalty\Services\LoyaltyReward\LoyaltyRewardServiceInterface;
use Modules\Loyalty\Transformers\Api\V1\LoyaltyRewardResource;
use Modules\Loyalty\Transformers\Api\V1\ShowLoyaltyRewardResource;
use Modules\Support\ApiResponse;

class LoyaltyRewardController extends Controller
{
    /**
     * Create a new instance of LoyaltyRewardController
     *
     * @param LoyaltyRewardServiceInterface $service
     */
    public function __construct(protected LoyaltyRewardServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of LoyaltyReward models.
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
            resource: LoyaltyRewardResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters($request->get('filters')['loyalty_program_id'] ?? null)
                : null
        );
    }

    /**
     * This method retrieves and returns a single LoyaltyReward model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowLoyaltyRewardResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the LoyaltyReward model.
     *
     * @param SaveLoyaltyRewardRequest $request
     * @return JsonResponse
     */
    public function store(SaveLoyaltyRewardRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new LoyaltyRewardResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the LoyaltyReward model.
     *
     * @param SaveLoyaltyRewardRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveLoyaltyRewardRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new LoyaltyRewardResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the LoyaltyReward model based on the provided ids.
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
        return ApiResponse::success($this->service->getFormMeta($request->get('loyalty_program_id')));
    }
}
