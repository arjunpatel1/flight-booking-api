<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Order\Models\OrderProduct;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasSortBy;

class KitchenStationOrderProduct extends Model
{
    use HasActivityLog, HasCreatedBy, HasSortBy;

    protected $table = 'kitchen_station_order_products';

    protected $appends = [
        'prep_time_display',
        'time_remaining',
        'is_delayed',
    ];

    protected $fillable = [
        'kitchen_station_id',
        'order_product_id',
        'status',
        'prep_started_at',
        'prep_completed_at',
        'estimated_completion_at',
        'prep_time_minutes',
        'priority',
        'notes',
        'bumped_by',
        'bumped_at',
    ];

    protected $casts = [
        'prep_started_at' => 'datetime',
        'prep_completed_at' => 'datetime',
        'estimated_completion_at' => 'datetime',
        'prep_time_minutes' => 'integer',
        'priority' => 'integer',
        'bumped_at' => 'datetime',
    ];

    protected function getSortableAttributes(): array
    {
        return ['kitchen_station_id', 'status', 'priority', 'estimated_completion_at'];
    }

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function orderProduct(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class);
    }

    public function bumpedBy(): BelongsTo
    {
        return $this->belongsTo(\Modules\User\Models\User::class, 'bumped_by');
    }

    /**
     * Get human-readable prep time.
     */
    public function getPrepTimeDisplayAttribute(): string
    {
        if ($this->prep_time_minutes < 60) {
            return "{$this->prep_time_minutes}m";
        }

        $hours = floor($this->prep_time_minutes / 60);
        $minutes = $this->prep_time_minutes % 60;

        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }

    /**
     * Check if item is delayed.
     */
    public function isDelayed(): bool
    {
        return $this->estimated_completion_at && 
               $this->estimated_completion_at->isPast() && 
               $this->status !== 'completed';
    }

    public function getIsDelayedAttribute(): bool
    {
        return $this->isDelayed();
    }

    /**
     * Get time remaining for prep.
     */
    public function getTimeRemainingAttribute(): ?string
    {
        if (!$this->estimated_completion_at || $this->status === 'completed') {
            return null;
        }

        $remaining = now()->diffInMinutes($this->estimated_completion_at, false);
        
        if ($remaining <= 0) {
            return 'Overdue';
        }

        if ($remaining < 60) {
            return "{$remaining}m";
        }

        $hours = floor($remaining / 60);
        $minutes = $remaining % 60;

        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }
}
