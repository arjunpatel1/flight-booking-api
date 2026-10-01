<?php

namespace Modules\Order\Delivery;

use Modules\Order\Models\OrderDelivery;

final class DeliveryReadModel
{
    private const CUSTOMER_LABELS = [
        'waiting_for_assignment' => 'Finding delivery partner',
        'fetching_quotes' => 'Finding delivery partner',
        'serviceability_checked' => 'Finding delivery partner',
        'quoted' => 'Finding delivery partner',
        'assigning' => 'Finding delivery partner',
        'booking_pending' => 'Finding delivery partner',
        'booked' => 'Finding delivery partner',
        'investigation' => 'Delivery is being arranged',
        'manual_review_required' => 'Delivery is being arranged',
        'failed' => 'Restaurant will contact you',
    ];

    public static function customer(?OrderDelivery $delivery): ?array
    {
        if (! $delivery) {
            return null;
        }

        [$riderName, $legacyDeliveryOtp] = self::riderIdentity($delivery->rider_name);
        $deliveryOtp = $delivery->delivery_otp ?: $legacyDeliveryOtp;
        $customerMaySeeRider = in_array($delivery->status?->value, [
            'rider_assigned', 'arrived_at_pickup', 'picked_up', 'in_transit',
            'arrived_at_customer', 'delivered', 'investigation', 'manual_review_required',
        ], true);
        $customerMaySeeTracking = ! in_array($delivery->status?->value, ['cancelled', 'failed', 'rto', 'rto_completed'], true);

        return [
            'status' => self::customerStatus($delivery),
            'status_label' => self::CUSTOMER_LABELS[$delivery->status?->value ?? '']
                ?? str_replace('_', ' ', ucfirst($delivery->status?->value ?? '')),
            'partner_name' => self::tenantPartnerName($delivery->partner_name),
            'rider_name' => $customerMaySeeRider ? $riderName : null,
            'rider_phone' => $customerMaySeeRider ? self::riderPhone($delivery->rider_phone) : null,
            'delivery_otp' => $customerMaySeeRider ? $deliveryOtp : null,
            'eta_minutes' => $delivery->eta_minutes,
            'tracking_url' => $customerMaySeeTracking ? self::safeTrackingUrl($delivery->tracking_url) : null,
            'picked_up_at' => $delivery->picked_up_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function riderIdentity(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [null, null];
        }

        $otp = null;
        if (preg_match('/\s*\(\s*OTP\s*:\s*([^)]+)\)\s*$/i', $value, $matches) === 1) {
            $otp = trim($matches[1]);
            $value = trim(substr($value, 0, -strlen($matches[0])));
        }

        if (preg_match('/^(?:not\s+provided|pending|unknown|n\/?a|none|unassigned|rider\s+searching)$/i', $value) === 1) {
            $value = '';
        }

        return [$value !== '' ? $value : null, $otp !== '' ? $otp : null];
    }

    public static function riderPhone(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^(?:not\s+provided|pending|unknown|n\/?a|none)$/i', $value) === 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (! is_string($digits) || strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }
        if (preg_match('/^(\d)\1+$/', $digits) === 1) {
            return null;
        }

        return str_starts_with($value, '+') ? '+'.$digits : $digits;
    }

    private static function customerStatus(OrderDelivery $delivery): string
    {
        return in_array($delivery->status?->value, ['investigation', 'manual_review_required'], true)
            ? 'arranging_delivery'
            : ($delivery->status?->value ?? 'waiting_for_assignment');
    }

    private static function adminAttempts(OrderDelivery $delivery, bool $showFinancials): array
    {
        $attempts = collect($delivery->attempt_history ?? [])->map(function (array $attempt) use ($showFinancials): array {
            if (! $showFinancials) {
                unset($attempt['provider_quoted_cost'], $attempt['provider_final_cost']);
            }

            $attempt['partner_name'] = self::tenantPartnerName($attempt['partner_name'] ?? null);

            return $attempt;
        })->values()->all();

        $hasCurrentAttempt = filled($delivery->external_delivery_id)
            || $delivery->assignment_started_at !== null
            || $delivery->booking_requested_at !== null;
        if (! $hasCurrentAttempt) {
            return $attempts;
        }

        $current = [
            'attempt_number' => count($attempts) + 1,
            'task_id' => $delivery->external_delivery_id,
            'partner_name' => self::tenantPartnerName($delivery->partner_name),
            'partner_code' => $delivery->partner_code,
            'status' => $delivery->status?->value,
            'provider_status' => $delivery->provider_status,
            'assignment_status' => $delivery->assignment_status,
            'rider_name' => self::riderIdentity($delivery->rider_name)[0],
            'rider_phone' => self::riderPhone($delivery->rider_phone),
            'rider_vehicle' => $delivery->rider_vehicle,
            'started_at' => $delivery->assignment_started_at?->toIso8601String(),
            'requested_at' => $delivery->booking_requested_at?->toIso8601String(),
            'created_at' => $delivery->booking_completed_at?->toIso8601String(),
            'assigned_at' => $delivery->rider_assigned_at?->toIso8601String() ?? $delivery->assigned_at?->toIso8601String(),
            'picked_up_at' => $delivery->picked_up_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'cancelled_at' => $delivery->cancelled_at?->toIso8601String(),
            'failure_code' => $delivery->failure_code,
            'failure_reason' => $delivery->failure_reason,
            'current' => true,
        ];
        if ($showFinancials) {
            $current['provider_quoted_cost'] = $delivery->provider_quoted_cost;
            $current['provider_final_cost'] = $delivery->provider_final_cost;
        }
        $attempts[] = $current;

        return $attempts;
    }

    private static function tenantPartnerName(?string $value): ?string
    {
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }

        return preg_match('/(?:uengage|flash)/i', $name) === 1 ? 'Delivery network' : $name;
    }

