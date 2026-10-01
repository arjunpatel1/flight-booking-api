<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Order\Enums\OrderType;
use Modules\Pos\Services\OfflineMode\OfflineModeServiceInterface;
use Modules\Support\InputLimit;

class OfflineModeController
{
    public function __construct(
        private readonly OfflineModeServiceInterface $offlineService
    ) {
    }

    /**
     * Check offline mode status.
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'nullable|string|max:120',
        ]);

        $orders = $this->offlineService->getOfflineOrders();
        if (! empty($validated['device_id'])) {
            $orders = $orders->where('device_id', $validated['device_id']);
        }

        return \Modules\Support\ApiResponse::success([
            'is_offline' => $this->offlineService->isOffline(),
            'pending_orders' => $orders->where('sync_status', 'pending')->count(),
            'retrying_orders' => $orders->where('sync_status', 'retrying')->count(),
            'processing_orders' => $orders->where('sync_status', 'processing')->count(),
            'failed_orders' => $orders->where('sync_status', 'failed')->count(),
            'synced_orders' => $orders->where('sync_status', 'synced')->count(),
            'total_orders' => $orders->count(),
            'oldest_pending_at' => $orders
                ->whereIn('sync_status', ['pending', 'retrying', 'processing'])
                ->sortBy('created_at')
                ->first()['created_at'] ?? null,
        ]);
    }

    /**
     * Enable offline mode.
     */
    public function enable(): JsonResponse
    {
        $this->offlineService->enableOfflineMode();

        return \Modules\Support\ApiResponse::success([
            'message' => __('pos::offline_mode.enabled'),
        ]);
    }

    /**
     * Disable offline mode.
     */
    public function disable(): JsonResponse
    {
        $this->offlineService->disableOfflineMode();

        return \Modules\Support\ApiResponse::success([
            'message' => __('pos::offline_mode.disabled'),
        ]);
    }

    /**
     * Store order in offline queue with enhanced security.
     */
    public function storeOrder(Request $request): JsonResponse
    {
        // Add authentication check
        if (!auth()->check()) {
            return response()->json([
                'message' => 'Authentication required for offline order storage.',
            ], 401);
        }

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'table_id' => 'nullable|exists:tables,id',
            'menu_id' => 'nullable|exists:menus,id',
            'register_id' => 'nullable|exists:pos_registers,id',
            'session_id' => 'nullable|exists:pos_sessions,id',
            'type' => ['nullable', Rule::in(OrderType::values())],
            'device_id' => 'nullable|string|max:120|regex:/^[a-zA-Z0-9\-_]+$/',
            'guest_count' => 'nullable|integer|min:1|max:20',
            'notes' => ['nullable', ...InputLimit::text('remark')],
            'currency' => 'nullable|string|size:3',
            // FX rate: bounded but not precision-locked (rates carry more dp than money).
            'currency_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'subtotal' => ['nullable', ...InputLimit::money()],
            'tax_amount' => ['nullable', ...InputLimit::money()],
            'total' => ['nullable', ...InputLimit::money()],
            'items' => ['required', ...InputLimit::lineItems()],
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => ['required', ...InputLimit::quantity(min: 1, integer: true)],
            'items.*.unit_price' => ['required', ...InputLimit::money()],
            'items.*.subtotal' => ['required', ...InputLimit::money()],
            'items.*.tax_total' => ['nullable', ...InputLimit::money()],
            'items.*.total' => ['required', ...InputLimit::money()],
        ]);

        $validated['client_request_id'] = $request->header('Idempotency-Key') ?: $request->input('client_request_id');
        $validated['device_id'] = $validated['device_id']
            ?? (mb_substr((string) $request->header('X-NexDine-Device-Id'), 0, 120) ?: null);

        // Verify device ownership if device_id is provided
        if (!empty($validated['device_id']) && !$this->offlineService->verifyDeviceOwnership(auth()->user(), $validated['device_id'])) {
            return response()->json([
                'message' => 'Invalid device ID or unauthorized device.',
            ], 403);
        }

        $result = $this->offlineService->storeOfflineOrder($validated);

        if (!$result['success']) {
            return \Modules\Support\ApiResponse::errors(
                errors: null,
                message: $result['error'],
                code: 422
            );
        }

        return \Modules\Support\ApiResponse::success([
            'message' => __('pos::offline_mode.order_stored'),
            'order_id' => $result['order_id'],
        ]);
    }

    /**
     * Get offline orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,retrying,processing,failed,synced,all',
            'device_id' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $status = $validated['status'] ?? 'all';
        $orders = $this->offlineService->getOfflineOrders();
        
        // Optimize by filtering in database if possible, otherwise in-memory
        if (! empty($validated['device_id'])) {
            $orders = $orders->where('device_id', $validated['device_id']);
        }

        if ($status === 'pending') {
            $orders = $orders->whereIn('sync_status', ['pending', 'retrying']);
        } elseif ($status === 'synced') {
            $orders = $orders->whereNotNull('synced_at');
        } elseif ($status !== 'all') {
            $orders = $orders->where('sync_status', $status);
        }

        return response()->json([
            'data' => $orders->values(),
        ]);
    }

    /**
     * Sync offline orders.
     */
    public function sync(): JsonResponse
    {
        $result = $this->offlineService->syncOfflineOrders();

        return \Modules\Support\ApiResponse::success([
            'message' => __('pos::offline_mode.sync_completed'),
            'data' => $result,
        ]);
    }

    /**
     * Get offline statistics.
     */
    public function statistics(): JsonResponse
    {
        $statistics = $this->offlineService->getOfflineStatistics();

        return \Modules\Support\ApiResponse::success($statistics);
    }

    /**
     * Generate offline receipt.
     */
    public function receipt(Request $request, string $orderId): JsonResponse
    {
        $result = $this->offlineService->generateOfflineReceipt($orderId);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'data' => $result['receipt'],
        ]);
    }

    /**
     * Delete offline order.
     */
    public function deleteOrder(string $orderId): JsonResponse
    {
        $success = $this->offlineService->deleteOfflineOrder($orderId);

        if (!$success) {
            return response()->json([
                'message' => __('pos::offline_mode.order_not_found'),
            ], 404);
        }

        return response()->json([
            'message' => __('pos::offline_mode.order_deleted'),
        ]);
    }

    /**
     * Clear offline data.
     */
    public function clear(): JsonResponse
    {
        $success = $this->offlineService->clearOfflineData();

        if (!$success) {
            return \Modules\Support\ApiResponse::errors(
                errors: null,
                message: __('pos::offline_mode.clear_failed'),
                code: 500
            );
        }

        return \Modules\Support\ApiResponse::success([
            'message' => __('pos::offline_mode.cleared'),
        ]);
    }
}
