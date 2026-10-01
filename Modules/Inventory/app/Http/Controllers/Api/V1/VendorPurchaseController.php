<?php

namespace Modules\Inventory\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\VendorPurchase\VendorPurchaseServiceInterface;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Transformers\Api\V1\PurchaseResource;

class VendorPurchaseController
{
    public function __construct(
        private readonly VendorPurchaseServiceInterface $purchaseService
    ) {
    }

    /**
     * Create a new purchase order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required_without:vendor_id|exists:suppliers,id',
            'vendor_id' => 'required_without:supplier_id|exists:suppliers,id',
            'branch_id' => 'required|exists:branches,id',
            'reference_number' => 'nullable|string|max:50',
            'reference_no' => 'nullable|string|max:50',
            'expected_at' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'required_without:items.*.unit_price|numeric|min:0',
            'items.*.unit_price' => 'required_without:items.*.unit_cost|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_amount' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:200',
        ]);

        $result = $this->purchaseService->createPurchase($validated);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => __('inventory::purchases.created_successfully'),
            'body' => $this->serializePurchase($result['purchase']),
        ]);
    }

    /**
     * Update purchase order status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', array_column(PurchaseStatus::cases(), 'value')),
            'notes' => 'nullable|string|max:500',
        ]);

        $result = $this->purchaseService->updatePurchaseStatus(
            $id,
            PurchaseStatus::from($validated['status']),
            $validated['notes'] ?? null
        );

        if (!$result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => __('inventory::purchases.status_updated'),
            'data' => $result['purchase'],
        ]);
    }

    /**
     * Receive purchase order items.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required_without:items.*.purchase_item_id|exists:purchase_items,id',
            'items.*.purchase_item_id' => 'required_without:items.*.id|exists:purchase_items,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:200',
        ]);

        $result = $this->purchaseService->receivePurchase($id, $validated['items']);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['error'],
            ], 422);
        }

        return response()->json([
            'message' => __('inventory::purchases.received_successfully'),
        ]);
    }

    /**
     * Get purchase orders list.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:' . implode(',', array_column(PurchaseStatus::cases(), 'value')),
            'supplier_id' => 'nullable|exists:suppliers,id',
            'vendor_id' => 'nullable|exists:suppliers,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $filters = $this->normalizeFilters($request, $validated);

        $purchases = $this->purchaseService->getPurchaseOrders(
            $filters['branch_id'] ?? null,
            $filters
        );

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(10, (int) $request->integer('per_page', 10)));
        $total = $purchases->count();
        $paged = $purchases->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'body' => [
                'data' => $paged->map(fn($purchase) => $this->serializePurchase($purchase))->values(),
                'pagination' => [
                    'current_page' => $page,
                    'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : 0,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'to' => min($page * $perPage, $total),
                    'total' => $total,
                ],
            ],
        ]);
    }

    /**
     * Get single purchase order.
     */
    public function show(int $id): JsonResponse
    {
        $purchases = $this->purchaseService->getPurchaseOrders();
        $purchase = $purchases->firstWhere('id', $id);

        if (!$purchase) {
            return response()->json([
                'message' => __('inventory::purchases.not_found'),
            ], 404);
        }

        return response()->json([
            'body' => $this->serializePurchase($purchase, true),
        ]);
    }

    /**
     * Get vendor analysis.
     */
    public function vendorAnalysis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $analysis = $this->purchaseService->getVendorAnalysis(
            $validated['branch_id'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null
        );

        return response()->json([
            'data' => $analysis,
        ]);
    }

    /**
     * Get purchase statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'period' => 'nullable|in:today,week,month,year',
        ]);

        $statistics = $this->purchaseService->getPurchaseStatistics(
            $validated['branch_id'] ?? null,
            $validated['period'] ?? null
        );

        return response()->json([
            'data' => $statistics,
        ]);
    }

    /**
     * Generate purchase report.
     */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:' . implode(',', array_column(PurchaseStatus::cases(), 'value')),
            'supplier_id' => 'nullable|exists:suppliers,id',
            'vendor_id' => 'nullable|exists:suppliers,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'format' => 'nullable|in:json,csv,excel',
        ]);

        $report = $this->purchaseService->generatePurchaseReport($validated);

        return response()->json([
            'data' => $report,
        ]);
    }

    private function normalizeFilters(Request $request, array $validated): array
    {
        $filters = array_filter($validated, fn($value) => $value !== null && $value !== '');
        // SmartDataTable uses `filter[field]`, while older clients use
        // `filters[field]`. Accept both contracts so status/date filters work
        // consistently across the registry and reports.
        $tableFilters = array_filter([
            ...(array) $request->get('filters', []),
            ...(array) $request->get('filter', []),
        ], fn($value) => $value !== null && $value !== '');

        if (isset($tableFilters['from'])) {
            $filters['date_from'] = $tableFilters['from'];
        }
        if (isset($tableFilters['to'])) {
            $filters['date_to'] = $tableFilters['to'];
        }
        if (isset($tableFilters['status'])) {
            $filters['status'] = $tableFilters['status'];
        }
        if (isset($tableFilters['supplier_id'])) {
            $filters['supplier_id'] = $tableFilters['supplier_id'];
        }
        if (isset($tableFilters['branch_id'])) {
            $filters['branch_id'] = $tableFilters['branch_id'];
        }
        if (isset($filters['vendor_id']) && !isset($filters['supplier_id'])) {
            $filters['supplier_id'] = $filters['vendor_id'];
        }

        return $filters;
    }

    private function serializePurchase($purchase, bool $withItems = false): array
    {
        $resource = (new PurchaseResource($purchase))->resolve(request());
        $resource['vendor_name'] = $resource['supplier']['name'] ?? null;
        $resource['supplier_name'] = $resource['supplier']['name'] ?? null;
        $resource['reference_no'] = $resource['reference_number'] ?? null;
        $resource['date'] = $resource['expected_at'] ?? $purchase->created_at?->toDateString();
        // SmartDataTable needs a single money object. PurchaseResource keeps
        // original/converted values for detail views, so expose the original
        // amount explicitly for the list column.
        $resource['total_amount'] = $purchase->total?->toArray() ?? [
            'amount' => 0,
            'formatted' => '0',
            'currency' => $purchase->currency,
        ];

        if ($withItems) {
            $resource['notes'] = $purchase->notes;
            $resource['items'] = $purchase->items->map(fn($item) => [
                'id' => $item->id,
                'ingredient' => [
                    'id' => $item->ingredient_id,
                    'name' => $item->ingredient?->name,
                    'unit' => $item->ingredient?->unit ? [
                        'name' => $item->ingredient->unit->name,
                        'symbol' => is_array($item->ingredient->unit->symbol)
                            ? $item->ingredient->unit->symbol
                            : ucfirst($item->ingredient->unit->symbol),
                    ] : null,
                ],
                'name' => $item->ingredient?->name,
                'quantity' => $item->quantity,
                'received_quantity' => $item->received_quantity,
                'unit_price' => $item->unit_cost,
                'unit_cost' => $item->unit_cost,
                'total' => $item->line_total,
                'line_total' => $item->line_total,
            ])->values();
        }

        return $resource;
    }
}
