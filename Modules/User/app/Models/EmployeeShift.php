<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class EmployeeShift extends Model
{
    use SoftDeletes,
        HasActivityLog,
        HasActiveStatus,
        HasBranch,
        HasCreatedBy,
        HasFilters,
        HasSortBy;

    public static string $defaultDateColumn = 'created_at';

    protected $fillable = [
        'branch_id',
        'user_id',
        'name',
        'starts_at',
        'ends_at',
        'break_minutes',
        'is_active',
        'notes',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withOutGlobalBranchPermission()->withoutGlobalActive()->withTrashed();
    }

    public function startsAt(): Attribute
    {
        return Attribute::get(fn($value) => $value ? substr((string) $value, 0, 5) : null);
    }

    public function endsAt(): Attribute
    {
        return Attribute::get(fn($value) => $value ? substr((string) $value, 0, 5) : null);
    }

    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'user_id',
            'is_active',
            'from',
            'to',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    public function scopeSearch($query, string $value): void
    {
        $query->where(function ($query) use ($value) {
            $query->like('name', $value)
                ->orWhereHas('user', fn($query) => $query->like('name', $value));
        });
    }

    protected function getSortableAttributes(): array
    {
        return [
            'name',
            'starts_at',
            'ends_at',
            'break_minutes',
            'is_active',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
