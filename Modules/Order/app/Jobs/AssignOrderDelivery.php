<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Delivery\DeliveryBookingGuard;
use Modules\Order\Delivery\DeliveryCostCalculator;
use Modules\Order\Delivery\DeliveryLocation;
use Modules\Order\Delivery\DeliveryProvider;
use Modules\Order\Delivery\DeliveryQuoteSelector;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Support\TenantStaffRecipients;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\User\Models\User;
use Throwable;

/** A queued, tenant-bound assignment cycle. Provider calls never hold a DB lock. */
class AssignOrderDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $tenantId, public readonly int $orderId, public readonly bool $manualConfirmed = false)
    {
        $this->onQueue((string) config('delivery.queue', 'delivery'));
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->orderId;
    }

    public function handle(EffectiveTenantEntitlementService $entitlements, DeliveryProvider $provider, DeliveryQuoteSelector $selector, DeliveryCostCalculator $costs, DeliveryBookingGuard $bookingGuard, DeliveryWallet $wallet): void
    {
        if (! config('delivery.integration_enabled', false)) {
            return;
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->find($this->tenantId);
        if (! $tenant || ! $entitlements->has($tenant, 'delivery')) {
            return;
        }

        app(TenantContext::class)->setId($this->tenantId);
        app(SettingServiceInterface::class)->refreshSettingBinding();
        $token = null;
        $confirmedExternalId = null;
        $confirmedProvider = null;
        try {
            $settings = [
                'delivery_enabled' => (bool) setting('delivery_enabled', false),
                'third_party_delivery_enabled' => (bool) setting('third_party_delivery_enabled', false),
                'automatic_partner_assignment_enabled' => (bool) app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('automatic_partner_assignment_enabled', false),
                'delivery_cod_enabled' => (bool) setting('delivery_cod_enabled', false),
                'delivery_prepaid_enabled' => (bool) setting('delivery_prepaid_enabled', false),
                'delivery_quotes_enabled' => (bool) app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('delivery_quotes_enabled', false),
                'delivery_selection_strategy' => app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('delivery_selection_strategy', 'cheapest'),
                'delivery_provider_codes' => app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('delivery_provider_codes', []),
                'maximum_provider_delivery_cost' => app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('maximum_provider_delivery_cost'),
                'maximum_delivery_eta_minutes' => app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('maximum_delivery_eta_minutes'),
                'maximum_delivery_radius_km' => setting('maximum_delivery_radius_km'),
                'auto_fallback_partner_enabled' => (bool) app(\Modules\Order\Delivery\PlatformDeliveryCredentials::class)->operationalValue('auto_fallback_partner_enabled', false),
            ];
            if (! $settings['delivery_enabled'] || ! $settings['third_party_delivery_enabled']) {
                return;
            }

            $token = (string) Str::uuid();
            $prepared = DB::transaction(function () use ($token, $settings): ?array {
                $order = Order::query()->withoutGlobalScopes()->whereKey($this->orderId)->lockForUpdate()->first();
                if (! $order || $order->type !== OrderType::Delivery
                    || $order->payment_status !== OrderPaymentStatus::Paid
                    || ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready], true)) {
                    return null;
                }
                $branch = $order->branch()->withoutGlobalScopes()->first();
                if (! $branch || (int) $branch->tenant_id !== $this->tenantId) {
                    return null;
                }

                $delivery = OrderDelivery::query()->where('tenant_id', $this->tenantId)->where('order_id', $order->id)->lockForUpdate()->first();
                // A signed partner integration owns its own driver lifecycle.
                // Never start a second Flash booking for the same order.
                if ($delivery && $delivery->mode === 'partner_api') {
                    return null;
                }
                if ($delivery && ($delivery->external_delivery_id || $delivery->provider_correlation_id
                    || $delivery->booking_requested_at || $delivery->status?->isTerminal()
                    || $delivery->status === DeliveryStatus::Investigation
                    || in_array($delivery->status, [DeliveryStatus::FetchingQuotes, DeliveryStatus::Assigning, DeliveryStatus::RiderSearching, DeliveryStatus::RiderAssigned], true))) {
                    return null;
                }

                $pickup = DeliveryLocation::fromAddress(['latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
                $dropoff = DeliveryLocation::fromAddress((array) data_get($order->fulfilmentDetails(), 'delivery_address', []));
                $distance = $pickup && $dropoff ? $pickup->distanceTo($dropoff) : null;
                // Existing checkout has no customer delivery-fee line. Never
                // infer one from a later provider quote or alter order totals.
                $customerFee = max(0, (float) data_get($order->fulfilmentDetails(), 'customer_delivery_fee', 0));
                $delivery ??= new OrderDelivery(['tenant_id' => $this->tenantId, 'branch_id' => $branch->id, 'order_id' => $order->id]);
                $delivery->fill([
                    'pickup_latitude' => $pickup?->latitude, 'pickup_longitude' => $pickup?->longitude,
                    'dropoff_latitude' => $dropoff?->latitude, 'dropoff_longitude' => $dropoff?->longitude,
                    'distance_km' => $distance, 'customer_delivery_fee' => $customerFee,
                ]);

                $radii = array_filter([$settings['maximum_delivery_radius_km'], $branch->delivery_radius_km],
                    static fn ($value) => $value !== null && is_numeric($value) && (float) $value > 0);
                $radius = $radii === [] ? null : min(array_map('floatval', $radii));
                $failure = match (true) {
                    ! $pickup => ['PICKUP_LOCATION_MISSING', 'Set the outlet map location before assigning delivery.'],
                    ! $dropoff => ['CUSTOMER_LOCATION_MISSING', 'Customer address needs a map location before assigning delivery.'],
                    $radius !== null && $distance > (float) $radius + 0.000001 => ['OUTSIDE_DELIVERY_RADIUS', 'Customer address is outside the configured delivery radius.'],
                    default => null,
                };
                if ($failure) {
                    $delivery->fill(['status' => DeliveryStatus::ManualReviewRequired, 'assignment_status' => 'failed', 'failure_code' => $failure[0], 'failure_reason' => $failure[1]]);
                    $delivery->save();

                    return null;
                }

                $delivery->fill(['status' => DeliveryStatus::FetchingQuotes, 'assignment_status' => 'in_progress', 'assignment_token' => $token, 'assignment_started_at' => now(), 'failure_code' => null, 'failure_reason' => null]);
                $delivery->save();

                return ['pickup' => $pickup, 'dropoff' => $dropoff, 'reference' => $order->reference_no,
                    'cod' => ! $order->payment_status->isPaid()
                        && in_array(strtolower((string) data_get($order->fulfilmentDetails(), 'payment_method', '')), ['cod', 'cash', 'cash_on_delivery'], true)];
            });
            if (! $prepared) {
                $failedDelivery = OrderDelivery::query()->withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)->where('order_id', $this->orderId)
                    ->where('status', DeliveryStatus::ManualReviewRequired)->first();
                if ($failedDelivery && filled($failedDelivery->failure_code)) {
                    $this->notifyManualActionRequired(
                        (string) $failedDelivery->failure_code,
                        (string) ($failedDelivery->failure_reason ?: 'The delivery task was not created.'),
                        false,
                    );
                }

                return;
            }
            if (! $this->manualConfirmed && (! $settings['automatic_partner_assignment_enabled'] || $settings['delivery_selection_strategy'] === 'manual')) {
                $this->review($token, 'MANUAL_ASSIGNMENT_SELECTED', 'Manual delivery assignment is selected for this restaurant.');

                return;
            }
            if (! $settings['delivery_quotes_enabled']) {
                $this->review($token, 'QUOTES_DISABLED', 'Partner quotes are disabled for this restaurant.');

                return;
            }
            if ($prepared['cod'] && ! $settings['delivery_cod_enabled'] || ! $prepared['cod'] && ! $settings['delivery_prepaid_enabled']) {
                $this->review($token, 'PAYMENT_METHOD_DISABLED', 'Third-party delivery is disabled for this payment method.');

                return;
            }

            try {
                $startedAt = microtime(true);
                $quotes = $provider->quotes($prepared['pickup'], $prepared['dropoff'], $prepared['reference'], $prepared['cod']);
                $this->operationLog('serviceability', $token, 'success', $startedAt, ['quote_count' => count($quotes)]);
            } catch (ProviderUnavailable $exception) {
                $this->operationLog('serviceability', $token, 'failure', $startedAt ?? microtime(true), ['failure_category' => $exception->reasonCode]);
                $this->review($token, $exception->reasonCode, $exception->getMessage());

                return;
            }

            $selection = $selector->rank($quotes, $settings, $prepared['cod']);
            $ranked = $selection['ranked'];
            $audit = $selection['audit'];
            if ($ranked === []) {
                $this->review($token, 'NO_ELIGIBLE_PARTNER', 'No serviceable partner meets the configured cost, ETA and payment limits.', $audit);

                return;
            }

            // Flash v1.3 does not define atomic rejection, booking idempotency,
            // or reconciliation. Only one external booking transmission is
            // therefore permitted for this logical delivery. In particular,
            // an HTTP error or negative/malformed response is not proof that a
            // task was not created and must never trigger partner fallback.
            foreach (array_slice($ranked, 0, 1) as $quote) {
                if (! $this->recordAttempt($token, $quote->partnerCode, $audit)) {
                    return;
                }
                $correlationId = (string) Str::uuid();
                if (! $bookingGuard->claim($this->tenantId, $this->orderId, $token, $correlationId)) {
                    return;
                }
                $deliveryId = (int) OrderDelivery::query()->where('tenant_id', $this->tenantId)
                    ->where('order_id', $this->orderId)->value('id');
                try {
                    // Funds move from available to reserved before any provider
                    // transmission. Ambiguous outcomes retain the reservation.
                    $wallet->reserve($this->tenantId, $deliveryId, $this->orderId, $quote->cost, 'delivery:'.$deliveryId.':reserve:'.$correlationId);
                } catch (\Illuminate\Validation\ValidationException) {
                    // No provider request has started, so release the local claim and permit a later explicit retry.
                    $bookingGuard->releasePreTransmissionClaim($this->tenantId, $this->orderId, $token, $correlationId);
                    $this->review($token, 'DELIVERY_WALLET_INSUFFICIENT', 'Add funds to the delivery wallet before booking this delivery.', $audit);

                    return;
                }
                if (! $bookingGuard->markRequestStarting($this->tenantId, $this->orderId, $token, $correlationId)) {
                    $wallet->release($this->tenantId, $deliveryId, $this->orderId, $quote->cost,
                        'delivery:'.$deliveryId.':release-before-transmission:'.$correlationId,
                        'Booking request was stopped before provider transmission.');

                    return;
                }
                try {
                    $startedAt = microtime(true);
                    $result = $provider->book($quote, $prepared['reference'], $this->tenantId.':'.$this->orderId.':'.($quote->reference ?: $quote->partnerCode));
                    $this->operationLog('booking', $correlationId, $result->successful ? 'success' : 'rejected', $startedAt,
                        ['failure_category' => $result->failureCode]);
                } catch (ProviderUnavailable $exception) {
                    $this->operationLog('booking', $correlationId, 'failure', $startedAt ?? microtime(true), ['failure_category' => $exception->reasonCode]);
                    $this->review($token, $exception->reasonCode, $exception->getMessage(), $audit, true);

                    return;
                }
                if ($result->successful && filled($result->externalDeliveryId)) {
                    // Retain the confirmed identity outside the persistence
                    // transaction so a later DB failure can reconcile it.
                    $confirmedExternalId = $result->externalDeliveryId;
                    $confirmedProvider = $provider->code();
                    $wallet->capture($this->tenantId, $deliveryId, $this->orderId, $quote->cost, 'delivery:'.$deliveryId.':capture:'.$correlationId);
                    $this->assigned($token, $provider->code(), $quote, $result->externalDeliveryId, $audit, $costs);

                    return;
                }
                // A parsed status=false response is a definitive rejection: no
                // provider task exists, so return the reserved wallet funds and
                // allow the admin's explicit manual-send flow after correction.
                $wallet->releaseOutstanding(
                    $this->tenantId,
                    $deliveryId,
                    $this->orderId,
                    'delivery:'.$deliveryId.':release-provider-rejected:'.$correlationId,
                    'Provider definitively rejected task creation.'
                );
                $this->review(
                    $token,
                    $result->failureCode ?: 'PROVIDER_REJECTED',
                    $result->failureReason ?: 'The provider rejected task creation. Review the validation details and send again manually.',
                    $audit,
                    false
                );

                return;
            }
            $this->review($token, 'ASSIGNMENT_FAILED', 'No delivery booking attempt could be started.', $audit);
        } catch (Throwable $exception) {
            if ($token !== null) {
                if (filled($confirmedExternalId) && filled($confirmedProvider)) {
                    $this->investigateKnownBooking($token, $confirmedProvider, $confirmedExternalId);
                } else {
                    $possiblyTransmitted = OrderDelivery::query()->where('tenant_id', $this->tenantId)
                        ->where('order_id', $this->orderId)->whereNotNull('booking_requested_at')->exists();
                    $this->review($token,
                        $possiblyTransmitted ? 'UNKNOWN_PROVIDER_RESULT' : 'DELIVERY_WORKFLOW_ERROR',
                        $possiblyTransmitted
                            ? 'Booking may have reached the provider, but NexDine could not persist a definitive result. Do not retry booking.'
                            : 'Delivery assignment could not finish. Review this order manually.',
                        [], $possiblyTransmitted);
                }
            }
            Log::error('Delivery assignment workflow failed', [
                'tenant_id' => $this->tenantId,
                'order_id' => $this->orderId,
                'exception_type' => $exception::class, 'exception_message' => mb_substr($exception->getMessage(), 0, 500), 'exception_line' => $exception->getLine(),
            ]);
        } finally {
            app(TenantContext::class)->setId(null);
            app(SettingServiceInterface::class)->refreshSettingBinding();
        }
    }

    /** Best-effort retention of a task ID returned before a later persistence failure. */
    private function investigateKnownBooking(string $token, string $provider, string $externalId): void
    {
        $markedForInvestigation = DB::transaction(function () use ($token, $provider, $externalId): bool {
            $order = Order::query()->withoutGlobalScopes()->whereKey($this->orderId)->lockForUpdate()->first();
            $delivery = OrderDelivery::query()->where('tenant_id', $this->tenantId)
                ->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $order || ! $delivery || $delivery->external_delivery_id
                || $delivery->assignment_token !== $token) {
                return false;
            }

            $delivery->fill([
                'provider' => $provider,
                'external_delivery_id' => $externalId,
                'status' => DeliveryStatus::Investigation,
                'assignment_status' => 'booking_confirmation_persistence_failed',
                'booking_phase' => 'confirmed',
                'booking_completed_at' => now(),
                'failure_code' => 'BOOKING_PERSISTENCE_FAILED',
                'failure_reason' => 'Provider confirmed a task, but its full assignment details could not be saved. Reconcile this known task; do not rebook.',
                'assignment_token' => null,
            ])->save();

            return true;
        });
        if ($markedForInvestigation) {
            $this->notifyManualActionRequired(
                'BOOKING_PERSISTENCE_FAILED',
                'Provider confirmed a task, but its assignment details could not be saved. Reconcile the known task; do not rebook.',
                true,
            );
        }
    }

    /** Preserve an ambiguous provider outcome when the queue worker terminates unexpectedly. */
    public function failed(?Throwable $exception): void
    {
        $markedForInvestigation = DB::transaction(function (): bool {
            $delivery = OrderDelivery::query()->where('tenant_id', $this->tenantId)
                ->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $delivery || $delivery->external_delivery_id || ! $delivery->booking_requested_at) {
                return false;
            }

            $delivery->fill([
                'status' => DeliveryStatus::Investigation,
                'assignment_status' => 'unknown_provider_result',
                'booking_phase' => 'ambiguous',
                'failure_code' => 'WORKER_TERMINATED_AFTER_BOOKING_STARTED',
                'failure_reason' => 'The worker stopped after provider transmission may have started. Do not retry booking.',
                'assignment_token' => null,
            ])->save();

            return true;
        });
        if ($markedForInvestigation) {
            $this->notifyManualActionRequired(
                'WORKER_TERMINATED_AFTER_BOOKING_STARTED',
                'The worker stopped after delivery-network transmission may have started. Check the provider dashboard before retrying.',
                true,
            );
        }
    }

    private function review(string $token, string $code, string $reason, array $audit = [], bool $ambiguous = false): void
    {
        DB::transaction(function () use ($token, $code, $reason, $audit, $ambiguous) {
            $delivery = $this->lockedCycle($token);
            if (! $delivery) {
                return;
            }
            $delivery->fill(['status' => $ambiguous ? DeliveryStatus::Investigation : DeliveryStatus::ManualReviewRequired,
                'assignment_status' => $ambiguous ? 'unknown_provider_result' : 'failed', 'failure_code' => $code,
                'failure_reason' => $reason, 'quote_history' => $audit,
                'booking_phase' => $ambiguous ? 'ambiguous' : $delivery->booking_phase,
                'assignment_token' => null]);
            $delivery->save();
        });
        $this->notifyManualActionRequired($code, $reason, $ambiguous);
    }

    private function notifyManualActionRequired(string $code, string $reason, bool $ambiguous): void
    {
        try {
            $order = Order::query()->withoutGlobalScopes()->with('branch:id,tenant_id,name,currency')->find($this->orderId);
            if (! $order || (int) $order->branch?->tenant_id !== $this->tenantId) {
                return;
            }
            $delivery = OrderDelivery::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)->where('order_id', $order->id)->first();
            $walletFailure = $code === 'DELIVERY_WALLET_INSUFFICIENT';
            $account = $walletFailure ? app(DeliveryWallet::class)->account($this->tenantId, $order->branch?->currency) : null;
            $required = $walletFailure ? (float) collect($delivery?->quote_history ?? [])->pluck('cost')->filter()->first() : null;
            $type = $walletFailure ? 'delivery_wallet_insufficient' : 'delivery_assignment_failed';
            $title = $walletFailure
                ? "Delivery Order Not Created · Wallet Balance Insufficient · Order #{$order->order_number}"
                : "Delivery Order Not Created · Order #{$order->order_number}";
            $message = $walletFailure
                ? sprintf('Order #%s was not sent to the delivery network. Available %s %.2f; required %s %.2f. Add funds, then use Review & send.',
                    $order->order_number, $order->branch?->currency ?: 'INR', (float) ($account?->available_balance ?? 0),
                    $order->branch?->currency ?: 'INR', (float) ($required ?? 0))
                : ($ambiguous
                    ? "Provider booking returned an uncertain result. {$reason} Check this task in the provider dashboard before taking any action; do not send it again."
                    : "The restaurant order was received, but its delivery order was not created. Reason: {$reason} Open the order, correct the issue, then use Review & Send.");

            TenantStaffRecipients::withAnyPermission((int) $this->tenantId, ['admin.orders.show', 'admin.delivery_settings.edit'])
                ->each(function (User $user) use ($order, $delivery, $code, $reason, $ambiguous, $walletFailure, $account, $required, $type, $title, $message): void {
                    $duplicate = DB::table('notifications')->where('target_user_id', $user->id)->where('type', $type)
                        ->where('payload->order_id', $order->id)->exists();
                    if ($duplicate) {
                        return;
                    }
                    app(NotificationServiceInterface::class)->create([
                        'title' => $title,
                        'message' => $message,
                        'type' => $type,
                        'severity' => $ambiguous ? 'error' : 'warning',
                        'icon' => $walletFailure ? 'tabler-wallet-off' : 'tabler-truck-off',
                        'color' => $ambiguous ? 'error' : 'warning',
                        'action_url' => '/admin/delivery?tab=orders&delivery_order='.$order->reference_no,
                        'payload' => [
                            'order_id' => $order->id, 'delivery_id' => $delivery?->id,
                            'order_reference' => $order->reference_no, 'order_number' => $order->order_number,
                            'branch_id' => $order->branch_id, 'failure_code' => $code, 'failure_reason' => $reason,
                            'manual_send_available' => ! $ambiguous,
                            'wallet_available' => $walletFailure ? (float) ($account?->available_balance ?? 0) : null,
                            'wallet_required' => $walletFailure ? (float) ($required ?? 0) : null,
                            'currency' => $walletFailure ? ($order->branch?->currency ?: 'INR') : null,
                        ],
                    ], $user);
                });
            SendStaffDeliveryActionAlert::dispatch(
                (int) $order->id,
                $this->tenantId,
                $walletFailure ? 'wallet-insufficient' : 'assignment-failed-'.$code,
                $walletFailure
                    ? sprintf('Delivery Wallet Insufficient. Available %s %.2f; Required %s %.2f. Add Funds And Send Again.',
                        $order->branch?->currency ?: 'INR', (float) ($account?->available_balance ?? 0),
                        $order->branch?->currency ?: 'INR', (float) ($required ?? 0))
                    : ($ambiguous
                        ? 'Delivery Booking Needs Review. Reason: '.$reason.' Check The Provider Dashboard Before Taking Action; Do Not Send Again.'
                        : 'Delivery Order Not Created. Reason: '.$reason.' Open The Order, Correct The Issue, Then Use Review And Send.'),
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to notify tenant administrators about failed delivery assignment.', [
                'tenant_id' => $this->tenantId, 'order_id' => $this->orderId, 'failure_code' => $code,
                'exception_type' => $exception::class, 'exception_message' => mb_substr($exception->getMessage(), 0, 500), 'exception_line' => $exception->getLine(),
            ]);
        }
    }

    private function recordAttempt(string $token, string $partnerCode, array $audit): bool
    {
        return DB::transaction(function () use ($token, $partnerCode, $audit): bool {
            $delivery = $this->lockedCycle($token);
            if (! $delivery) {
                return false;
            }
            $delivery->fill(['status' => DeliveryStatus::Assigning, 'partner_code' => $partnerCode, 'quote_history' => $audit, 'assignment_attempts' => $delivery->assignment_attempts + 1]);
            $delivery->save();

            return true;
        });
    }

    private function assigned(string $token, string $provider, $quote, string $externalId, array $audit, DeliveryCostCalculator $costs): void
    {
        DB::transaction(function () use ($token, $provider, $quote, $externalId, $audit, $costs) {
            $order = Order::query()->withoutGlobalScopes()->whereKey($this->orderId)->lockForUpdate()->first();
            $delivery = OrderDelivery::query()->where('tenant_id', $this->tenantId)->where('order_id', $this->orderId)->lockForUpdate()->first();
            if (! $delivery || $delivery->external_delivery_id) {
                return;
            }
            if (! $order || $order->payment_status !== OrderPaymentStatus::Paid
                || ! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready], true)) {
                $delivery->fill([
                    'provider' => $provider, 'external_delivery_id' => $externalId,
                    'status' => DeliveryStatus::ManualReviewRequired, 'assignment_status' => 'external_cancellation_required',
                    'failure_code' => 'ORDER_CLOSED_DURING_BOOKING',
                    'failure_reason' => 'Provider booked after the order closed. Contact the provider to cancel this delivery.',
                    'assignment_token' => null,
                ])->save();

                return;
            }
            if ($delivery->assignment_token !== $token) {
                return;
            }
            foreach ($audit as &$entry) {
                $entry['selected'] = $entry['quote_reference'] === $quote->reference;
                if ($entry['selected']) {
                    $entry['selection_reason'] = 'CHEAPEST_ELIGIBLE';
                }
            }
            unset($entry);
            $from = $delivery->status ?? DeliveryStatus::WaitingForAssignment;
            $delivery->fill([
                'provider' => $provider, 'partner_code' => $quote->partnerCode, 'partner_name' => $quote->partnerName,
                'external_delivery_id' => $externalId, 'status' => DeliveryStatus::RiderSearching, 'assignment_status' => 'assigned',
                'provider_quoted_cost' => $quote->cost, 'provider_final_cost' => $quote->cost,
                ...$costs->split((float) $delivery->customer_delivery_fee, $quote->cost),
                'eta_minutes' => $quote->etaMinutes, 'quote_history' => $audit, 'assigned_at' => now(),
                'booking_phase' => 'confirmed', 'booking_completed_at' => now(),
                'assignment_deadline_at' => now()->addMinutes(max(5, min(120, (int) (DB::table('branches')->where('id', $delivery->branch_id)->value('delivery_assignment_timeout_minutes') ?: 15)))),
                'assignment_escalated_at' => null, 'assignment_token' => null,
            ]);
            $delivery->save();
            if ($from !== DeliveryStatus::RiderSearching) {
                DeliveryStatusChanged::dispatch(
                    (int) $delivery->tenant_id,
                    (int) $delivery->id,
                    (int) $delivery->order_id,
                    $from,
                    DeliveryStatus::RiderSearching,
                );
            }
        });
    }

    private function lockedCycle(string $token): ?OrderDelivery
    {
        $order = Order::query()->withoutGlobalScopes()->whereKey($this->orderId)->lockForUpdate()->first();
        $delivery = OrderDelivery::query()->where('tenant_id', $this->tenantId)->where('order_id', $this->orderId)->lockForUpdate()->first();
        if (! $delivery || $delivery->assignment_token !== $token || $delivery->external_delivery_id) {
            return null;
        }

        return $order && $order->payment_status === OrderPaymentStatus::Paid
            && in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready], true) ? $delivery : null;
    }

    private function operationLog(string $operation, string $correlationId, string $result, float $startedAt, array $extra = []): void
    {
        Log::info('Delivery provider operation', array_filter([
            'delivery_id' => OrderDelivery::query()->where('tenant_id', $this->tenantId)->where('order_id', $this->orderId)->value('id'),
            'order_id' => $this->orderId, 'tenant_id' => $this->tenantId,
            'branch_id' => OrderDelivery::query()->where('tenant_id', $this->tenantId)->where('order_id', $this->orderId)->value('branch_id'),
            'provider' => (string) config('delivery.provider', 'uengage'),
            'environment' => (string) config('delivery.uengage.environment', 'sandbox'),
            'operation' => $operation, 'correlation_id' => $correlationId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'result' => $result,
            ...$extra,
        ], static fn ($value) => $value !== null));
    }
}
