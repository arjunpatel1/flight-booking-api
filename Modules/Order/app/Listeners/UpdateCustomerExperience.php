<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Services\Loyalty\LoyaltyServiceInterface;
use Modules\Menu\Models\OnlineMenu;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderUpdateStatus;
use Throwable;

class UpdateCustomerExperience
{
    public function handle(OrderCreated|OrderUpdateStatus $event): void
    {
        $order = $event->order;
        if (! $order->customer_id) {
            return;
        }

        try {
            $tenantId = (int) $order->branch->tenant_id;
            $slug = OnlineMenu::query()->withoutGlobalActive()->withOutGlobalBranchPermission()
                ->where('branch_id', $order->branch_id)->whereNotNull('slug')->value('slug');
            $actionUrl = $slug
                ? '/online-menu/'.rawurlencode((string) $slug).'/orders/'.rawurlencode((string) $order->reference_no)
                : null;
            $isCreated = $event instanceof OrderCreated;
            $status = $isCreated ? $order->status->value : $event->status->value;
            $type = $isCreated ? 'order_created' : 'order_status';

            $alreadyExists = DB::table('customer_notifications')
                ->where('tenant_id', $tenantId)->where('customer_id', $order->customer_id)
                ->where('type', $type)->where('data->order_reference', $order->reference_no)
                ->when(! $isCreated, fn ($query) => $query->where('data->status', $status))
                ->exists();
            if (! $alreadyExists) {
                DB::table('customer_notifications')->insert([
                    'reference' => (string) Str::uuid(), 'tenant_id' => $tenantId,
                    'customer_id' => $order->customer_id, 'type' => $type,
                    'title' => $isCreated ? 'Order '.$order->order_number.' received' : 'Order '.$order->order_number.' updated',
                    'message' => $isCreated
                        ? 'Your order was received. Open it to follow payment, kitchen, and delivery updates.'
                        : 'Your order is now '.str_replace('_', ' ', $status).'.',
                    'action_url' => $actionUrl,
                    'data' => json_encode(['order_reference' => $order->reference_no, 'status' => $status]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            if ($isCreated) {
                return;
            }

            if ($event->status === OrderStatus::Refunded) {
                DB::transaction(function () use ($order) {
                    $payment = DB::table('customer_wallet_transactions')->where('order_id', $order->id)->where('type', 'order_payment')->first();
                    $refundKey = 'wallet-refund-order-'.$order->id;
                    if (! $payment || DB::table('customer_wallet_transactions')->where('tenant_id', $payment->tenant_id)->where('idempotency_key', $refundKey)->exists()) {
                        return;
                    }
                    $account = DB::table('customer_wallet_accounts')->where('tenant_id', $payment->tenant_id)
                        ->where('customer_id', $payment->customer_id)->where('currency', $payment->currency)->lockForUpdate()->first();
                    if (! $account) {
                        return;
                    }
                    $returned = (float) ($order->getRawOriginal('return_amount') ?: $payment->amount);
                    $amount = min((float) $payment->amount, $returned);
                    $balanceAfter = (float) $account->balance + $amount;
                    DB::table('customer_wallet_accounts')->where('id', $account->id)->update(['balance' => $balanceAfter, 'updated_at' => now()]);
                    DB::table('customer_wallet_transactions')->insert([
                        'reference' => (string) Str::uuid(), 'tenant_id' => $payment->tenant_id, 'customer_id' => $payment->customer_id,
                        'order_id' => $order->id, 'type' => 'refund', 'direction' => 'credit', 'amount' => $amount,
                        'balance_after' => $balanceAfter, 'currency' => $payment->currency, 'idempotency_key' => $refundKey,
                        'description' => 'Refund for order '.$order->reference_no, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            }

            if ($event->status !== OrderStatus::Completed) {
                return;
            }
            DB::transaction(function () use ($order) {
                $referral = DB::table('customer_referrals')->where('tenant_id', $order->branch->tenant_id)
                    ->where('referred_customer_id', $order->customer_id)->whereNull('rewarded_at')->lockForUpdate()->first();
                if (! $referral) {
                    return;
                }
                $loyaltyCustomer = LoyaltyCustomer::query()->where('customer_id', $referral->referrer_customer_id)->first();
                if (! $loyaltyCustomer) {
                    return;
                }
                app(LoyaltyServiceInterface::class)->adjustPoints($loyaltyCustomer, (int) $referral->reward_points, 'Customer referral reward');
                DB::table('customer_referrals')->where('id', $referral->id)->update([
                    'qualifying_order_id' => $order->id, 'status' => 'rewarded',
                    'qualified_at' => now(), 'rewarded_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Log::error('Customer experience update failed.', ['order_id' => $order->id, 'error' => $exception->getMessage()]);
        }
    }
}
