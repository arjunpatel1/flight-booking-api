<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Pos\Services\Pos\PosServiceInterface;
use Modules\Support\ApiResponse;
use Throwable;

class PosController extends Controller
{
    /**
     * Create a new instance of PosController
     *
     * @param PosServiceInterface $service
     * @param OrderServiceInterface $orderService
     */
    public function __construct(protected PosServiceInterface   $service,
                                protected OrderServiceInterface $orderService)
    {
    }

    /**
     * This method retrieves and returns a list of Pos models.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success($this->service->get());
    }

    /**
     * Get kitchen Viewer data
     *
     * @return JsonResponse
     */
    public function kitchenViewer(): JsonResponse
    {
        return ApiResponse::success($this->service->kitchenViewer());
    }

    /**
     * Kitchen move to next status
     *
     * @param int|string $orderId
     * @return JsonResponse
     * @throws Throwable
     */
    public function kitchenMoveToNextStatus(int|string $orderId, int $itemId): JsonResponse
    {
        $newStatus = $this->orderService->kitchenMoveToNextStatus($orderId, $itemId);

        return ApiResponse::success(message: __("order::messages.order_update_status_to_successfully", ['status' => $newStatus->trans()]));
    }
}
