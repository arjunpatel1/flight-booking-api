<?php

namespace Modules\SeatingPlan\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\ReservationType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class TableReservation extends Model
{
    use HasActivityLog,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy,
        SoftDeletes;

    protected $fillable = [
        'reference_no',
        'booking_type',
        'branch_id',
        'customer_id',
        'table_id',
        'table_ids',
        'hall_name',
        'event_title',
        'customer_name',
        'customer_phone',
        'customer_email',
        'guest_count',
        'reservation_date',
        'reservation_time',
        'duration_minutes',
        'deposit_amount',
        'status',
        'special_requests',
        'cancel_reason',
        'confirmed_at',
        'seated_at',
        'completed_at',
        'cancelled_at',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class)->withTrashed();
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'booking_type',
            'branch_id',
            'table_id',
            'status',
            'date',
            'from',
            'to',
            'guest_count',
            'min_guest_count',
            'max_guest_count',
        ];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('reference_no', "%{$value}%")
            ->orWhereLike('customer_name', "%{$value}%")
            ->orWhereLike('customer_phone', "%{$value}%")
            ->orWhereLike('hall_name', "%{$value}%")
            ->orWhereLike('event_title', "%{$value}%");
    }

    public function scopeDate(Builder $query, string $date): void
    {
        $query->whereDate('reservation_date', $date);
    }

    public function scopeMinGuestCount(Builder $query, int $count): void
    {
        $query->where('guest_count', '>=', $count);
    }

    public function scopeMaxGuestCount(Builder $query, int $count): void
    {
        $query->where('guest_count', '<=', $count);
    }

    protected function casts(): array
    {
        return [
            'booking_type' => ReservationType::class,
            'status' => ReservationStatus::class,
            'guest_count' => 'int',
            'duration_minutes' => 'int',
            'deposit_amount' => 'decimal:4',
            'table_ids' => 'array',
            'reservation_date' => 'date',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'reference_no',
            'booking_type',
            'reservation_date',
            'reservation_time',
            'guest_count',
            'status',
            'created_at',
        ];
    }
}
