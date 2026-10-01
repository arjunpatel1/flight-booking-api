<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Transformers\Api\V1\Kitchen\KitchenOrderResource;
use Modules\Pos\Services\KitchenViewer\KitchenViewerServiceInterface;
use Modules\Support\ApiResponse;
use Throwable;

class KitchenViewerController extends Controller
{
    /**
     * Create a new instance of PosController
     */
    public function __construct(protected KitchenViewerServiceInterface $service) {}

    /**
     * Get Configuration
     */
    public function configuration(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->getConfiguration($request->get('branch_id')));
    }

    /**
     * Get orders
     */
    public function orders(Request $request): JsonResponse
    {
        $data = $this->service->getOrders($request->get('branch_id'));

        return ApiResponse::success([
            'orders' => KitchenOrderResource::collection($data['orders']),
            'last_updated_at' => $data['last_updated_at'],
        ]);
    }

    /**
     * Update Order product status
     *
     * @throws Throwable
     */
    public function updateOrderProductStatus(Request $request, int|string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            // Ownership and current state are checked against the selected
            // order inside the transaction. A global exists rule leaks stale
            // cross-tenant rows and races with another kitchen device.
            'ids.*' => ['integer'],
        ]);

        $this->service->moveOrderProductToNextStatus($orderId, $validated['ids']);

        return ApiResponse::success(['success' => true]);
    }

    public function cancelOrderProducts(Request $request, int|string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->service->cancelOrderProducts($orderId, $validated['ids'], $validated['reason']);

        return ApiResponse::success(['success' => true]);
    }

    public function cancelDelayedOrder(Request $request, int|string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->service->cancelDelayedOrder($orderId, $validated['reason']);

        return ApiResponse::success(['success' => true]);
    }
}
