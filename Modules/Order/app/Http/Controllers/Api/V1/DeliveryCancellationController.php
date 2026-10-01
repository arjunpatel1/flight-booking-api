<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Order\Delivery\DeliveryStateMachine;
use Modules\Order\Delivery\PlatformDeliveryCredentials;
use Modules\Order\Delivery\UengageClient;
use Modules\Order\Delivery\UengageStatus;
use Modules\Order\Delivery\UengageWebhookProcessor;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Saas\Support\TenantContext;
use Modules\Support\ApiResponse;

final class DeliveryCancellationController extends Controller
{
    private const CANCELLABLE = [
        DeliveryStatus::RiderSearching,
        DeliveryStatus::RiderAssigned,
        DeliveryStatus::ArrivedAtPickup,
    ];

    public function preview(string $order, TenantContext $context): JsonResponse
    {
        [$model, $delivery] = $this->find($order, (int) $context->id());

        return ApiResponse::success(body: $this->summary($model, $delivery));
    }

    public function cancel(
        Request $request,
        TenantContext $context,
        UengageClient $client,
        UengageWebhookProcessor $processor,
        DeliveryStateMachine $stateMachine,
        PlatformDeliveryCredentials $credentials,
    ): JsonResponse {
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'action' => ['required', Rule::in(['delivery_only', 'full_order'])],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[0-9a-f-]{36}$/i', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }

        $tenantId = (int) $context->id();
        [$model, $delivery] = $this->find((string) $request->route('order'), $tenantId);
        $cacheKey = "delivery-cancel:{$tenantId}:{$delivery->id}:{$key}";
        if (! Cache::add($cacheKey, 'processing', now()->addDay())) {
            return ApiResponse::success(body: $this->summary($model->fresh(), $delivery->fresh()), message: 'This cancellation request was already processed.');
        }

        try {
            $tracked = $client->trackTaskStatus((string) $credentials->apiKey(), (string) $credentials->storeId(), (string) $delivery->external_delivery_id);
            if (($tracked['status'] ?? false) === true) {
                $processor->processTrusted($tracked);
            }
            $delivery->refresh();
            if (! in_array($delivery->status, self::CANCELLABLE, true)) {
                throw ValidationException::withMessages(['delivery' => $this->unsafeReason($delivery->status)]);
            }

            DB::transaction(function () use ($delivery, $stateMachine, $data): void {
                $locked = OrderDelivery::query()->where('tenant_id', $delivery->tenant_id)->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, self::CANCELLABLE, true)) {
                    throw ValidationException::withMessages(['delivery' => $this->unsafeReason($locked->status)]);
                }
                $stateMachine->transition($locked, DeliveryStatus::CancelPending, [
                    'assignment_status' => 'cancellation_requested',
                    'failure_reason' => trim($data['reason']),
                ]);
            }, 3);

            try {
                $response = $client->cancelTask((string) $credentials->apiKey(), (string) $credentials->storeId(), (string) $delivery->external_delivery_id);
            } catch (\Throwable $exception) {
                $this->investigate($delivery, $stateMachine, 'Provider cancellation response was uncertain. Verify the task in the provider dashboard before any refund or re-dispatch.');
                throw $exception;
            }

            $providerStatus = UengageStatus::normalize((string) ($response['status_code'] ?? ''));
            if (($response['status'] ?? false) !== true || $providerStatus !== DeliveryStatus::Cancelled) {
                $this->investigate($delivery, $stateMachine, 'The delivery network did not provide a definitive cancellation confirmation.');
                throw ValidationException::withMessages(['delivery' => 'The delivery network did not confirm cancellation. The delivery is under investigation.']);
            }

