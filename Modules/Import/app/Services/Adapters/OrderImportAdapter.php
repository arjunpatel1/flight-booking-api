<?php

namespace Modules\Import\Services\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Modules\Import\Contracts\ImportAdapter;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Product\Models\Product;

class OrderImportAdapter implements ImportAdapter
{
    public function import(array $row, array $options = []): void
    {
        $branchId = $row['branch_id'] ?? $options['branch_id'] ?? null;
        $productIdentifiers = $this->productIdentifiers($row);
        $quantities = $this->values($row['quantities'] ?? null);

        $data = [
            'branch_id' => $branchId,
            'customer_id' => $row['customer_id'] ?? null,
            'waiter_id' => $row['waiter_id'] ?? null,
            'table_id' => $row['table_id'] ?? null,
            'type' => $row['type'] ?: OrderType::DineIn->value,
            'status' => $row['status'] ?: OrderStatus::Pending->value,
            'payment_status' => $row['payment_status'] ?: OrderPaymentStatus::Unpaid->value,
            'order_date' => $row['order_date'] ?: now()->toDateString(),
            'guest_count' => $row['guest_count'] ?: 1,
            'notes' => $row['notes'] ?? null,
            'product_identifiers' => $productIdentifiers,
            'quantities' => $quantities,
        ];

        Validator::make($data, [
            'branch_id' => ['required', 'integer', 'exists:branches,id,deleted_at,NULL'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id,deleted_at,NULL'],
            'waiter_id' => ['nullable', 'integer', 'exists:users,id,deleted_at,NULL'],
            'table_id' => ['nullable', 'integer', 'exists:tables,id,deleted_at,NULL'],
            'type' => ['required', Rule::enum(OrderType::class)],
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'payment_status' => ['required', Rule::enum(OrderPaymentStatus::class)],
            'order_date' => ['required', 'date'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'product_identifiers' => ['required', 'array', 'min:1'],
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['required', 'integer', 'min:1', 'max:999'],
        ])->validate();

        if (count($productIdentifiers) !== count($quantities)) {
            throw ValidationException::withMessages([
                'quantities' => __('import::imports.errors.quantity_count_mismatch'),
            ]);
        }

        $products = $this->products($productIdentifiers);

        Validator::make(['products' => $products], [
            'products' => ['array', 'size:' . count($productIdentifiers)],
        ], [
            'products.size' => __('import::imports.errors.products_not_found'),
        ])->validate();

        DB::transaction(function () use ($data, $products, $quantities) {
            $currency = setting('default_currency', 'USD');
            $subtotal = 0;
            $costPrice = 0;

            $order = Order::query()->create([
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'] ?: null,
                'waiter_id' => $data['waiter_id'] ?: null,
                'table_id' => $data['table_id'] ?: null,
                'status' => $data['status'],
                'type' => $data['type'],
                'payment_status' => $data['payment_status'],
                'currency' => $currency,
                'currency_rate' => 1,
                'subtotal' => 0,
                'total' => 0,
                'cost_price' => 0,
                'revenue' => 0,
                'guest_count' => $data['guest_count'],
                'notes' => $data['notes'],
                'order_date' => $data['order_date'],
            ]);

            foreach ($products as $index => $product) {
                $quantity = (int) $quantities[$index];
                $unitPrice = (float) $product->getRawOriginal('price');
                $lineSubtotal = $unitPrice * $quantity;
                $lineCost = 0;

                OrderProduct::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'currency' => $currency,
                    'currency_rate' => 1,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                    'tax_total' => 0,
                    'total' => $lineSubtotal,
                    'cost_price' => $lineCost,
                    'revenue' => $lineSubtotal - $lineCost,
                    'status' => OrderProductStatus::Pending,
                ]);

                $subtotal += $lineSubtotal;
                $costPrice += $lineCost;
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'cost_price' => $costPrice,
                'revenue' => $subtotal - $costPrice,
            ]);
        });
    }

    private function productIdentifiers(array $row): array
    {
        $ids = $this->values($row['product_ids'] ?? null);

        if (!empty($ids)) {
            return $ids;
        }

        return $this->values($row['product_skus'] ?? null);
    }

    private function products(array $identifiers)
    {
        $numeric = collect($identifiers)->every(fn($identifier) => is_numeric($identifier));

        return Product::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->whereNull('deleted_at')
            ->when(
                $numeric,
                fn($query) => $query->whereIn('id', array_map('intval', $identifiers)),
                fn($query) => $query->whereIn('sku', $identifiers)
            )
            ->get()
            ->sortBy(fn(Product $product) => array_search($numeric ? $product->id : $product->sku, $identifiers))
            ->values();
    }

    private function values(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn($item) => $item !== null && $item !== ''));
        }

        return collect(explode(',', (string) $value))
            ->map(fn(string $item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
