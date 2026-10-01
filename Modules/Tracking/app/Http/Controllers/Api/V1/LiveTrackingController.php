<?php

namespace Modules\Tracking\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Transformers\Api\V1\OrderResource;
use Modules\Support\ApiResponse;
use Modules\Tracking\Http\Requests\Api\V1\RejectLiveOrderRequest;
use Modules\Tracking\Services\LiveTrackingServiceInterface;

class LiveTrackingController extends Controller
{
    public function __construct(private readonly LiveTrackingServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->activeOrders($request->get('filters', [])),
            resource: OrderResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null,
        );
    }

    public function show(int|string $trackingId): JsonResponse
    {
        return ApiResponse::success($this->service->show($trackingId));
    }

    public function pendingCount(): JsonResponse
    {
        abort_unless(
            Gate::allows('admin.live_tracking.index') || Gate::allows('admin.orders.index'),
            403
        );

        return ApiResponse::success([
            'pending_count' => $this->service->pendingCount(),
        ]);
    }

    public function rejectMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->rejectMeta());
    }

    public function accept(int|string $trackingId): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->accept($trackingId),
            message: __('tracking::tracking.order_accepted')
        );
    }

    public function reject(RejectLiveOrderRequest $request, int|string $trackingId): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->reject($trackingId, $request->validated()),
            message: __('tracking::tracking.order_rejected')
        );
    }
}
