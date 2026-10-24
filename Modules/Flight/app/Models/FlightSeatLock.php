<?php

namespace Modules\Flight\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Flight\Enums\SeatLockStatus;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class FlightSeatLock extends Model
{
    protected $table = 'flight_seat_locks';

    protected $fillable = [
        'tenant_id',
        'flight_inventory_id',
        'seat_number',
        'lock_token',
        'user_id',
        'booking_id',
        'passenger_id',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'status'     => SeatLockStatus::class,
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->lock_token)) {
                $model->lock_token = 'SEATLOCK-' . strtoupper(Str::random(16));
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function flightInventory(): BelongsTo
    {
        return $this->belongsTo(FlightInventory::class, 'flight_inventory_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'booking_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(FlightBookingPassenger::class, 'passenger_id');
    }

    public function isExpired(): bool
    {
        return $this->status === SeatLockStatus::EXPIRED ||
            ($this->status === SeatLockStatus::LOCKED && $this->expires_at->isPast());
    }

    public function getRemainingSecondsAttribute(): int
    {
        if ($this->status !== SeatLockStatus::LOCKED || $this->expires_at->isPast()) {
            return 0;
        }

        return max(0, Carbon::now()->diffInSeconds($this->expires_at, false));
    }
}
