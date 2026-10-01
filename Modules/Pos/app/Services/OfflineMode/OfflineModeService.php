<?php

namespace Modules\Pos\Services\OfflineMode;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Pos\Models\PosOfflineOrder;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Product\Models\Product;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;
use Modules\User\Models\User;
use Throwable;

class OfflineModeService implements OfflineModeServiceInterface
{
    private const OFFLINE_ORDERS_FILE = 'offline_orders.json';
    private const OFFLINE_SETTINGS_FILE = 'offline_settings.json';

    /** @inheritDoc */
    public function verifyDeviceOwnership(User $user, string $deviceId): bool
    {
        $device = PosTerminalDevice::withoutGlobalScopes()
            ->withTrashed()
            ->where('device_id', $deviceId)
            ->first();

        return ! $device
            || is_null($device->created_by)
            || (int) $device->created_by === (int) $user->id;
    }

    /** @inheritDoc */
    public function isOffline(): bool
    {
        $settings = $this->getOfflineSettings();
        return $settings['enabled'] ?? false;
    }

    /** @inheritDoc */
    public function enableOfflineMode(): void
    {
        $settings = $this->getOfflineSettings();
        $settings['enabled'] = true;
        $settings['enabled_at'] = now()->toISOString();
        $this->saveOfflineSettings($settings);
    }

    /** @inheritDoc */
    public function disableOfflineMode(): void
    {
        $settings = $this->getOfflineSettings();
        $settings['enabled'] = false;
        $settings['disabled_at'] = now()->toISOString();
        $this->saveOfflineSettings($settings);
    }

