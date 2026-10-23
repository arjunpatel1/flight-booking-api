<?php

namespace Modules\Flight\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Enums\FlightNotificationEvent;
use Modules\Flight\Events\FlightBookingCancelled;
use Modules\Flight\Events\FlightBookingCreated;
use Modules\Flight\Events\FlightBookingRescheduled;
use Modules\Flight\Events\FlightHoldCreated;
use Modules\Flight\Events\FlightHoldExpired;
use Modules\Flight\Events\FlightPaymentConfirmed;
use Modules\Flight\Events\FlightPaymentFailed;
use Modules\Flight\Events\FlightPaymentInitiated;
use Modules\Flight\Events\FlightPnrGenerated;
use Modules\Flight\Events\FlightRefundCompleted;
use Modules\Flight\Events\FlightRefundInitiated;
use Modules\Flight\Events\FlightTicketFailed;
use Modules\Flight\Events\FlightTicketIssued;
use Modules\Flight\Events\FlightWalletDebited;
use Modules\Flight\Events\FlightWalletRefunded;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Notification\FlightNotificationService;
use Modules\User\Models\User;
use Throwable;

class FlightNotificationSubscriber
{
    public function __construct(
        protected FlightNotificationService $notificationService
    ) {}

    public function handleBookingCreated(FlightBookingCreated $event): void
    {
        try {
            $booking = $event->booking;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::BOOKING_CREATED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'booking_confirmation',
                data:       $this->extractBookingPayload($booking),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on booking created notification', ['error' => $e->getMessage()]);
        }
    }

