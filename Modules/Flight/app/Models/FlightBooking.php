<?php

namespace Modules\Flight\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Flight\Enums\BookingStatus;
use Modules\Flight\Enums\PaymentStatus;
use Modules\Flight\Enums\TicketStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightBooking extends Model
{
    use SoftDeletes;

    protected $table = 'flight_bookings';

    protected $fillable = [
        'booking_reference',
        'pnr',
        'tenant_id',
        'user_id',
        'flight_customer_id',
        'quote_id',
        'quote_reference',
        'hold_id',
        'hold_reference',
        'flight_inventory_id',
        'flight_fare_id',
        'supplier_code',
        'supplier_booking_reference',
        'supplier_pnr',
        'manual_pnr_required',
        'itinerary',
        'fare_snapshot',
        'pricing_breakdown',
        'total_amount',
        'currency',
        'booking_status',
        'ticket_status',
        'payment_status',
        'payment_reference',
        'pax_count_adult',
        'pax_count_child',
        'pax_count_infant',
        'contact_name',
        'contact_email',
        'contact_phone',
        'idempotency_key',
        'cancellation_reason',
        'cancellation_fee',
        'refunded_amount',
        'ticketing_error',
        'eticket_pdf_path',
        'meta',
        'confirmed_at',
        'ticketed_at',
        'cancelled_at',
        'rescheduled_at',
        'reschedule_count',
    ];

    protected $casts = [
        'itinerary'         => 'array',
        'fare_snapshot'     => 'array',
        'pricing_breakdown' => 'array',
        'meta'              => 'array',
        'manual_pnr_required' => 'boolean',
        'total_amount'      => 'decimal:2',
        'cancellation_fee'  => 'decimal:2',
        'refunded_amount'   => 'decimal:2',
        'booking_status'    => BookingStatus::class,
        'ticket_status'     => TicketStatus::class,
        'payment_status'    => PaymentStatus::class,
        'pax_count_adult'   => 'integer',
        'pax_count_child'   => 'integer',
        'pax_count_infant'  => 'integer',
        'reschedule_count'  => 'integer',
        'confirmed_at'      => 'datetime',
        'ticketed_at'       => 'datetime',
        'cancelled_at'      => 'datetime',
        'rescheduled_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->booking_reference)) {
                $model->booking_reference = 'BKNG-' . strtoupper(Str::random(10));
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(FlightBookingPassenger::class, 'booking_id');
    }

    public function ancillaries(): HasMany
    {
        return $this->hasMany(FlightBookingAncillary::class, 'booking_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FlightPayment::class, 'flight_booking_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FlightTicket::class, 'flight_booking_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(FlightRefund::class, 'flight_booking_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(FlightBookingAmendment::class, 'flight_booking_id');
    }

    public function isTicketed(): bool
    {
        $st = is_object($this->ticket_status) ? $this->ticket_status->value : (string) $this->ticket_status;
        return strtoupper($st) === 'ISSUED';
    }

    public function hasPnr(): bool
    {
        return ! empty($this->pnr);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FlightFareQuote::class, 'quote_id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(FlightHold::class, 'hold_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function flightFare(): BelongsTo
    {
        return $this->belongsTo(FlightFare::class, 'flight_fare_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $query, ?int $tenantId): void
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
    }

    public function scopeByStatus(Builder $query, string|BookingStatus $status): void
    {
        $statusValue = $status instanceof BookingStatus ? $status->value : $status;
        $query->where('booking_status', $statusValue);
    }

    // ── State Machine Transitions ─────────────────────────────────────────────

    public function canTransitionTo(string|BookingStatus $targetStatus): bool
    {
        $current = $this->booking_status instanceof BookingStatus
            ? $this->booking_status
            : BookingStatus::from($this->booking_status);

        $target = $targetStatus instanceof BookingStatus
            ? $targetStatus
            : BookingStatus::from($targetStatus);

        return $current->canTransitionTo($target);
    }

    public function transitionTo(string|BookingStatus $targetStatus, ?string $reason = null): void
    {
        $current = $this->booking_status instanceof BookingStatus
            ? $this->booking_status
            : BookingStatus::from($this->booking_status);

        $target = $targetStatus instanceof BookingStatus
            ? $targetStatus
            : BookingStatus::from($targetStatus);

        if (! $current->canTransitionTo($target)) {
            throw new DomainException(
                "Invalid booking status transition: cannot change from {$current->value} to {$target->value}."
            );
        }

        $this->booking_status = $target;

        if (in_array($target, [BookingStatus::PNR_PENDING, BookingStatus::CONFIRMED], true)) {
            $this->confirmed_at = now();
        } elseif ($target === BookingStatus::CANCELLED) {
            $this->cancelled_at = now();
            if ($reason) {
                $this->cancellation_reason = $reason;
            }
        }

        $this->save();
    }
}
