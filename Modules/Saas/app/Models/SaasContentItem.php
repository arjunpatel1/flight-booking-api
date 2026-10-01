<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * A single piece of SaaS-managed content — a download, a guide, a video, a
 * support contact or a release note.
 *
 * Platform-owned and deliberately NOT tenant-scoped: every restaurant consumes
 * the same published catalogue. There is no `HasBranch`/tenant trait here on
 * purpose.
 *
 * @property string $type
 * @property string $key
 * @property bool $is_published
 */
class SaasContentItem extends Model
{
    use SoftDeletes;

    public const TYPE_DOWNLOAD = 'download';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_VIDEO = 'video';
    public const TYPE_SUPPORT_CONTACT = 'support_contact';
    public const TYPE_RELEASE_NOTE = 'release_note';

    public static function types(): array
    {
        return [
            self::TYPE_DOWNLOAD,
            self::TYPE_DOCUMENT,
            self::TYPE_VIDEO,
            self::TYPE_SUPPORT_CONTACT,
            self::TYPE_RELEASE_NOTE,
        ];
    }

    protected $fillable = [
        'type', 'key', 'section', 'title', 'summary', 'url', 'icon',
        'platform', 'version', 'min_os', 'size', 'checksum', 'release_notes', 'released_at',
        'minutes', 'content_type', 'contact_value',
        'sort_order', 'is_published', 'published_at', 'meta',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'released_at' => 'datetime',
            'minutes' => 'integer',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * What a tenant is allowed to see. Everything else is a draft.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A download is only offerable when it is published *and* has somewhere to
     * point. Publishing an item with no URL must never render a dead button.
     */
    public function isDownloadable(): bool
    {
        return $this->is_published && filled($this->url);
    }
}
