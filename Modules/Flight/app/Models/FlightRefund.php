<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Flight\Enums\RefundMethod;
use Modules\Flight\Enums\RefundStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;

class FlightRefund extends Model
{
    use SoftDeletes;

    protected $table = 'flight_refunds';

    protected $fillable = [
        'tenant_id',
        'flight_booking_id',
        'flight_payment_id',
        'refund_reference',
        'supplier_refund_reference',
        'refund_type',
        'refund_method',
        'original_amount',
        'cancellation_penalty',
        'refund_amount',
        'currency',
        'status',
        'gateway_refund_id',
        'wallet_transaction_id',
        'reason',
        'failure_reason',
        'audit_meta',
        'processed_at',
    ];

    protected $casts = [
        'original_amount'      => 'decimal:2',
        'cancellation_penalty' => 'decimal:2',
        'refund_amount'        => 'decimal:2',
        'status'               => RefundStatus::class,
        'refund_method'        => RefundMethod::class,
        'audit_meta'           => 'array',
        'processed_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->refund_reference)) {
                $model->refund_reference = 'REF-' . strtoupper(Str::random(10));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FlightPayment::class, 'flight_payment_id');
    }
}