    public function handlePaymentInitiated(FlightPaymentInitiated $event): void
    {
        try {
            $booking = $event->booking;
            $payment = $event->payment;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::PAYMENT_INITIATED,
                recipients: ['email' => $booking->contact_email, 'phone' => $booking->contact_phone],
                template:   'payment_initiated',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'amount'            => $payment->amount,
                    'payment_reference' => $payment->payment_reference,
                ]),
                booking:    $booking,
                channels:   ['email']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on payment initiated notification', ['error' => $e->getMessage()]);
        }
    }

    public function handlePaymentConfirmed(FlightPaymentConfirmed $event): void
    {
        try {
            $booking = $event->booking;
            $payment = $event->payment;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::PAYMENT_SUCCESS,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'payment_success',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'amount'            => $payment->amount,
                    'payment_reference' => $payment->payment_reference,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on payment confirmed notification', ['error' => $e->getMessage()]);
        }
    }

    public function handlePaymentFailed(FlightPaymentFailed $event): void
    {
        try {
            $booking = $event->booking;
            $payment = $event->payment;
            $email = $booking?->contact_email;
            $phone = $booking?->contact_phone;

            if ($booking) {
                $this->notificationService->dispatchNotification(
                    event:      FlightNotificationEvent::PAYMENT_FAILED,
                    recipients: ['email' => $email, 'phone' => $phone],
                    template:   'payment_failure',
                    data:       array_merge($this->extractBookingPayload($booking), [
                        'amount' => $payment->amount,
                        'reason' => $event->reason,
                    ]),
                    booking:    $booking,
                    channels:   ['email', 'sms']
                );
            }
        } catch (Throwable $e) {
            Log::warning('Subscriber error on payment failed notification', ['error' => $e->getMessage()]);
        }
    }

    public function handlePnrGenerated(FlightPnrGenerated $event): void
    {
        try {
            $booking = $event->booking;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::PNR_GENERATED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'pnr_generated',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'pnr' => $event->pnr,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on pnr generated notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleTicketIssued(FlightTicketIssued $event): void
    {
        try {
            $booking = $event->booking;
            $tickets = $event->tickets;
            $firstTicket = $tickets[0] ?? null;

            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::TICKET_ISSUED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'ticket_issued',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'ticket_number'     => $firstTicket?->ticket_number ?? 'N/A',
                    'eticket_reference' => $firstTicket?->e_ticket_number ?? 'N/A',
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on ticket issued notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleTicketFailed(FlightTicketFailed $event): void
    {
        try {
            $booking = $event->booking;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::TICKET_FAILED,
                recipients: ['email' => $booking->contact_email, 'phone' => $booking->contact_phone],
                template:   'ticket_failed',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'reason' => $event->reason,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on ticket failed notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleHoldCreated(FlightHoldCreated $event): void
    {
        try {
            $hold = $event->hold;
            $meta = $hold->meta ?? [];
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::HOLD_CREATED,
                recipients: ['email' => $meta['contact_email'] ?? 'guest@nexdine.com', 'phone' => $meta['contact_phone'] ?? '9999999999'],
                template:   'hold_created',
                data:       [
                    'booking_reference' => $hold->reference ?? $hold->hold_id,
                    'flight_number'     => $meta['flight_number'] ?? 'Flight',
                    'origin'            => $meta['origin'] ?? '',
                    'destination'       => $meta['destination'] ?? '',
                    'quantity'          => $hold->quantity,
                    'expires_at'        => (string) $hold->expires_at,
                ],
                booking:    null,
                tenantId:   $hold->tenant_id,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on hold created notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleHoldExpired(FlightHoldExpired $event): void
    {
        try {
            $hold = $event->hold;
            $meta = $hold->meta ?? [];
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::HOLD_EXPIRED,
                recipients: ['email' => $meta['contact_email'] ?? 'guest@nexdine.com', 'phone' => $meta['contact_phone'] ?? '9999999999'],
                template:   'hold_expiry',
                data:       [
                    'booking_reference' => $hold->reference ?? $hold->hold_id,
                    'flight_number'     => $meta['flight_number'] ?? 'Flight',
                    'status'            => 'EXPIRED',
                ],
                booking:    null,
                tenantId:   $hold->tenant_id,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on hold expired notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleBookingCancelled(FlightBookingCancelled $event): void
    {
        try {
            $booking = $event->booking;
            $refund = $event->refund;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::BOOKING_CANCELLED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'cancellation_confirmation',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'refund_amount' => $refund?->refund_amount ?? 0.00,
                    'reason'        => $refund?->reason ?? 'Cancelled',
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on booking cancelled notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleRefundInitiated(FlightRefundInitiated $event): void
    {
        try {
            $booking = $event->booking;
            $refund = $event->refund;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::REFUND_INITIATED,
                recipients: ['email' => $booking->contact_email, 'phone' => $booking->contact_phone],
                template:   'refund_initiated',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'refund_amount'     => $refund->refund_amount,
                    'eticket_reference' => $refund->refund_reference,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on refund initiated notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleRefundCompleted(FlightRefundCompleted $event): void
    {
        try {
            $booking = $event->booking;
            $refund = $event->refund;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::REFUND_COMPLETED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'refund_completed',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'refund_amount'     => $refund->refund_amount,
                    'eticket_reference' => $refund->refund_reference,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on refund completed notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleBookingRescheduled(FlightBookingRescheduled $event): void
    {
        try {
            $booking = $event->booking;
            $amendment = $event->amendment;
            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::RESCHEDULE_COMPLETED,
                recipients: [
                    'email'    => $booking->contact_email,
                    'phone'    => $booking->contact_phone,
                    'whatsapp' => $booking->contact_phone,
                ],
                template:   'reschedule_confirmation',
                data:       array_merge($this->extractBookingPayload($booking), [
                    'pnr'           => $booking->pnr,
                    'reschedule_id' => $amendment->amendment_id ?? $amendment->id,
                ]),
                booking:    $booking,
                channels:   ['email', 'sms', 'whatsapp']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on booking rescheduled notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleWalletDebited(FlightWalletDebited $event): void
    {
        try {
            $txn = $event->transaction;
            $wallet = $event->wallet;
            $ownerEmail = null;
            $ownerPhone = null;
            if ($wallet->owner_id) {
                // Check if owner is an Agent
                $agent = DB::table('flight_agents')->where('id', $wallet->owner_id)->orWhere('user_id', $wallet->owner_id)->first();
                if ($agent) {
                    $ownerEmail = $agent->email ?? null;
                    $ownerPhone = $agent->phone ?? null;
                }
                // Check if owner is a Distributor
                if (! $ownerEmail) {
                    $dist = DB::table('flight_distributors')->where('id', $wallet->owner_id)->orWhere('user_id', $wallet->owner_id)->first();
                    if ($dist) {
                        $ownerEmail = $dist->email ?? null;
                        $ownerPhone = $dist->phone ?? null;
                    }
                }
                // Check direct User
                if (! $ownerEmail) {
                    $user = DB::table('users')->where('id', $wallet->owner_id)->first();
                    if ($user) {
                        $ownerEmail = $user->email ?? null;
                        $ownerPhone = $user->phone ?? null;
                    }
                }
            }
            $recipientEmail = $ownerEmail ?? ($wallet->contact_email ?? 'partner@nexdine.com');
            $recipientPhone = $ownerPhone ?? ($wallet->contact_phone ?? '9999999999');

            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::WALLET_DEBITED,
                recipients: ['email' => $recipientEmail, 'phone' => $recipientPhone],
                template:   'wallet_debited',
                data:       [
                    'booking_reference' => $txn->reference,
                    'amount'            => $txn->amount,
                    'currency'          => $txn->currency,
                    'transaction_id'    => $txn->transaction_id,
                ],
                booking:    null,
                tenantId:   $wallet->tenant_id,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on wallet debited notification', ['error' => $e->getMessage()]);
        }
    }

    public function handleWalletRefunded(FlightWalletRefunded $event): void
    {
        try {
            $txn = $event->transaction;
            $wallet = $event->wallet;
            $ownerEmail = null;
            $ownerPhone = null;
            if ($wallet->owner_id) {
                // Check if owner is an Agent
                $agent = DB::table('flight_agents')->where('id', $wallet->owner_id)->orWhere('user_id', $wallet->owner_id)->first();
                if ($agent) {
                    $ownerEmail = $agent->email ?? null;
                    $ownerPhone = $agent->phone ?? null;
                }
                // Check if owner is a Distributor
                if (! $ownerEmail) {
                    $dist = DB::table('flight_distributors')->where('id', $wallet->owner_id)->orWhere('user_id', $wallet->owner_id)->first();
                    if ($dist) {
                        $ownerEmail = $dist->email ?? null;
                        $ownerPhone = $dist->phone ?? null;
                    }
                }
                // Check direct User
                if (! $ownerEmail) {
                    $user = DB::table('users')->where('id', $wallet->owner_id)->first();
                    if ($user) {
                        $ownerEmail = $user->email ?? null;
                        $ownerPhone = $user->phone ?? null;
                    }
                }
            }
            $recipientEmail = $ownerEmail ?? ($wallet->contact_email ?? 'partner@nexdine.com');
            $recipientPhone = $ownerPhone ?? ($wallet->contact_phone ?? '9999999999');

            $this->notificationService->dispatchNotification(
                event:      FlightNotificationEvent::WALLET_REFUNDED,
                recipients: ['email' => $recipientEmail, 'phone' => $recipientPhone],
                template:   'wallet_refunded',
                data:       [
                    'booking_reference' => $txn->reference,
                    'refund_amount'     => $txn->amount,
                    'currency'          => $txn->currency,
                    'transaction_id'    => $txn->transaction_id,
                ],
                booking:    null,
                tenantId:   $wallet->tenant_id,
                channels:   ['email', 'sms']
            );
        } catch (Throwable $e) {
            Log::warning('Subscriber error on wallet refunded notification', ['error' => $e->getMessage()]);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FlightBookingCreated::class     => 'handleBookingCreated',
            FlightPaymentInitiated::class   => 'handlePaymentInitiated',
            FlightPaymentConfirmed::class   => 'handlePaymentConfirmed',
            FlightPaymentFailed::class      => 'handlePaymentFailed',
            FlightPnrGenerated::class       => 'handlePnrGenerated',
            FlightTicketIssued::class       => 'handleTicketIssued',
            FlightTicketFailed::class       => 'handleTicketFailed',
            FlightHoldCreated::class        => 'handleHoldCreated',
            FlightHoldExpired::class        => 'handleHoldExpired',
            FlightBookingCancelled::class   => 'handleBookingCancelled',
            FlightRefundInitiated::class    => 'handleRefundInitiated',
            FlightRefundCompleted::class    => 'handleRefundCompleted',
            FlightBookingRescheduled::class => 'handleBookingRescheduled',
            FlightWalletDebited::class      => 'handleWalletDebited',
            FlightWalletRefunded::class     => 'handleWalletRefunded',
        ];
    }

    protected function extractBookingPayload(FlightBooking $booking): array
    {
        $inv = $booking->flightInventory;

        return [
            'booking_reference' => $booking->booking_reference,
            'pnr'               => $booking->pnr ?? 'PENDING',
            'passenger_name'    => $booking->contact_name ?? 'Valued Customer',
            'contact_name'      => $booking->contact_name,
            'flight_number'     => $inv?->flight_number ?? 'Flight',
            'origin'            => $inv?->originAirport?->iata_code ?? 'DEP',
            'destination'       => $inv?->destinationAirport?->iata_code ?? 'ARR',
            'departure'         => $inv?->departure_time ? (string) $inv->departure_time : '',
            'amount'            => (float) $booking->total_amount,
            'currency'          => $booking->currency ?: 'INR',
        ];
    }
}