    /** @inheritDoc */
    public function storeOfflineOrder(array $orderData): array
    {
        try {
            if ($this->hasDatabaseQueue()) {
                return $this->storeDatabaseOfflineOrder($orderData);
            }

            $orders = $this->getOfflineOrders();
            $clientRequestId = $orderData['client_request_id'] ?? null;

            if ($clientRequestId) {
                $existing = $orders->firstWhere('client_request_id', $clientRequestId);
                if ($existing) {
                    return [
                        'success' => true,
                        'order_id' => $existing['id'],
                    ];
                }
            }

            $orderId = 'offline_' . now()->timestamp . '_' . uniqid();
            $orderType = OrderType::tryFrom($orderData['type'] ?? '') ?? (
                !empty($orderData['table_id']) ? OrderType::DineIn : OrderType::Takeaway
            );
            $authoritativeOrder = $this->withAuthoritativeTaxTotals([
                ...$orderData,
                'type' => $orderType->value,
            ]);
            
            $offlineOrder = [
                'id' => $orderId,
                'client_request_id' => $clientRequestId ?: $orderId,
                'reference_no' => $orderData['reference_no'] ?? 'OFFLINE-' . now()->format('YmdHis') . '-' . substr($orderId, -6),
                'order_number' => $orderData['order_number'] ?? $orderId,
                'branch_id' => $orderData['branch_id'],
                'table_id' => $orderData['table_id'] ?? null,
                'register_id' => $orderData['register_id'] ?? null,
                'session_id' => $orderData['session_id'] ?? null,
                'guest_count' => $orderData['guest_count'] ?? 1,
                'notes' => $orderData['notes'] ?? null,
                'status' => OrderStatus::Pending->value,
                'sync_status' => 'pending',
                'type' => $orderType->value,
                'currency' => $authoritativeOrder['currency'],
                'currency_rate' => $authoritativeOrder['currency_rate'],
                'subtotal' => $authoritativeOrder['subtotal'],
                'tax_amount' => $authoritativeOrder['tax_amount'],
                'total' => $authoritativeOrder['total'],
                'items' => $authoritativeOrder['items'],
                'taxes' => $authoritativeOrder['taxes'],
                'created_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ];

            $orders->push($offlineOrder);
            $this->saveOfflineOrders($orders);

            return [
                'success' => true,
                'order_id' => $orderId,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function getOfflineOrders(): Collection
    {
        if ($this->hasDatabaseQueue()) {
            return PosOfflineOrder::query()
                ->latest()
                ->get()
                ->map(fn(PosOfflineOrder $order) => $order->toQueuePayload());
        }

        if (! Storage::disk('local')->exists(self::OFFLINE_ORDERS_FILE)) {
            return collect();
        }

        $ordersData = Storage::disk('local')->get(self::OFFLINE_ORDERS_FILE);
        $orders = json_decode($ordersData, true) ?? [];
        
        return collect($orders);
    }

    /** @inheritDoc */
    public function getPendingOfflineOrders(): Collection
    {
        if ($this->hasDatabaseQueue()) {
            return PosOfflineOrder::query()
                ->whereNull('synced_at')
                ->whereIn('sync_status', ['pending', 'retrying'])
                ->whereIn('status', [
                    OrderStatus::Pending->value,
                    OrderStatus::Confirmed->value,
                ])
                ->oldest()
                ->get()
                ->map(fn(PosOfflineOrder $order) => $order->toQueuePayload());
        }

        return $this->getOfflineOrders()
            ->filter(fn($order) => ! isset($order['synced_at'])
                && in_array(($order['sync_status'] ?? 'pending'), ['pending', 'retrying'], true)
                && in_array($order['status'], [
                    OrderStatus::Pending->value,
                    OrderStatus::Confirmed->value,
                ], true));
    }

    /** @inheritDoc */
    public function syncOfflineOrders(): array
    {
        if ($this->hasDatabaseQueue()) {
            return $this->syncDatabaseOfflineOrders();
        }

        $pendingOrders = $this->getPendingOfflineOrders();
        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($pendingOrders as $offlineOrder) {
            try {
                DB::transaction(function () use ($offlineOrder) {
                    $this->createSyncedOrder($offlineOrder);
                });

                $this->markOrderAsSynced($offlineOrder['id']);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                $this->markOrderAsFailed($offlineOrder['id'], $e->getMessage());
                $errors[] = [
                    'order_id' => $offlineOrder['id'],
                    'reference_no' => $offlineOrder['reference_no'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /** @inheritDoc */
    public function markOrderAsSynced(string $orderId): bool
    {
        try {
            if ($this->hasDatabaseQueue()) {
                return PosOfflineOrder::query()
                    ->where('offline_id', $orderId)
                    ->update([
                        'sync_status' => 'synced',
                        'locked_at' => null,
                        'last_sync_error' => null,
                        'synced_at' => now(),
                    ]) > 0;
            }

            $orders = $this->getOfflineOrders();
            $updatedOrders = $orders->map(function ($order) use ($orderId) {
                if ($order['id'] === $orderId) {
                    $order['synced_at'] = now()->toISOString();
                    $order['sync_status'] = 'synced';
                }
                return $order;
            });

            $this->saveOfflineOrders($updatedOrders);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function markOrderAsFailed(string $orderId, string $error): bool
    {
        try {
            if ($this->hasDatabaseQueue()) {
                return PosOfflineOrder::query()
                    ->where('offline_id', $orderId)
                    ->update([
                        'sync_status' => 'failed',
                        'locked_at' => null,
                        'last_sync_error' => mb_substr($error, 0, 1000),
                        'last_sync_attempt_at' => now(),
                    ]) > 0;
            }

            $orders = $this->getOfflineOrders();
            $updatedOrders = $orders->map(function ($order) use ($orderId, $error) {
                if ($order['id'] === $orderId) {
                    $order['sync_status'] = 'failed';
                    $order['last_sync_error'] = $error;
                    $order['last_sync_attempt_at'] = now()->toISOString();
                }

                return $order;
            });

            $this->saveOfflineOrders($updatedOrders);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @inheritDoc */
    public function deleteOfflineOrder(string $orderId): bool
    {
        try {
            if ($this->hasDatabaseQueue()) {
                return PosOfflineOrder::query()
                    ->where('offline_id', $orderId)
                    ->delete() > 0;
            }

            $orders = $this->getOfflineOrders();
            $filteredOrders = $orders->filter(fn($order) => $order['id'] !== $orderId);
            $this->saveOfflineOrders($filteredOrders);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @inheritDoc */
    public function getOfflineStatistics(): array
    {
        $orders = $this->getOfflineOrders();
        $totalOrders = $orders->count();
        $pendingOrders = $this->getPendingOfflineOrders()->count();
        $syncedOrders = $orders->filter(fn($order) => isset($order['synced_at']))->count();
        $totalAmount = $orders->sum('total');

        return [
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'synced_orders' => $syncedOrders,
            'failed_orders' => $totalOrders - $pendingOrders - $syncedOrders,
            'total_amount' => $totalAmount,
            'average_order_value' => $totalOrders > 0 ? $totalAmount / $totalOrders : 0,
        ];
    }

    /** @inheritDoc */
    public function clearOfflineData(): bool
    {
        try {
            if ($this->hasDatabaseQueue()) {
                PosOfflineOrder::query()->delete();
            }

            Storage::disk('local')->delete(self::OFFLINE_ORDERS_FILE);
            Storage::disk('local')->delete(self::OFFLINE_SETTINGS_FILE);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @inheritDoc */
    public function generateOfflineReceipt(string $orderId): array
    {
        try {
            $orders = $this->getOfflineOrders();
            $order = $orders->firstWhere('id', $orderId);

            if (!$order) {
                return [
                    'success' => false,
                    'error' => 'Order not found',
                ];
            }

            $receipt = [
                'order_id' => $order['id'],
                'reference_no' => $order['reference_no'],
                'customer_name' => $order['customer_name'] ?? null,
                'customer_mobile' => $order['customer_mobile'] ?? null,
                'table' => $order['table_id'] ? 'Table ' . $order['table_id'] : 'Takeaway',
                'items' => collect($order['items'])->map(function ($item) {
                    return [
                        'name' => $item['name'] ?? 'Item',
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total'],
                    ];
                })->toArray(),
                'subtotal' => $order['subtotal'],
                'tax_amount' => $order['tax_amount'],
                'total' => $order['total'],
                'currency' => $order['currency'],
                'created_at' => $order['created_at'],
                'notes' => $order['notes'],
            ];

            return [
                'success' => true,
                'receipt' => $receipt,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function validateOfflineOrder(array $orderData): array
    {
        $errors = [];

        // Validate required fields
        if (empty($orderData['items'])) {
            $errors[] = 'Order must have at least one item';
        }

        // Validate items
        foreach ($orderData['items'] as $index => $item) {
            if (!isset($item['product_id']) || !$item['product_id']) {
                $errors[] = "Item {$index}: Product ID is required";
            }
            if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                $errors[] = "Item {$index}: Quantity must be greater than 0";
            }
            if (!isset($item['unit_price']) || $item['unit_price'] < 0) {
                $errors[] = "Item {$index}: Unit price must be greater than 0";
            }
        }

        // Validate totals
        if (isset($orderData['subtotal']) && $orderData['subtotal'] < 0) {
            $errors[] = 'Subtotal must be greater than or equal to 0';
        }

        if (isset($orderData['total']) && $orderData['total'] < 0) {
            $errors[] = 'Total must be greater than or equal to 0';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function hasDatabaseQueue(): bool
    {
        try {
            return Schema::hasTable('pos_offline_orders');
        } catch (Throwable) {
            return false;
        }
    }

    private function storeDatabaseOfflineOrder(array $orderData): array
    {
        $clientRequestId = $orderData['client_request_id'] ?? null;

        if ($clientRequestId) {
            $existing = PosOfflineOrder::query()
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existing) {
                return [
                    'success' => true,
                    'order_id' => $existing->offline_id,
                ];
            }
        }

        $orderId = 'offline_' . now()->timestamp . '_' . uniqid();
        $orderType = OrderType::tryFrom($orderData['type'] ?? '') ?? (
            !empty($orderData['table_id']) ? OrderType::DineIn : OrderType::Takeaway
        );
        $referenceNo = $orderData['reference_no'] ?? 'OFFLINE-' . now()->format('YmdHis') . '-' . substr($orderId, -6);
        $authoritativeOrder = $this->withAuthoritativeTaxTotals([
            ...$orderData,
            'type' => $orderType->value,
        ]);

        $offlineOrder = PosOfflineOrder::query()->create([
            'created_by' => auth()->id(),
            'offline_id' => $orderId,
            'client_request_id' => $clientRequestId ?: $orderId,
            'device_id' => $orderData['device_id'] ?? null,
            'reference_no' => $referenceNo,
            'order_number' => $orderData['order_number'] ?? $orderId,
            'branch_id' => $orderData['branch_id'],
            'table_id' => $orderData['table_id'] ?? null,
            'pos_register_id' => $orderData['register_id'] ?? null,
            'pos_session_id' => $orderData['session_id'] ?? null,
            'status' => OrderStatus::Pending->value,
            'sync_status' => 'pending',
            'type' => $orderType->value,
            'currency' => $authoritativeOrder['currency'],
            'currency_rate' => $authoritativeOrder['currency_rate'],
            'subtotal' => $authoritativeOrder['subtotal'],
            'tax_amount' => $authoritativeOrder['tax_amount'],
            'total' => $authoritativeOrder['total'],
            'payload' => array_merge($orderData, [
                'id' => $orderId,
                'reference_no' => $referenceNo,
                'order_number' => $orderData['order_number'] ?? $orderId,
                'guest_count' => $orderData['guest_count'] ?? 1,
                'notes' => $orderData['notes'] ?? null,
                'currency' => $authoritativeOrder['currency'],
                'currency_rate' => $authoritativeOrder['currency_rate'],
                'subtotal' => $authoritativeOrder['subtotal'],
                'tax_amount' => $authoritativeOrder['tax_amount'],
                'total' => $authoritativeOrder['total'],
                'items' => $authoritativeOrder['items'],
                'taxes' => $authoritativeOrder['taxes'],
            ]),
        ]);

        return [
            'success' => true,
            'order_id' => $offlineOrder->offline_id,
        ];
    }

    private function syncDatabaseOfflineOrders(): array
    {
        $pendingOrders = PosOfflineOrder::query()
            ->whereNull('synced_at')
            ->whereIn('sync_status', ['pending', 'retrying'])
            ->where(function ($query) {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<', now()->subMinutes(5));
            })
            ->oldest()
            ->get();

        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($pendingOrders as $queuedOrder) {
            $locked = PosOfflineOrder::query()
                ->whereKey($queuedOrder->id)
                ->whereIn('sync_status', ['pending', 'retrying'])
                ->where(function ($query) {
                    $query->whereNull('locked_at')
                        ->orWhere('locked_at', '<', now()->subMinutes(5));
                })
                ->update([
                    'sync_status' => 'processing',
                    'locked_at' => now(),
                    'last_sync_attempt_at' => now(),
                    'attempts' => DB::raw('attempts + 1'),
                ]);

            if ($locked === 0) {
                continue;
            }

            $offlineOrder = $queuedOrder->fresh()->toQueuePayload();

            try {
                DB::transaction(fn() => $this->createSyncedOrder($offlineOrder));
                $this->markOrderAsSynced($offlineOrder['id']);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                $this->markOrderAsFailed($offlineOrder['id'], $e->getMessage());
                $errors[] = [
                    'order_id' => $offlineOrder['id'],
                    'reference_no' => $offlineOrder['reference_no'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function createSyncedOrder(array $offlineOrder): void
    {
        $existing = Order::query()
            ->where('reference_no', $offlineOrder['reference_no'])
            ->first();

        if ($existing) {
            return;
        }

        $offlineOrder = $this->withAuthoritativeTaxTotals($offlineOrder);

        $order = Order::query()->create([
            'reference_no' => $offlineOrder['reference_no'],
            'order_number' => $offlineOrder['order_number'],
            'branch_id' => $offlineOrder['branch_id'],
            'table_id' => $offlineOrder['table_id'],
            'pos_register_id' => $offlineOrder['register_id'] ?? null,
            'pos_session_id' => $offlineOrder['session_id'] ?? null,
            'guest_count' => $offlineOrder['guest_count'] ?? 1,
            'notes' => $offlineOrder['notes'] ?? null,
            'status' => OrderStatus::Confirmed,
            'type' => OrderType::from($offlineOrder['type']),
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => $offlineOrder['currency'],
            'currency_rate' => $offlineOrder['currency_rate'],
            'subtotal' => $offlineOrder['subtotal'],
            'total' => $offlineOrder['total'],
            'due_amount' => $offlineOrder['total'],
            'kitchen_display' => true,
            'order_date' => now(),
            'created_by' => auth()->id(),
        ]);

        foreach ($offlineOrder['items'] as $itemData) {
            $orderProduct = OrderProduct::query()->create([
                'order_id' => $order->id,
                'product_id' => $itemData['product_id'],
                'currency' => $offlineOrder['currency'],
                'currency_rate' => $offlineOrder['currency_rate'],
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'subtotal' => $itemData['subtotal'],
                'tax_total' => $itemData['tax_total'] ?? 0,
                'total' => $itemData['total'],
                'status' => OrderProductStatus::Pending,
                'created_by' => auth()->id(),
            ]);

            $this->storeOrderProductTaxes($orderProduct, $itemData['taxes'] ?? []);
        }

        foreach ($offlineOrder['taxes'] ?? [] as $tax) {
            $order->taxes()->create([
                'tax_id' => $tax['id'],
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'currency' => $tax['currency'],
                'currency_rate' => $tax['currency_rate'],
                'amount' => $tax['amount'],
                'type' => $tax['type'],
                'compound' => $tax['compound'],
            ]);
        }
    }

    private function withAuthoritativeTaxTotals(array $orderData): array
    {
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail($orderData['branch_id']);
        $orderType = OrderType::tryFrom($orderData['type'] ?? '') ?? (
            !empty($orderData['table_id']) ? OrderType::DineIn : OrderType::Takeaway
        );
        $currency = $orderData['currency']
            ?? $branch->currency
            ?? setting('default_currency')
            ?? config('app.currency', env('DEFAULT_CURRENCY', 'INR'));
        $currencyRate = (float) ($orderData['currency_rate'] ?? 1.0);
        $items = collect($orderData['items'] ?? []);
        $productIds = $items
            ->map(fn(array $item) => $item['product_id'] ?? $item['id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $products = Product::query()
            ->with('taxes')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $taxService = app(TaxCalculationService::class);
        $authoritativeItems = [];
        $subtotal = 0.0;
        $taxAmount = 0.0;

        foreach ($items as $item) {
            $productId = $item['product_id'] ?? $item['id'] ?? null;
            /** @var Product|null $product */
            $product = $products->get($productId);

            if (!$product) {
                continue;
            }

            $quantity = max((int) ($item['quantity'] ?? 1), 1);
            $unitPrice = (float) ($item['unit_price'] ?? $product->selling_price->amount());
            $grossSubtotal = $unitPrice * $quantity;
            $taxes = $this->filterTaxesForOrderType($product->taxes, $orderType);
            $calculatedTaxes = $taxService->calculate($grossSubtotal, $taxes);
            $lineSubtotal = $taxService->taxableAmount($grossSubtotal, $taxes);
            $lineTaxTotal = (float) $calculatedTaxes->sum('amount');
            $lineTotal = $lineSubtotal + $lineTaxTotal;

            $authoritativeItems[] = [
                ...$item,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'tax_total' => $lineTaxTotal,
                'total' => $lineTotal,
                'taxes' => $this->formatCalculatedTaxes($calculatedTaxes, $currency, $currencyRate),
            ];

            $subtotal += $lineTotal;
            $taxAmount += $lineTaxTotal;
        }

        $orderTaxes = $this->globalTaxesForOrder($branch, $orderType);
        $calculatedOrderTaxes = $taxService->calculate($subtotal, $orderTaxes);
        $orderTaxRows = $this->formatCalculatedTaxes($calculatedOrderTaxes, $currency, $currencyRate);
        $additiveOrderTaxTotal = (float) $calculatedOrderTaxes
            ->where('additive', true)
            ->sum('amount');

        return [
            ...$orderData,
            'currency' => $currency,
            'currency_rate' => $currencyRate,
            'items' => $authoritativeItems,
            'taxes' => $orderTaxRows,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount + (float) $calculatedOrderTaxes->sum('amount'),
            'total' => $subtotal + $additiveOrderTaxTotal,
        ];
    }

    private function filterTaxesForOrderType(Collection $taxes, OrderType $orderType): Collection
    {
        return $taxes
            ->filter(function (Tax $tax) use ($orderType) {
                $orderTypes = $tax->order_types ?? [];

                return empty($orderTypes) || in_array($orderType->value, $orderTypes, true);
            })
            ->values();
    }

    private function globalTaxesForOrder(Branch $branch, OrderType $orderType): Collection
    {
        $taxRows = Tax::list($branch->id, true)
            ->filter(fn(array $tax) => empty($tax['order_types']) || in_array($orderType->value, $tax['order_types'], true))
            ->values();
        $taxModels = Tax::query()
            ->withOutGlobalBranchPermission()
            ->whereIn('id', $taxRows->pluck('id'))
            ->get()
            ->keyBy('id');

        return $taxRows
            ->map(fn(array $tax) => $taxModels->get($tax['id']))
            ->filter()
            ->values();
    }

    private function formatCalculatedTaxes(Collection $calculatedTaxes, string $currency, float $currencyRate): array
    {
        return $calculatedTaxes
            ->map(function (array $row) use ($currency, $currencyRate) {
                /** @var Tax $tax */
                $tax = $row['tax'];

                return [
                    'id' => $tax->id,
                    'name' => $tax->getTranslations('name') ?: ['en' => $tax->name],
                    'rate' => $tax->rate,
                    'currency' => $currency,
                    'currency_rate' => $currencyRate,
                    'amount' => $row['amount'],
                    'type' => $tax->type->value,
                    'compound' => $tax->compound,
                ];
            })
            ->values()
            ->all();
    }

    private function storeOrderProductTaxes(OrderProduct $orderProduct, array $taxes): void
    {
        foreach ($taxes as $tax) {
            $orderProduct->taxes()->create([
                'order_id' => $orderProduct->order_id,
                'tax_id' => $tax['id'],
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'currency' => $tax['currency'],
                'currency_rate' => $tax['currency_rate'],
                'amount' => $tax['amount'],
                'type' => $tax['type'],
                'compound' => $tax['compound'],
            ]);
        }
    }

    private function getOfflineSettings(): array
    {
        if (! Storage::disk('local')->exists(self::OFFLINE_SETTINGS_FILE)) {
            return [];
        }

        $settingsData = Storage::disk('local')->get(self::OFFLINE_SETTINGS_FILE);
        return json_decode($settingsData, true) ?? [];
    }

    private function saveOfflineSettings(array $settings): void
    {
        Storage::disk('local')->put(self::OFFLINE_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function saveOfflineOrders(Collection $orders): void
    {
        Storage::disk('local')->put(self::OFFLINE_ORDERS_FILE, json_encode($orders->toArray(), JSON_PRETTY_PRINT));
    }
}
