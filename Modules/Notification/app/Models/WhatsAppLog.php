<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Modules\Branch\Traits\HasBranch;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class WhatsAppLog extends Model
{
    use HasBranch, HasCreatedBy, HasFilters, HasSortBy;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'provider',
        'recipient',
        'template',
        'status',
        'request_payload',
        'retry_payload',
        'response_payload',
        'error_message',
        'delivery_status',
        'sent_at',
        'delivered_at',
        'failed_attempts',
    ];

    protected $hidden = [
        'retry_payload',
    ];

    public function allowedFilterKeys(): array
    {
        return ['search', 'provider', 'status', 'delivery_status', 'campaign_id', 'audience', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('recipient', "%{$value}%")
            ->orWhereLike('template', "%{$value}%");
    }

    public function scopeDeliveryPending(Builder $query): void
    {
        $query->whereNull('delivered_at')
            ->where('status', NotificationStatus::Sent);
    }

    public function scopeRetryable(Builder $query): void
    {
        $query->where('status', NotificationStatus::Failed)
            ->where('failed_attempts', '<', 3)
            ->where('created_at', '>=', now()->subHours(24));
    }

    public function scopeCampaignId(Builder $query, string $value): void
    {
        $query->where('request_payload->campaign_id', $value);
    }

    public function scopeAudience(Builder $query, string $value): void
    {
        $query->where('request_payload->audience', $value);
    }

    public function scopeForTenant(Builder $query, int $tenantId, ?int $branchId = null): void
    {
        $query->where('tenant_id', $tenantId)
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId));
    }

    protected function casts(): array
    {
        return [
            'provider' => WhatsAppProvider::class,
            'status' => NotificationStatus::class,
            'request_payload' => 'array',
            // Template values may contain personal/order data. They are
            // retained only to make an authorized retry possible and are
            // encrypted at rest, never returned in log resources.
            'retry_payload' => 'encrypted:array',
            'response_payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_attempts' => 'int',
        ];
    }
}
