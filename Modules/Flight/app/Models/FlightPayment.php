<?php

namespace Modules\Flight\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Flight\Enums\FlightPaymentMethod;
use Modules\Flight\Enums\FlightPaymentStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightPayment extends Model
{
    use SoftDeletes;

    protected $table = 'flight_payments';

    protected $fillable = [
        'tenant_id',
        'flight_booking_id',
        'flight_fare_quote_id',
        'flight_hold_id',
        'user_id',
        'payer_type',
        'payer_id',
        'payment_method',
        'gateway',
        'gateway_order_id',
        'gateway_transaction_id',
        'payment_reference',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'gateway_response_metadata',
        'reconciliation_reference',
        'initiated_at',
        'authorized_at',
        'completed_at',
        'failed_at',
        'refunded_amount',
    ];

    protected $casts = [
        'amount'                    => 'decimal:2',
        'refunded_amount'           => 'decimal:2',
        'status'                    => FlightPaymentStatus::class,
        'gateway_response_metadata' => 'array',
        'initiated_at'              => 'datetime',
        'authorized_at'             => 'datetime',
        'completed_at'              => 'datetime',
        'failed_at'                 => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->payment_reference)) {
                $model->payment_reference = 'FPAY-' . strtoupper(Str::random(10));
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(FlightFareQuote::class, 'flight_fare_quote_id');
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(FlightHold::class, 'flight_hold_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── State Machine Transition ──────────────────────────────────────────────

    public function transitionTo(FlightPaymentStatus $newStatus, array $extraAttributes = []): void
    {
        $this->status->assertCanTransitionTo($newStatus);

        $updates = array_merge(['status' => $newStatus], $extraAttributes);

        if ($newStatus === FlightPaymentStatus::COMPLETED && empty($updates['completed_at'])) {
            $updates['completed_at'] = now();
        } elseif ($newStatus === FlightPaymentStatus::AUTHORIZED && empty($updates['authorized_at'])) {
            $updates['authorized_at'] = now();
        } elseif ($newStatus === FlightPaymentStatus::FAILED && empty($updates['failed_at'])) {
            $updates['failed_at'] = now();
        }

        $this->update($updates);
    }
}
