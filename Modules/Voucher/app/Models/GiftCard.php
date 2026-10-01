<?php

namespace Modules\Voucher\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $customer_id
 * @property string $code
 * @property float $initial_balance
 * @property float $current_balance
 * @property float $total_used
 * @property string $status
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class GiftCard extends Model
{
    use HasActivityLog, HasBranch, HasCreatedBy, HasFilters, HasSortBy, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'customer_id',
        'code',
        'initial_balance',
        'current_balance',
        'total_used',
        'status',
        'issued_at',
        'expires_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:4',
            'current_balance' => 'decimal:4',
            'total_used' => 'decimal:4',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->latest();
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'code',
            'status',
            'customer_id',
            'from',
            'to',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    protected function getSortableAttributes(): array
    {
        return [
            'code',
            'status',
            'initial_balance',
            'current_balance',
            'total_used',
            'issued_at',
            'expires_at',
            'created_at',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function hasSufficientBalance(float $amount): bool
    {
        return (float) $this->current_balance >= $amount
            && ! $this->isExpired()
            && $this->status === 'active';
    }

    public function scopeSearch($query, string $value): void
    {
        $query->where(fn ($searchQuery) => $searchQuery
            ->where('code', 'like', "%{$value}%")
            ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('name', 'like', "%{$value}%")));
    }
}
