<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class NotificationLog extends Model
{
    use HasCreatedBy, HasFilters, HasSortBy;

    protected $fillable = [
        'tenant_id',
        'type',
        'channel',
        'recipient',
        'status',
        'payload',
        'response',
        'error_message',
        'queued_at',
        'sent_at',
        'failed_at',
    ];

    public function allowedFilterKeys(): array
    {
        return ['search', 'type', 'channel', 'status', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(fn (Builder $search) => $search
            ->whereLike('type', "%{$value}%")
            ->orWhereLike('recipient', "%{$value}%"));
    }

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'response' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
