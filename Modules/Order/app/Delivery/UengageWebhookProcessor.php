<?php

namespace Modules\Order\Delivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Models\OrderDelivery;

final class UengageWebhookProcessor
{
    public function __construct(private readonly UengageWebhookAuthenticator $authenticator, private readonly DeliveryStateMachine $stateMachine) {}

    public function process(string $rawBody, array $headers): array
    {
        if (! config('delivery.webhook_enabled', false)) {
            throw new ProviderUnavailable('PROVIDER_WEBHOOK_DISABLED');
        }
        if (! $this->authenticator->authenticate($rawBody, $headers)) {
            throw new ProviderUnavailable('WEBHOOK_AUTHENTICATION_FAILED');
        }
        if ($rawBody === '' || strlen($rawBody) > 65_536) {
            throw ValidationException::withMessages(['payload' => 'Invalid callback payload size.']);
        }
        try {
            $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => 'Malformed callback JSON.']);
        }

        return $this->processTrusted($payload);
    }

    /** Process a response obtained directly from the authenticated provider API. */
    public function processTrusted(array $payload): array
    {
        if (($payload['status'] ?? null) !== true || ! is_array($payload['data'] ?? null)) {
            throw ValidationException::withMessages(['payload' => 'Invalid provider tracking result.']);
        }
        $data = $payload['data'];
        $taskId = $this->requiredString($data['taskId'] ?? null, 'taskId', 191);
        // Flash v1.3 documents orderId only in an example callback; it is not
        // listed as a required field. The unique provider task ID remains the
        // primary lookup key. When supplied, orderId is still cross-checked.
        $orderReference = $this->optionalString($data['orderId'] ?? null, 191);
        $providerStatus = $this->requiredString($payload['status_code'] ?? null, 'status_code', 80);
        $nextStatus = UengageStatus::normalize($providerStatus);
        if (! $nextStatus) {
            throw ValidationException::withMessages(['status_code' => 'Unknown provider status.']);
        }
        $cancellationReason = $nextStatus === DeliveryStatus::Cancelled
            ? $this->cancellationReason([...$payload, ...$data])
            : null;

        $event = null;
        $detailsEvent = null;
        $result = DB::transaction(function () use ($data, $taskId, $orderReference, $providerStatus, $nextStatus, $cancellationReason, &$event, &$detailsEvent): array {
            $matches = OrderDelivery::withoutGlobalScopes()->with('order')
                ->where('provider', 'uengage')->where('external_delivery_id', $taskId)->lockForUpdate()->limit(2)->get();
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['taskId' => 'Delivery task was not found.']);
            }
            $delivery = $matches->first();
            if (! $delivery->order || ($orderReference !== null && ! hash_equals((string) $delivery->order->reference_no, $orderReference))
                || (int) $delivery->order->branch_id !== (int) $delivery->branch_id) {
                throw ValidationException::withMessages(['orderId' => 'Delivery task and order do not match.']);
            }
            if ($delivery->status === $nextStatus || $this->isStaleMilestone($delivery->status, $nextStatus)) {
                $sameStatus = $delivery->status === $nextStatus;
                $detailsChanged = $this->hydrateMissingProviderDetails($delivery, $data);
                // uEngage may send the drop OTP in a later callback after the
                // initial ALLOTTED event. Re-emit the same milestone only when
                // customer-visible details were enriched so the customer gets
                // the real OTP without replaying ordinary duplicate callbacks.
                if ($sameStatus && $detailsChanged && filled($delivery->fresh()->delivery_otp)) {
                    $detailsEvent = new DeliveryStatusChanged(
                        (int) $delivery->tenant_id,
                        (int) $delivery->id,
                        (int) $delivery->order_id,
                        $nextStatus,
                        $nextStatus,
                    );
                }

                return $this->result($delivery->fresh(), true);
            }

            $order = $delivery->order()->withoutGlobalScopes()->lockForUpdate()->first();
            if (! $order || $order->payment_status !== OrderPaymentStatus::Paid
                || ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery, OrderStatus::Completed], true)) {
                throw ValidationException::withMessages(['status_code' => 'Order is not eligible for a rider status update.']);
            }
            // The provider is authoritative for physical rider milestones.
            // A delayed kitchen click must not hide a pickup or delivery that
            // has already happened outside NexDine; the order is advanced below.
            $attributes = array_filter([
                'provider_status' => $providerStatus,
                'assignment_status' => $nextStatus === DeliveryStatus::Cancelled ? 'cancelled' : null,
                'failure_reason' => $nextStatus === DeliveryStatus::Cancelled
                    ? ($cancellationReason ?: 'The delivery partner cancelled the delivery.')
                    : null,
                'partner_name' => $this->optionalString($data['partner_name'] ?? null, 120),
                'rider_name' => DeliveryReadModel::riderIdentity($this->optionalString($data['rider_name'] ?? null, 120))[0],
                'rider_phone' => DeliveryReadModel::riderPhone($this->optionalString($data['rider_contact'] ?? null, 40)),
                'delivery_otp' => $this->deliveryOtpFrom($data),
                'tracking_url' => $this->safeTrackingUrl($data['tracking_url'] ?? null),
                'rider_assigned_at' => $nextStatus === DeliveryStatus::RiderAssigned ? now() : null,
                'arrived_at_pickup_at' => $nextStatus === DeliveryStatus::ArrivedAtPickup ? now() : null,
                'picked_up_at' => $nextStatus === DeliveryStatus::PickedUp ? now() : null,
                'arrived_at_customer_at' => $nextStatus === DeliveryStatus::ArrivedAtCustomer ? now() : null,
                'delivered_at' => $nextStatus === DeliveryStatus::Delivered ? now() : null,
                'cancelled_at' => $nextStatus === DeliveryStatus::Cancelled ? now() : null,
            ], static fn ($value) => $value !== null);
            // Provider coordinates are deliberately not persisted; the short-lived delivery OTP is encrypted at rest.
            $this->stateMachine->transition($delivery, $nextStatus, $attributes);

            $orderStatus = match ($nextStatus) {
                DeliveryStatus::PickedUp, DeliveryStatus::InTransit, DeliveryStatus::ArrivedAtCustomer => OrderStatus::OutForDelivery,
                DeliveryStatus::Delivered => OrderStatus::Completed,
                default => null,
            };
            if ($orderStatus && $order->status !== $orderStatus) {
                $order->update([
                    'status' => $orderStatus,
                    'closed_at' => $orderStatus === OrderStatus::Completed ? now() : $order->closed_at,
                ]);
                $order->storeStatusLog($orderStatus, note: 'UENGAGE_DELIVERY_STATUS');
                $event = new OrderUpdateStatus($order->fresh(), $orderStatus, note: 'UENGAGE_DELIVERY_STATUS');
            }

            return $this->result($delivery->fresh(), false);
        }, 3);

        if ($event) {
            DB::afterCommit(fn () => event($event));
        }
        if ($detailsEvent) {
            DB::afterCommit(fn () => event($detailsEvent));
        }

        return $result;
    }

    private function cancellationReason(array $data): ?string
    {
        foreach (['cancellation_reason', 'cancel_reason', 'reason', 'status_message', 'message'] as $field) {
            $reason = $this->optionalString($data[$field] ?? null, 500);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function isStaleMilestone(?DeliveryStatus $current, DeliveryStatus $incoming): bool
    {
        $rank = [
            DeliveryStatus::RiderSearching->value => 10,
            DeliveryStatus::RiderAssigned->value => 20,
            DeliveryStatus::ArrivedAtPickup->value => 30,
            DeliveryStatus::PickedUp->value => 40,
            DeliveryStatus::InTransit->value => 50,
            DeliveryStatus::ArrivedAtCustomer->value => 60,
            DeliveryStatus::Delivered->value => 70,
        ];
        if ($current === null || ! isset($rank[$current->value], $rank[$incoming->value])) {
            return false;
        }

        return $rank[$incoming->value] < $rank[$current->value];
    }

    private function hydrateMissingProviderDetails(OrderDelivery $delivery, array $data): bool
    {
        $incoming = [
            'partner_name' => $this->optionalString($data['partner_name'] ?? null, 120),
            'rider_name' => DeliveryReadModel::riderIdentity($this->optionalString($data['rider_name'] ?? null, 120))[0],
            'rider_phone' => DeliveryReadModel::riderPhone($this->optionalString($data['rider_contact'] ?? null, 40)),
            'delivery_otp' => $this->deliveryOtpFrom($data),
            'tracking_url' => $this->safeTrackingUrl($data['tracking_url'] ?? null),
        ];
        $missing = array_filter($incoming, static fn ($value, $field) => $value !== null && blank($delivery->{$field}), ARRAY_FILTER_USE_BOTH);
        if ($missing !== []) {
            $delivery->fill($missing)->save();
        }

        return $missing !== [];
    }

    /** Accept the field names observed across uEngage API and callback versions. */
    private function deliveryOtpFrom(array $data): ?string
    {
        $direct = $data['delivery_otp']
            ?? $data['deliveryOtp']
            ?? $data['deliveryOTP']
            ?? $data['drop_otp']
            ?? $data['dropOtp']
            ?? $data['dropOTP']
            ?? $data['drop_otp_code']
            ?? $data['dropOtpCode']
            ?? $data['dropOTPCode']
            ?? $data['otp']
            ?? data_get($data, 'delivery.otp')
            ?? data_get($data, 'drop.otp');
        if ($direct !== null) {
            return $this->deliveryOtp($direct);
        }

        // Callback versions have also nested the same delivery/drop field in a
        // task/details object. Match only delivery/drop OTP names so a pickup
        // verification code can never be sent to the customer by mistake.
        foreach ($data as $key => $value) {
            $normalised = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalised, ['deliveryotp', 'deliveryotpcode', 'dropotp', 'dropotpcode'], true)) {
                return $this->deliveryOtp($value);
            }
            if (is_array($value)) {
                $nested = $this->deliveryOtpFrom($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function requiredString(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw ValidationException::withMessages([$field => "Invalid {$field}."]);
        }

        return trim($value);
    }

    private function optionalString(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen(trim($value)) > $max) {
            throw ValidationException::withMessages(['payload' => 'Invalid callback field.']);
        }

        return trim($value);
    }

    private function deliveryOtp(mixed $value): ?string
    {
        // Providers may serialize numeric OTPs as JSON numbers.
        if (is_int($value)) {
            $value = (string) $value;
        }
        $otp = $this->optionalString($value, 20);
        if ($otp === null) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9-]{3,20}$/', $otp) !== 1) {
            throw ValidationException::withMessages(['delivery_otp' => 'Invalid delivery OTP.']);
        }

        return $otp;
    }

    private function safeTrackingUrl(mixed $value): ?string
    {
        $url = $this->optionalString($value, 2048);
        if ($url === null) {
            return null;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages(['tracking_url' => 'Tracking URL must use HTTPS.']);
        }

        return $url;
    }

    private function result(OrderDelivery $delivery, bool $duplicate): array
    {
        return ['duplicate' => $duplicate, 'delivery_id' => (int) $delivery->id, 'order_id' => (int) $delivery->order_id,
            'tenant_id' => (int) $delivery->tenant_id, 'status' => $delivery->status->value];
    }
}
