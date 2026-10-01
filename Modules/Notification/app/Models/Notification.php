<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class Notification extends Model
{
    use HasCreatedBy, HasFilters, HasSortBy;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'target_user_id',
        'title',
        'message',
        'type',
        'severity',
        'icon',
        'color',
        'action_url',
        'payload',
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'created_by',
        'read_at',
        'dismissed_at',
        'archived_at',
        'deleted_at',
    ];

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function allowedFilterKeys(): array
    {
        return ['search', 'type', 'severity', 'read_status', 'state', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(fn (Builder $search) => $search
            ->whereLike('title', "%{$value}%")
            ->orWhereLike('message', "%{$value}%"));
    }

    public function scopeReadStatus(Builder $query, string $value): void
    {
        if ($value === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($value === 'unread') {
            $query->whereNull('read_at');
        }
    }

    public function scopeState(Builder $query, string $value): void
    {
        match ($value) {
            'unread' => $query->whereNull('read_at')->whereNull('dismissed_at')->whereNull('archived_at'),
            'read' => $query->whereNotNull('read_at')->whereNull('dismissed_at')->whereNull('archived_at'),
            'dismissed' => $query->whereNotNull('dismissed_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'severity' => NotificationSeverity::class,
            'payload' => 'array',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'archived_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
