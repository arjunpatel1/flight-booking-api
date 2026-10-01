<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;

class CustomerAppContentItem extends Model
{
    use SoftDeletes;

    public const TYPE_SLIDER = 'slider';
    public const TYPE_BANNER = 'banner';
    public const TYPE_OFFER = 'offer';
    public const TYPE_EVENT = 'event';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EXPIRED = 'expired';

    public const CTA_OPEN_MENU = 'open_menu';
    public const CTA_OPEN_OFFER = 'open_offer';
    public const CTA_OPEN_EVENT = 'open_event';
    public const CTA_START_ORDER = 'start_order';
    public const CTA_OPEN_ORDER = self::CTA_START_ORDER;
    public const CTA_CALL_RESTAURANT = 'call_restaurant';
    public const CTA_WHATSAPP = 'whatsapp';
    public const CTA_EXTERNAL_URL = 'external_url';

    protected $fillable = [
        'tenant_id', 'type', 'title', 'subtitle', 'body', 'image_path',
        'mobile_image_path', 'cta_action', 'cta_label', 'cta_target',
        'linked_resource_type', 'linked_resource_id', 'status', 'is_active',
        'display_order', 'starts_at', 'ends_at', 'published_at', 'metadata',
        'created_by', 'updated_by',
    ];

    public static function types(): array
    {
        return [self::TYPE_SLIDER, self::TYPE_BANNER, self::TYPE_OFFER, self::TYPE_EVENT];
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED, self::STATUS_SCHEDULED, self::STATUS_EXPIRED];
    }

    public static function ctaActions(): array
    {
        return [
            self::CTA_OPEN_MENU,
            self::CTA_OPEN_OFFER,
            self::CTA_OPEN_EVENT,
            self::CTA_START_ORDER,
            self::CTA_CALL_RESTAURANT,
            self::CTA_WHATSAPP,
            self::CTA_EXTERNAL_URL,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        return $query->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant);
    }

    public function scopeRuntimeVisible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->where(fn (Builder $schedule) => $schedule->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $schedule) => $schedule->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderByDesc('published_at')->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
