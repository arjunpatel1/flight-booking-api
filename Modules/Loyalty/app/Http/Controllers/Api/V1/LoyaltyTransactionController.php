<?php

namespace Modules\Loyalty\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Http\Requests\Api\V1\SaveLoyaltyTransactionRequest;
use Modules\Loyalty\Transformers\Api\V1\LoyaltyTransactionResource;
use Modules\Loyalty\Services\LoyaltyTransaction\LoyaltyTransactionServiceInterface;
use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Support\ApiResponse;

class LoyaltyTransactionController extends Controller
{
    /**
     * Create a new instance of LoyaltyTransactionController
     *
     * @param LoyaltyTransactionServiceInterface $service
     */
    public function __construct(protected LoyaltyTransactionServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of LoyaltyTransaction models.
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
            resource: LoyaltyTransactionResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single LoyaltyTransaction model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new LoyaltyTransactionResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the LoyaltyTransaction model.
     *
     * @param SaveLoyaltyTransactionRequest $request
     * @return JsonResponse
     */
    public function store(SaveLoyaltyTransactionRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new LoyaltyTransactionResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the LoyaltyTransaction model.
     *
     * @param SaveLoyaltyTransactionRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveLoyaltyTransactionRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new LoyaltyTransactionResource($this->service->update($id,$request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the LoyaltyTransaction model based on the provided ids.
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
