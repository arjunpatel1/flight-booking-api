<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Support\ApiResponse;
use Modules\Order\Delivery\DeliveryLocation;
use Modules\Order\Delivery\DeliveryProvider;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Delivery\PlatformDeliveryCredentials;
use Modules\Order\Delivery\UengageClient;
use Modules\Order\Delivery\UengageStatus;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Jobs\AssignOrderDelivery;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;

class ManualDeliveryDispatchController extends Controller
{
    public function preview(Request $request, string $reference, DeliveryProvider $provider, DeliveryWallet $wallet): JsonResponse
    {
        [$order, $delivery] = $this->eligible($request, $reference);
        $branch = $order->branch()->withoutGlobalScopes()->firstOrFail();
        $pickup = DeliveryLocation::fromAddress(['latitude' => $delivery->pickup_latitude, 'longitude' => $delivery->pickup_longitude]);
        $dropoff = DeliveryLocation::fromAddress((array) data_get($order->fulfilmentDetails(), 'delivery_address', []));
        abort_unless($pickup && $dropoff, 422, 'Both restaurant and customer map locations are required before manual dispatch.');

        try {
            $providerQuotes = collect($provider->quotes($pickup, $dropoff, $order->reference_no, false));
            $quotes = $providerQuotes->filter(fn ($quote) => $quote->serviceable)->sortBy('cost')->values();
        } catch (ProviderUnavailable $exception) {
            abort(503, $exception->getMessage());
        }
        if ($quotes->isEmpty()) {
            $reason = $providerQuotes->pluck('unserviceableReason')->filter()->first();
            abort(422, $reason ?: 'No delivery partner is currently serviceable for this location. Try self delivery or review the customer map pin.');
        }
        $quote = $quotes->first();
        $account = $wallet->account((int) $branch->tenant_id, $branch->currency);

        return ApiResponse::success([
            'order_reference' => $order->reference_no,
            'order_number' => $order->order_number,
            'pickup' => ['label' => $branch->name, 'address' => collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->postal_code])->filter()->join(', '), 'latitude' => $pickup->latitude, 'longitude' => $pickup->longitude],
            'dropoff' => ['label' => (string) data_get($order->fulfilmentDetails(), 'delivery_address.address_line1', 'Customer location'), 'address' => collect([
                data_get($order->fulfilmentDetails(), 'delivery_address.address_line1'), data_get($order->fulfilmentDetails(), 'delivery_address.area'),
                data_get($order->fulfilmentDetails(), 'delivery_address.city'), data_get($order->fulfilmentDetails(), 'delivery_address.postal_code'),
            ])->filter()->join(', '), 'latitude' => $dropoff->latitude, 'longitude' => $dropoff->longitude],
            'distance_km' => $delivery->distance_km,
            'customer_delivery_fee' => (float) $delivery->customer_delivery_fee,
            'provider_charge' => (float) $quote->cost,
            'partner_name' => $quote->partnerName,
            'eta_minutes' => $quote->etaMinutes,
            'currency' => $branch->currency,
            'wallet_available' => (float) $account->available_balance,
            'wallet_sufficient' => (float) $account->available_balance >= (float) $quote->cost,
            'failure_code' => $delivery->failure_code,
            'failure_reason' => $delivery->failure_reason,
        ]);
    }

    public function send(Request $request, string $reference): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        [$order, $delivery] = $this->eligible($request, $reference);
        $redispatch = $delivery->status === DeliveryStatus::Cancelled;
        abort_if(! $redispatch && ($delivery->booking_requested_at || $delivery->external_delivery_id
            || $delivery->status === DeliveryStatus::Investigation), 409,
            'This delivery may already have reached the provider. Reconcile it before any new booking.');

        DB::transaction(function () use ($delivery, $redispatch): void {
            $locked = OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($redispatch) {
                abort_unless($locked->status === DeliveryStatus::Cancelled
                    && filled($locked->external_delivery_id) && $locked->cancelled_at !== null, 409,
                    'The previous provider cancellation is not confirmed.');
                $attemptHistory = [...($locked->attempt_history ?? []), $this->attemptSnapshot($locked)];
                $locked->fill([
                    'attempt_history' => $attemptHistory,
                    'quote_history' => [],
                    'external_delivery_id' => null, 'external_partner_id' => null,
                    'provider_status' => null, 'partner_code' => null, 'partner_name' => null,
                    'rider_name' => null, 'rider_phone' => null, 'delivery_otp' => null,
                    'rider_vehicle' => null, 'tracking_url' => null, 'eta_minutes' => null,
                    'booking_requested_at' => null, 'booking_completed_at' => null,
                    'assigned_at' => null, 'rider_assigned_at' => null,
                    'assignment_deadline_at' => null, 'assignment_escalated_at' => null,
                    'arrived_at_pickup_at' => null, 'picked_up_at' => null,
                    'arrived_at_customer_at' => null, 'delivered_at' => null, 'cancelled_at' => null,
                ]);
            } else {
                abort_if($locked->booking_requested_at || $locked->external_delivery_id, 409,
                    'This delivery has already started provider booking.');
            }
            $locked->fill(['status' => DeliveryStatus::WaitingForAssignment, 'assignment_status' => 'manual_retry_queued', 'failure_code' => null, 'failure_reason' => null,
                'assignment_token' => null, 'assignment_started_at' => null,
                'provider_correlation_id' => null, 'booking_phase' => null,
                'booking_claimed_at' => null])->save();
        });
        DB::afterCommit(fn () => AssignOrderDelivery::dispatch((int) $delivery->tenant_id, (int) $order->id, true));

        return ApiResponse::success(['order_reference' => $order->reference_no, 'queued' => true],
            'Delivery was queued for manual partner dispatch.');
    }

    public function cancelPreview(Request $request, string $reference): JsonResponse
    {
        [$order, $delivery] = $this->cancellable($request, $reference);
        $branch = $order->branch()->withoutGlobalScopes()->firstOrFail();

        return ApiResponse::success([
            'order_reference' => $order->reference_no,
            'order_number' => $order->order_number,
            'provider' => $delivery->provider,
            'provider_task_id' => $delivery->external_delivery_id,
            'delivery_status' => $delivery->status?->value,
            'partner_name' => $delivery->partner_name ?: 'Delivery network',
            'provider_charge' => (float) ($delivery->provider_final_cost ?: $delivery->provider_quoted_cost),
            'customer_delivery_fee' => (float) $delivery->customer_delivery_fee,
            'currency' => $branch->currency,
            'rider_name' => $delivery->rider_name,
            'dropoff' => collect([
                data_get($order->fulfilmentDetails(), 'delivery_address.address_line1'),
                data_get($order->fulfilmentDetails(), 'delivery_address.city'),
                data_get($order->fulfilmentDetails(), 'delivery_address.state'),
                data_get($order->fulfilmentDetails(), 'delivery_address.postal_code'),
            ])->filter()->join(', '),
        ]);
    }

    public function cancel(Request $request, string $reference, UengageClient $client,
        PlatformDeliveryCredentials $credentials, DeliveryStateMachine $stateMachine): JsonResponse
    {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        [$order, $delivery] = $this->cancellable($request, $reference);

        DB::transaction(function () use ($delivery, $stateMachine): void {
            $locked = OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, [DeliveryStatus::RiderSearching, DeliveryStatus::RiderAssigned, DeliveryStatus::ArrivedAtPickup], true),
                409, 'This delivery can no longer be cancelled through the provider.');
            $stateMachine->transition($locked, DeliveryStatus::CancelPending, [
                'assignment_status' => 'provider_cancellation_pending',
                'failure_code' => null,
                'failure_reason' => null,
            ]);
        });

        try {
            $body = $client->cancelTask((string) $credentials->apiKey(), (string) $credentials->storeId(),
                (string) $delivery->external_delivery_id);
        } catch (ProviderUnavailable $exception) {
            $this->markCancellationReview($delivery, 'CANCELLATION_'.$exception->reasonCode,
                'Provider cancellation returned an uncertain result. Reconcile this task in the provider dashboard before retrying.', true);
            abort(503, 'Delivery cancellation could not be confirmed. The task was moved to investigation.');
        }

        $providerStatus = (string) ($body['status_code'] ?? '');
        if (($body['status'] ?? false) !== true || UengageStatus::normalize($providerStatus) !== DeliveryStatus::Cancelled) {
            $reason = $client->providerMessage($body, 'The provider rejected delivery cancellation.');
            $this->markCancellationReview($delivery, 'PROVIDER_CANCELLATION_REJECTED', $reason, false);
            abort(422, $reason);
        }

        DB::transaction(function () use ($delivery, $providerStatus, $data, $stateMachine): void {
            $locked = OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DeliveryStatus::Cancelled) return;
            abort_unless($locked->status === DeliveryStatus::CancelPending, 409, 'Delivery cancellation state changed. Refresh the order.');
            $stateMachine->transition($locked, DeliveryStatus::Cancelled, [
                'assignment_status' => 'cancelled',
                'provider_status' => $providerStatus,
                'failure_code' => null,
                'failure_reason' => null,
                'cancelled_at' => now(),
                'quote_history' => [...($locked->quote_history ?? []), [
                    'event' => 'provider_cancelled',
                    'reason' => trim((string) $data['reason']),
                    'at' => now()->toIso8601String(),
                ]],
            ]);
        });

        return ApiResponse::success([
            'order_reference' => $order->reference_no,
            'provider_task_id' => $delivery->external_delivery_id,
            'delivery_status' => DeliveryStatus::Cancelled->value,
            'order_status' => $order->status?->value,
        ], 'Delivery partner booking cancelled. The restaurant order remains active.');
    }

    private function attemptSnapshot(OrderDelivery $delivery): array
    {
        return [
            'attempt_number' => count($delivery->attempt_history ?? []) + 1,
            'task_id' => $delivery->external_delivery_id,
            'provider' => $delivery->provider,
            'partner_name' => $delivery->partner_name,
            'partner_code' => $delivery->partner_code,
            'status' => $delivery->status?->value,
            'provider_status' => $delivery->provider_status,
            'assignment_status' => $delivery->assignment_status,
            'rider_name' => $delivery->rider_name,
            'rider_phone' => $delivery->rider_phone,
            'rider_vehicle' => $delivery->rider_vehicle,
            'provider_quoted_cost' => $delivery->provider_quoted_cost,
            'provider_final_cost' => $delivery->provider_final_cost,
            'started_at' => $delivery->assignment_started_at?->toIso8601String(),
            'requested_at' => $delivery->booking_requested_at?->toIso8601String(),
            'created_at' => $delivery->booking_completed_at?->toIso8601String(),
            'assigned_at' => $delivery->rider_assigned_at?->toIso8601String() ?? $delivery->assigned_at?->toIso8601String(),
            'picked_up_at' => $delivery->picked_up_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'cancelled_at' => $delivery->cancelled_at?->toIso8601String(),
            'failure_code' => $delivery->failure_code,
            'failure_reason' => $delivery->failure_reason,
            'archived_at' => now()->toIso8601String(),
        ];
    }

    private function cancellable(Request $request, string $reference): array
    {
        $tenantId = (int) $request->user()->tenant_id;
        $order = Order::query()->withoutGlobalScopes()->with('branch:id,tenant_id,name,address_line1,address_line2,city,state,postal_code,currency')
            ->where('reference_no', $reference)
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))->firstOrFail();
        $delivery = OrderDelivery::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)->firstOrFail();
        abort_unless($delivery->provider === 'uengage' && filled($delivery->external_delivery_id), 409,
            'This order does not have an active delivery-network task.');
        abort_unless(in_array($delivery->status, [DeliveryStatus::RiderSearching, DeliveryStatus::RiderAssigned, DeliveryStatus::ArrivedAtPickup], true), 409,
            'Delivery can only be cancelled before rider pickup.');

        return [$order, $delivery];
    }

    private function markCancellationReview(OrderDelivery $delivery, string $code, string $reason, bool $ambiguous): void
    {
        OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->update([
            'status' => $ambiguous ? DeliveryStatus::Investigation : DeliveryStatus::ManualReviewRequired,
            'assignment_status' => $ambiguous ? 'cancellation_unknown' : 'cancellation_rejected',
            'failure_code' => $code,
            'failure_reason' => mb_substr($reason, 0, 500),
            'updated_at' => now(),
        ]);
    }

    private function eligible(Request $request, string $reference): array
    {
        $tenantId = (int) $request->user()->tenant_id;
        abort_unless((bool) setting('delivery_enabled', false), 422,
            'Home delivery is turned off. Open Delivery > Delivery setup and enable Offer home delivery.');
        abort_unless((bool) setting('third_party_delivery_enabled', false), 422,
            'Third-party delivery is turned off. Open Delivery > Delivery setup, choose Third-party delivery network, and save before sending this order.');
        $order = Order::query()->withoutGlobalScopes()->with('branch:id,tenant_id,name,address_line1,address_line2,city,state,postal_code,currency')
            ->where('reference_no', $reference)->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))->firstOrFail();
        $delivery = OrderDelivery::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('order_id', $order->id)->firstOrFail();
        abort_unless(in_array($delivery->status, [DeliveryStatus::WaitingForAssignment, DeliveryStatus::ManualReviewRequired, DeliveryStatus::Failed, DeliveryStatus::Cancelled], true)
            || in_array($delivery->assignment_status, ['pending', 'failed'], true), 409,
            'This delivery has already started partner assignment or no longer requires manual dispatch.');
        return [$order, $delivery];
    }
}
