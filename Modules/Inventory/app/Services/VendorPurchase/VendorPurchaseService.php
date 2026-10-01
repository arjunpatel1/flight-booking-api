<?php

namespace Modules\Inventory\Services\VendorPurchase;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Currency\Models\CurrencyRate;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Services\StockMovement\StockMovementServiceInterface;
use Modules\Inventory\Models\Purchase;
use Modules\Inventory\Models\PurchaseItem;
use Modules\Inventory\Models\Ingredient;
use Throwable;

class VendorPurchaseService implements VendorPurchaseServiceInterface
{
    public function __construct(
        private readonly StockMovementServiceInterface $stockMovementService
    ) {
    }

    /** @inheritDoc */
    public function createPurchase(array $data): array
    {
        try {
            DB::beginTransaction();

            $branch = Branch::findOrFail((int) $data['branch_id']);
            $supplierId = $data['supplier_id'] ?? $data['vendor_id'] ?? null;

            $purchase = Purchase::create([
                'supplier_id' => $supplierId,
                'branch_id' => $data['branch_id'],
                'reference_number' => $data['reference_number'] ?? $data['reference_no'] ?? null,
                'expected_at' => $data['expected_at'] ?? $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PurchaseStatus::Draft,
                'currency' => $branch->currency,
                'currency_rate' => CurrencyRate::for($branch->currency),
                'discount' => 0,
                'tax' => 0,
                'sub_total' => 0,
                'total' => 0,
                'created_by' => auth()->user()?->id,
            ]);

            $total = 0;
            $subTotal = 0;
            $taxAmount = 0;

            foreach ($data['items'] as $itemData) {
                Ingredient::findOrFail($itemData['ingredient_id']);
                $unitCost = (float) ($itemData['unit_cost'] ?? $itemData['unit_price'] ?? 0);
                $quantity = (float) $itemData['quantity'];
                
                $itemTotal = $unitCost * $quantity;
                $itemTax = $itemData['tax_amount'] ?? 0;

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'ingredient_id' => $itemData['ingredient_id'],
                    'quantity' => $quantity,
                    'received_quantity' => 0,
                    'currency' => $branch->currency,
                    'currency_rate' => CurrencyRate::for($branch->currency),
                    'unit_cost' => $unitCost,
                    'line_total' => $itemTotal,
                ]);

                $total += $itemTotal;
                $subTotal += $itemTotal;
                $taxAmount += $itemTax;
            }

            $purchase->update([
                'sub_total' => $subTotal,
                'tax' => $taxAmount,
                'total' => $total,
                'status' => PurchaseStatus::Pending,
            ]);

            DB::commit();