    private static function safeTrackingUrl(?string $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }

    public static function admin(?OrderDelivery $delivery, bool $showFinancials = false): ?array
    {
        if (! $delivery) {
            return null;
        }

        return [
            ...self::customer($delivery),
            'assignment_status' => $delivery->assignment_status,
            'delivery_attempts' => self::adminAttempts($delivery, $showFinancials),
            'provider' => $delivery->provider,
            'partner_code' => $delivery->partner_code,
            'distance_km' => $delivery->distance_km,
            ...($showFinancials ? [
                'customer_delivery_fee' => $delivery->customer_delivery_fee,
                'provider_quoted_cost' => $delivery->provider_quoted_cost,
                'provider_final_cost' => $delivery->provider_final_cost,
                'restaurant_contribution' => $delivery->restaurant_contribution,
                'platform_contribution' => $delivery->platform_contribution,
                'delivery_margin' => $delivery->delivery_margin,
                'quote_history' => $delivery->quote_history ?? [],
            ] : []),
            'tracking_url' => self::safeTrackingUrl($delivery->tracking_url),
            'rider_name' => self::riderIdentity($delivery->rider_name)[0],
            'delivery_otp' => $delivery->delivery_otp ?: self::riderIdentity($delivery->rider_name)[1],
            'rider_phone' => self::riderPhone($delivery->rider_phone),
            'rider_vehicle' => $delivery->rider_vehicle,
            'assignment_attempts' => $delivery->assignment_attempts,
            'failure_code' => $delivery->failure_code,
            'failure_reason' => $delivery->failure_reason,
            'provider_correlation_id' => $delivery->provider_correlation_id,
            'booking_phase' => $delivery->booking_phase,
            'booking_claimed_at' => $delivery->booking_claimed_at?->toIso8601String(),
            'booking_requested_at' => $delivery->booking_requested_at?->toIso8601String(),
            'booking_completed_at' => $delivery->booking_completed_at?->toIso8601String(),
            'provider_managed' => $delivery->status !== \Modules\Order\Enums\DeliveryStatus::Cancelled
                && ($delivery->mode === 'partner_api'
                    || ($delivery->mode === 'third_party' && filled($delivery->external_delivery_id))),
            'tenant_delivery_actions_allowed' => $delivery->status === \Modules\Order\Enums\DeliveryStatus::Cancelled
                || ! ($delivery->mode === 'partner_api'
                    || ($delivery->mode === 'third_party' && filled($delivery->external_delivery_id))),
            'redispatch_allowed' => $delivery->status === \Modules\Order\Enums\DeliveryStatus::Cancelled
                && filled($delivery->external_delivery_id) && $delivery->cancelled_at !== null,
            'automatic_retry_forbidden' => in_array($delivery->status?->value, ['booking_pending', 'investigation'], true),
            'assigned_at' => $delivery->assigned_at?->toIso8601String(),
            'rider_assigned_at' => $delivery->rider_assigned_at?->toIso8601String(),
            'assignment_deadline_at' => $delivery->assignment_deadline_at?->toIso8601String(),
            'assignment_escalated_at' => $delivery->assignment_escalated_at?->toIso8601String(),
            'assignment_remaining_seconds' => $delivery->assignment_deadline_at && $delivery->rider_assigned_at === null
                ? max(0, now()->diffInSeconds($delivery->assignment_deadline_at, false))
                : null,
            'assignment_overdue' => $delivery->rider_assigned_at === null
                && ($delivery->assignment_escalated_at !== null || $delivery->assignment_deadline_at?->isPast()),
            'arrived_at_pickup_at' => $delivery->arrived_at_pickup_at?->toIso8601String(),
            'arrived_at_customer_at' => $delivery->arrived_at_customer_at?->toIso8601String(),
            'cancelled_at' => $delivery->cancelled_at?->toIso8601String(),
        ];
    }
}