            DB::transaction(function () use ($delivery, $stateMachine, $data, $request): void {
                $locked = OrderDelivery::query()->where('tenant_id', $delivery->tenant_id)->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                $stateMachine->transition($locked, DeliveryStatus::Cancelled, [
                    'assignment_status' => 'cancelled',
                    'provider_status' => 'CANCELLED',
                    'cancelled_at' => now(),
                    'failure_code' => 'ADMIN_PROVIDER_CANCELLATION',
                    'failure_reason' => trim($data['reason']),
                    'assignment_token' => null,
                ]);
                activity('delivery')->event('provider_delivery_cancelled')->causedBy($request->user())
                    ->performedOn($locked)->withProperties([
                        'tenant_id' => $locked->tenant_id,
                        'order_id' => $locked->order_id,
                        'external_delivery_id' => $locked->external_delivery_id,
                        'action' => $data['action'],
                        'reason' => trim($data['reason']),
                    ])->log('Delivery-network cancellation confirmed.');
            }, 3);

            Cache::put($cacheKey, 'completed', now()->addDays(30));

            return ApiResponse::success(
                body: [...$this->summary($model->fresh(), $delivery->fresh()), 'next_action' => $data['action'] === 'full_order' ? 'cancel_order_and_refund' : 'redispatch_available'],
                message: $data['action'] === 'full_order'
                    ? 'Delivery cancelled. Continue with order cancellation and refund.'
                    : 'Delivery partner booking cancelled.',
            );
        } catch (\Throwable $exception) {
            if (Cache::get($cacheKey) === 'processing') {
                Cache::forget($cacheKey);
            }
            throw $exception;
        }
    }

    private function find(string $reference, int $tenantId): array
    {
        $order = Order::query()->withoutGlobalScopes()->with(['branch', 'delivery'])
            ->where('reference_no', $reference)
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $tenantId))
            ->firstOrFail();
        $delivery = $order->delivery;
        if (! $delivery || $delivery->provider !== 'uengage' || blank($delivery->external_delivery_id)) {
            throw ValidationException::withMessages(['delivery' => 'This order has no cancellable delivery-network task.']);
        }
        if (! in_array($delivery->status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages(['delivery' => $this->unsafeReason($delivery->status)]);
        }

        return [$order, $delivery];
    }

    private function investigate(OrderDelivery $delivery, DeliveryStateMachine $stateMachine, string $reason): void
    {
        DB::transaction(function () use ($delivery, $stateMachine, $reason): void {
            $locked = OrderDelivery::query()->where('tenant_id', $delivery->tenant_id)->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DeliveryStatus::CancelPending) {
                $stateMachine->transition($locked, DeliveryStatus::Investigation, [
                    'assignment_status' => 'cancellation_uncertain',
                    'failure_code' => 'CANCELLATION_CONFIRMATION_UNCERTAIN',
                    'failure_reason' => $reason,
                ]);
            }
        }, 3);
    }

    private function unsafeReason(?DeliveryStatus $status): string
    {
        return match ($status) {
            DeliveryStatus::PickedUp, DeliveryStatus::InTransit, DeliveryStatus::ArrivedAtCustomer, DeliveryStatus::Delivered => 'Cancellation is blocked because the rider has already picked up the order.',
            DeliveryStatus::Cancelled => 'This delivery is already cancelled.',
            DeliveryStatus::CancelPending, DeliveryStatus::Investigation => 'This delivery already has an unresolved cancellation request.',
            default => 'The current delivery state cannot be cancelled safely.',
        };
    }

    private function summary(Order $order, OrderDelivery $delivery): array
    {
        return [
            'order_reference' => $order->reference_no,
            'order_number' => $order->order_number,
            'provider_task_id' => $delivery->external_delivery_id,
            'delivery_status' => $delivery->status?->value,
            'rider_status' => $delivery->assignment_status,
            'provider_charge' => (float) ($delivery->provider_final_cost ?: $delivery->provider_quoted_cost),
            'customer_delivery_fee' => (float) $delivery->customer_delivery_fee,
            'currency' => $order->currency,
            'dropoff' => (string) data_get($order->fulfilmentDetails(), 'delivery_address.address', ''),
            'payment_status' => $order->payment_status?->value,
            'refund_required' => $order->payment_status?->value === 'paid',
            'can_cancel_delivery' => in_array($delivery->status, self::CANCELLABLE, true),
        ];
    }
}
