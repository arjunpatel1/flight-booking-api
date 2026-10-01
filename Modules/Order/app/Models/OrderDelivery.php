<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Enums\DeliveryStatus;

class OrderDelivery extends Model
{
    use \Modules\Saas\Traits\BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (self $delivery): void {
            $ownership = \Illuminate\Support\Facades\DB::table('orders')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.id', $delivery->order_id)
                ->select('orders.branch_id', 'branches.tenant_id')->first();
            $actor = auth()->user();
            if (! $ownership || (int) $ownership->branch_id !== (int) $delivery->branch_id
                || (int) $ownership->tenant_id !== (int) $delivery->tenant_id
                || ($actor?->assignedToTenant() && ! $actor->isSuperAdmin()
                    && (int) $actor->tenant_id !== (int) $delivery->tenant_id)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'delivery' => 'Delivery ownership must match the order, outlet and tenant.',
                ]);
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'branch_id', 'order_id', 'mode', 'provider', 'partner_code', 'partner_name',
        'external_delivery_id', 'external_partner_id', 'status', 'assignment_status',
        'pickup_latitude', 'pickup_longitude', 'dropoff_latitude', 'dropoff_longitude',
        'distance_km', 'customer_delivery_fee', 'provider_quoted_cost', 'provider_final_cost',
        'restaurant_contribution', 'platform_contribution', 'delivery_margin', 'eta_minutes',
        'rider_name', 'rider_phone', 'delivery_otp', 'rider_vehicle', 'tracking_url', 'failure_code', 'failure_reason',
        'assignment_attempts', 'quote_history', 'attempt_history', 'assignment_token', 'assignment_started_at',
        'provider_correlation_id', 'booking_phase', 'provider_status', 'booking_claimed_at',
        'booking_requested_at', 'booking_completed_at',
        'assigned_at', 'rider_assigned_at', 'assignment_deadline_at', 'assignment_escalated_at', 'arrived_at_pickup_at', 'picked_up_at',
        'arrived_at_customer_at', 'delivered_at', 'cancelled_at',
    ];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'delivery_otp' => 'encrypted',
        'quote_history' => 'array',
        'attempt_history' => 'array',
        'pickup_latitude' => 'float', 'pickup_longitude' => 'float',
        'dropoff_latitude' => 'float', 'dropoff_longitude' => 'float', 'distance_km' => 'float',
        'customer_delivery_fee' => 'decimal:4', 'provider_quoted_cost' => 'decimal:4',
        'provider_final_cost' => 'decimal:4', 'restaurant_contribution' => 'decimal:4',
        'platform_contribution' => 'decimal:4', 'delivery_margin' => 'decimal:4',
        'assignment_started_at' => 'datetime', 'assigned_at' => 'datetime',
        'booking_claimed_at' => 'datetime', 'booking_requested_at' => 'datetime', 'booking_completed_at' => 'datetime',
        'rider_assigned_at' => 'datetime', 'assignment_deadline_at' => 'datetime', 'assignment_escalated_at' => 'datetime', 'arrived_at_pickup_at' => 'datetime', 'picked_up_at' => 'datetime',
        'arrived_at_customer_at' => 'datetime',
        'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
