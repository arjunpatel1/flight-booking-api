<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Inventory\Http\Requests\Api\V1\MarkAsReceivedPurchaseRequest;
use Modules\Inventory\Http\Requests\Api\V1\SavePurchaseRequest;
use Modules\Inventory\Services\Purchase\PurchaseServiceInterface;
use Modules\Inventory\Transformers\Api\V1\PurchaseResource;
use Modules\Support\ApiResponse;
use Throwable;

class PurchaseController extends Controller
{
    /**
     * Create a new instance of PurchaseController
     *
     * @param PurchaseServiceInterface $service
     */
    public function __construct(protected PurchaseServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of Purchase models.
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
            resource: PurchaseResource::class,
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
     * This method retrieves and returns a single Purchase model based on the provided identifier.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PurchaseResource($this->service->show($id, $request->get('with_receipt', false)))
        );
    }

    /**
     * This method stores the provided data into storage for the Purchase model.
     *
     * @param SavePurchaseRequest $request
     * @return JsonResponse
     */
    public function store(SavePurchaseRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new PurchaseResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the Purchase model.
     *
     * @param SavePurchaseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SavePurchaseRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new PurchaseResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the Purchase model based on the provided ids.
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
        return ApiResponse::success($this->service->getFormMeta(
            auth()->user()->assignedToBranch()
                ? auth()->user()->branch_id
                : $request->get('branch_id')
        ));
    }

    /**
     * Mark purchase order as received
     *
     * @param MarkAsReceivedPurchaseRequest $request
     * @param int $id
     * @return JsonResponse
     * @throws Throwable
     */
    public function markAsReceived(MarkAsReceivedPurchaseRequest $request, int $id): JsonResponse
    {
        $this->service->markAsReceived($id, $request->validated());

        return ApiResponse::success(null, __("inventory::messages.purchase_received_successfully"));
    }
}
