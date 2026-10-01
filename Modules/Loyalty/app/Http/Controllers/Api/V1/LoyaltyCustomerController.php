<?php

namespace Modules\Loyalty\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Loyalty\Http\Requests\Api\V1\AdjustLoyaltyPointsRequest;
use Modules\Loyalty\Http\Requests\Api\V1\SaveLoyaltyCustomerRequest;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Services\Loyalty\LoyaltyServiceInterface;
use Modules\Loyalty\Services\LoyaltyCustomer\LoyaltyCustomerServiceInterface;
use Modules\Loyalty\Transformers\Api\V1\LoyaltyCustomerResource;
use Modules\Support\ApiResponse;

class LoyaltyCustomerController extends Controller
{
    /**
     * Create a new instance of LoyaltyCustomerController
     *
     * @param LoyaltyCustomerServiceInterface $service
     */
    public function __construct(
        protected LoyaltyCustomerServiceInterface $service,
        protected LoyaltyServiceInterface $loyaltyService,
    ) {
    }

    /**
     * This method retrieves and returns a list of LoyaltyCustomer models.
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
            resource: LoyaltyCustomerResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters($request->get('filters')['loyalty_program_id'] ?? null)
                : null
        );
    }

    /**
     * This method retrieves and returns a single LoyaltyCustomer model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new LoyaltyCustomerResource($this->service->show($id))
        );
    }

    /**
     * Manually credit (positive) or debit (negative) loyalty points for a customer —
     * a staff goodwill or correction adjustment.
     */
    public function adjustPoints(AdjustLoyaltyPointsRequest $request, int $id): JsonResponse
    {
        $customer = $this->service->findOrFail($id);

        $transaction = $this->loyaltyService->adjustPoints(
            $customer,
            $request->integer('points'),
            $request->input('reason'),
            auth()->id()
        );

        return ApiResponse::success(
            body: [
                'transaction_id' => $transaction->id,
                'points_balance' => $customer->fresh()->points_balance,
            ],
            message: __('admin::messages.resource_saved', ['resource' => $this->service->label()])
        );
    }

    /**
     * This method stores the provided data into storage for the LoyaltyCustomer model.
     *
     * @param SaveLoyaltyCustomerRequest $request
     * @return JsonResponse
     */
    public function store(SaveLoyaltyCustomerRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new LoyaltyCustomerResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the LoyaltyCustomer model.
     *
     * @param SaveLoyaltyCustomerRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveLoyaltyCustomerRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new LoyaltyCustomerResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the LoyaltyCustomer model based on the provided ids.
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
