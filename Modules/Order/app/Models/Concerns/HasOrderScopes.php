<?php

namespace Modules\Order\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;

/**
 * Query scopes for the Order model. Extracted from Order to keep the model
 * focused; behaviour is identical (scopes resolve the same on the model).
 */
trait HasOrderScopes
{
    /**
     * Scope a query to search across all fields.
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(function (Builder $query) use ($value) {
            $query->like('reference_no', $value)->orLike('order_number', $value);
        });
    }

    /**
     * Scope a query to get all active orders.
     */
    public function scopeActiveOrders(Builder $query): Builder
    {
        return $query->where(function ($query) {
            $query
                ->where(function (Builder $query) {
                    $query->whereNot('payment_status', OrderPaymentStatus::Paid)
                        ->orWhereIn('status', [
                            OrderStatus::Pending,
                            OrderStatus::Confirmed,
                            OrderStatus::Preparing,
                            OrderStatus::Ready,
                            OrderStatus::Served,
                        ]);
                })
                ->whereNotIn('status', [
                    OrderStatus::Cancelled,
                    OrderStatus::Refunded,
                    OrderStatus::Merged,
                ]);
        });
    }

    /**
     * Scope to active order-management rows: orders that still need payment.
     * Kitchen/table flows use activeOrders(); order drawers and dashboard counts
     * use this narrower payment-pending view.
     */
    public function scopePaymentPendingActiveOrders(Builder $query): Builder
    {
        return $query
            ->whereNot('payment_status', OrderPaymentStatus::Paid)
            ->whereNotIn('status', [
                OrderStatus::Cancelled,
                OrderStatus::Refunded,
                OrderStatus::Merged,
                OrderStatus::Completed,
            ]);
    }

    /**
     * Scope a query to get all orders by type.
     */
    public function scopeType(Builder $query, string $type): void
    {
        $query->where('orders.type', $type);
    }

    /**
     * Scope orders owned by a waiter. Older POS orders may have been created by
     * the waiter before waiter_id was persisted, so include that legacy shape.
     */
    public function scopeWaiterId(Builder $query, int|string $waiterId): void
    {
        $waiterId = (int) $waiterId;

        if ($waiterId <= 0) {
            return;
        }

        $query->where(function (Builder $query) use ($waiterId) {
            $query->where('orders.waiter_id', $waiterId)
                ->orWhere(function (Builder $query) use ($waiterId) {
                    $query->whereNull('orders.waiter_id')
                        ->where('orders.created_by', $waiterId);
                });
        });
    }

    /**
     * Scope a query to get orders by operational source.
     */
    public function scopeSource(Builder $query, string $source): void
    {
        if (in_array($source, AggregatorProvider::values(), true)) {
            $query->whereHas(
                'aggregatorOrderMapping.integration',
                fn (Builder $query) => $query->where('provider', $source)
            );

            return;
        }

        $query->where(function (Builder $query) use ($source) {
            match ($source) {
                'whatsapp' => $query->whereHas('whatsAppOrderSession'),
                'partner' => $query->whereHas('partnerApiOrderMapping'),
                'admin', 'direct' => $query
                    ->where('fulfilment->source', 'admin')
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('created_by')
                            ->whereNull('waiter_id')
                            ->whereNull('pos_register_id')
                            ->whereNull('pos_session_id')
                            ->whereDoesntHave('aggregatorOrderMapping')
                            ->whereDoesntHave('partnerApiOrderMapping')
                            ->whereDoesntHave('whatsAppOrderSession')
                            ->where(function (Builder $query) {
                                $query->whereNull('fulfilment->source')
                                    ->orWhere('fulfilment->source', 'admin');
                            });
                    }),
                'waiter_app' => $query
                    ->where('fulfilment->source', 'waiter_app')
                    ->orWhereColumn('created_by', 'waiter_id'),
                'customer_app' => $query->where('fulfilment->source', 'customer_app'),
                'customer_web' => $query
                    ->where('fulfilment->source', 'customer_web')
                    ->orWhere(function (Builder $query) {
                        $query->whereNull('table_id')
                            ->whereNull('pos_register_id')
                            ->whereNull('pos_session_id')
                            ->whereDoesntHave('aggregatorOrderMapping')
                            ->whereDoesntHave('partnerApiOrderMapping')
                            ->whereDoesntHave('whatsAppOrderSession')
                            ->where(function (Builder $query) {
                                $query->whereNull('created_by')
                                    ->orWhereColumn('created_by', 'customer_id');
                            });
                    }),
                'portal' => $query
                    ->whereNull('created_by')
                    ->whereNull('table_id')
                    ->whereDoesntHave('aggregatorOrderMapping')
                    ->whereDoesntHave('partnerApiOrderMapping')
                    ->whereDoesntHave('whatsAppOrderSession'),
                'qr' => $query
                    ->where('fulfilment->source', 'qr')
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('table_id')
                            ->whereNull('pos_register_id')
                            ->whereNull('pos_session_id')
                            ->whereDoesntHave('aggregatorOrderMapping')
                            ->whereDoesntHave('partnerApiOrderMapping')
                            ->whereDoesntHave('whatsAppOrderSession')
                            ->where(function (Builder $query) {
                                $query->whereNull('created_by')
                                    ->orWhereColumn('created_by', 'customer_id');
                            });
                    }),
                'pos' => $query->whereNotNull('pos_register_id')->orWhereNotNull('pos_session_id'),
                default => null,
            };
        });
    }

    /**
     * Scope a query to get all orders completed.
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', OrderStatus::Completed);
    }

    /**
     * Scope a query to get all without canceled orders.
     */
    public function scopeWithoutCanceledOrders(Builder $query): void
    {
        $query->whereNotIn($query->getModel()->qualifyColumn('status'), Order::revenueExcludedStatuses());
    }

    /**
     * Scope a query to dine-in orders that can be merged.
     */
    public function scopeForMerge(Builder $query): void
    {
        $query->where(function ($query) {
            $query
                ->where('type', OrderType::DineIn)
                ->whereNot('payment_status', OrderPaymentStatus::Paid)
                ->whereIn('status', [
                    OrderStatus::Pending,
                    OrderStatus::Confirmed,
                    OrderStatus::Preparing,
                    OrderStatus::Ready,
                    OrderStatus::Served,
                ]);
        });
    }

    /**
     * Scope to orders that should still be visible on the kitchen display.
     */
    public function scopeVisibleForKitchen(Builder $query): void
    {
        $query->where(function ($query) {
            $query->whereNotIn('status', [
                OrderStatus::Ready,
                OrderStatus::Served,
            ])
                ->orWhereHas('statusLogs', function ($log) {
                    $log->whereIn('status', [
                        OrderStatus::Ready,
                        OrderStatus::Served,
                    ])
                        ->where('created_at', '>=', now()->subMinutes(10));
                });
        });
    }

    /**
     * Scope to orders containing products in the given kitchen categories.
     */
    public function scopeForKitchenCategories(Builder $query, array $categoryIds): void
    {
        if (! empty($categoryIds)) {
            $query->whereHas('products', function ($q) use ($categoryIds) {
                $q->whereHas('product.categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            });
        }
    }
}