            return [
                'success' => true,
                'purchase' => $purchase,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function updatePurchaseStatus(int $purchaseId, PurchaseStatus $status, ?string $notes = null): array
    {
        try {
            $purchase = Purchase::findOrFail($purchaseId);

            if (!$this->canUpdateStatus($purchase, $status)) {
                return [
                    'success' => false,
                    'error' => 'Cannot update purchase status from ' . $purchase->status->value . ' to ' . $status->value,
                ];
            }

            $updateData = [
                'status' => $status,
            ];

            if ($notes) {
                $existingNotes = $purchase->notes ?? '';
                $updateData['notes'] = $existingNotes . "\n" . $notes;
            }

            $purchase->update($updateData);

            return [
                'success' => true,
                'purchase' => $purchase,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function receivePurchase(int $purchaseId, array $items): array
    {
        try {
            DB::beginTransaction();

            $purchase = Purchase::with(['items.ingredient'])->findOrFail($purchaseId);

            if (!in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::PartiallyReceived], true)) {
                return [
                    'success' => false,
                    'error' => 'Purchase cannot be received in current status: ' . $purchase->status->value,
                ];
            }

            foreach ($items as $itemData) {
                $itemId = (int) ($itemData['id'] ?? $itemData['purchase_item_id']);
                $purchaseItem = $purchase->items->first(fn($item) => (int) $item->id === $itemId);
                
                if (!$purchaseItem) {
                    continue;
                }

                $receivedQuantity = $itemData['received_quantity'];
                $receivedQuantity = min($receivedQuantity, max(0, $purchaseItem->quantity - $purchaseItem->received_quantity));
                if ($receivedQuantity <= 0) {
                    continue;
                }
                $purchaseItem->update([
                    'received_quantity' => $purchaseItem->received_quantity + $receivedQuantity,
                ]);

                // Update stock
                if ($receivedQuantity > 0) {
                    $this->stockMovementService->store([
                        'branch_id' => $purchase->branch_id,
                        'ingredient_id' => $purchaseItem->ingredient_id,
                        'type' => StockMovementType::In,
                        'source_id' => $purchase->id,
                        'source_type' => Purchase::class,
                        'quantity' => $receivedQuantity,
                        'note' => "Purchase receipt: {$purchase->reference_number}",
                    ]);
                }

            }

            $purchase->load('items');
            $anyReceived = $purchase->items->contains(fn($item) => (float) $item->received_quantity > 0);
            $allReceived = $purchase->items->every(fn($item) => (float) $item->received_quantity >= (float) $item->quantity);
            $newStatus = match (true) {
                $allReceived => PurchaseStatus::Received,
                $anyReceived => PurchaseStatus::PartiallyReceived,
                default => PurchaseStatus::Pending,
            };

            $purchase->update([
                'status' => $newStatus,
            ]);

            DB::commit();

            return [
                'success' => true,
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function getPurchaseOrders(?int $branchId = null, array $filters = []): Collection
    {
        $query = Purchase::with(['supplier', 'items.ingredient.unit', 'branch'])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when(isset($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['supplier_id']), fn($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(isset($filters['date_from']), fn($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderBy('created_at', 'desc');

        return $query->get();
    }

    /** @inheritDoc */
    public function getVendorAnalysis(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = Purchase::with('supplier')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($startDate, fn($q) => $q->whereDate('created_at', '>=', $startDate))
            ->when($endDate, fn($q) => $q->whereDate('created_at', '<=', $endDate));

        $purchases = $query->get();

        $vendorAnalysis = $purchases->groupBy('supplier_id')->map(function ($vendorPurchases) {
            $vendor = $vendorPurchases->first()->supplier;
            $totalAmount = $this->sumPurchaseTotals($vendorPurchases);
            $totalQuantity = $vendorPurchases->sum(function ($purchase) {
                return $purchase->items->sum('quantity');
            });

            return [
                'vendor_id' => $vendor?->id,
                'vendor_name' => $vendor?->name,
                'total_purchases' => $vendorPurchases->count(),
                'total_amount' => $totalAmount,
                'total_quantity' => $totalQuantity,
                'average_order_value' => $vendorPurchases->count() > 0 ? $totalAmount / $vendorPurchases->count() : 0,
                'last_order_date' => $vendorPurchases->max('created_at'),
            ];
        })->values();

        return [
            'vendor_analysis' => $vendorAnalysis,
            'total_vendors' => $vendorAnalysis->count(),
            'grand_total' => $this->sumPurchaseTotals($purchases),
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];
    }

    /** @inheritDoc */
    public function getPurchaseStatistics(?int $branchId = null, ?string $period = null): array
    {
        $query = Purchase::when($branchId, fn($q) => $q->where('branch_id', $branchId));

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year);
                break;
            case 'year':
                $query->whereYear('created_at', now()->year);
                break;
        }

        $purchases = $query->get();

        $totalAmount = $this->sumPurchaseTotals($purchases);

        return [
            'total_purchases' => $purchases->count(),
            'total_amount' => $totalAmount,
            'pending_purchases' => $purchases->where('status', PurchaseStatus::Pending)->count(),
            'received_purchases' => $purchases->where('status', PurchaseStatus::Received)->count(),
            'partially_received_purchases' => $purchases->where('status', PurchaseStatus::PartiallyReceived)->count(),
            'average_purchase_value' => $purchases->count() > 0 ? $totalAmount / $purchases->count() : 0,
            'period' => $period ?? 'all_time',
        ];
    }

    /** @inheritDoc */
    public function generatePurchaseReport(array $filters): array
    {
        $purchases = $this->getPurchaseOrders(
            $filters['branch_id'] ?? null,
            $filters
        );

        return [
            'purchases' => $purchases->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'reference_no' => $purchase->reference_number,
                    'vendor_name' => $purchase->supplier?->name,
                    'order_date' => $purchase->created_at,
                    'expected_delivery_date' => $purchase->expected_at,
                    'status' => $purchase->status->value,
                    'status_label' => $purchase->status->toTrans()['label'] ?? $purchase->status->value,
                    'total' => $purchase->total,
                    'items_count' => $purchase->items->count(),
                    'notes' => $purchase->notes,
                ];
            }),
            'summary' => [
                'total_purchases' => $purchases->count(),
                'total_amount' => $this->sumPurchaseTotals($purchases),
                'pending_amount' => $this->sumPurchaseTotals($purchases->where('status', PurchaseStatus::Pending)),
                'received_amount' => $this->sumPurchaseTotals($purchases->whereIn('status', [PurchaseStatus::Received, PurchaseStatus::PartiallyReceived])),
            ],
            'filters' => $filters,
        ];
    }

    private function canUpdateStatus(Purchase $purchase, PurchaseStatus $newStatus): bool
    {
        $currentStatus = $purchase->status;

        return match ([$currentStatus, $newStatus]) {
            [PurchaseStatus::Draft, PurchaseStatus::Pending] => true,
            [PurchaseStatus::Draft, PurchaseStatus::Cancelled] => true,
            [PurchaseStatus::Pending, PurchaseStatus::PartiallyReceived] => true,
            [PurchaseStatus::Pending, PurchaseStatus::Received] => true,
            [PurchaseStatus::Pending, PurchaseStatus::Cancelled] => true,
            [PurchaseStatus::PartiallyReceived, PurchaseStatus::Received] => true,
            [PurchaseStatus::PartiallyReceived, PurchaseStatus::Cancelled] => true,
            default => false,
        };
    }

    /** Money is an immutable value object; Collection::sum cannot aggregate it. */
    private function sumPurchaseTotals(Collection $purchases): float
    {
        return (float) $purchases->sum(
            fn (Purchase $purchase) => (float) ($purchase->total?->amount() ?? 0)
        );
    }
}
