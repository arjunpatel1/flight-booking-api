<?php

namespace Modules\Media\Models;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Kalnoy\Nestedset\NodeTrait;
use Modules\Media\Enum\MediaType;
use Modules\Media\MediaHelper;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $media_id
 * @property-read  Media|null $media
 * @property MediaType $type
 * @property string $name
 * @property string|null $mime_type
 * @property string|null $extension
 * @property int|null $size
 * @property string|null $path
 * @property string|null $disk
 * @property string|null $download_url
 * @property string|null $url
 * @property string $human_size
 * @property string $preview_image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Media extends Model
{
    use BelongsToTenant, HasCreatedBy, HasFilters, NodeTrait;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "tenant_id",
        "media_id",
        "type",
        "name",
        "mime_type",
        "extension",
        "size",
        "path",
        "disk",
    ];

    /**
     * Get cache media
     *
     * @param int|null $mediaId
     * @param bool $withMediaResource
     * @return MediaSimpleResource|Media|null
     */
    public static function getCacheMedia(?int $mediaId = null, bool $withMediaResource = false): MediaSimpleResource|null|Media
    {
        if (is_null($mediaId)) {
            return null;
        }

        $tenantId = static::currentTenantId();
        $isPlatformAdministrator = auth()->user()?->isSuperAdmin() === true;
        $scope = $tenantId ? "tenant:{$tenantId}" : ($isPlatformAdministrator ? 'platform' : 'global');

        $media = Cache::rememberForever(
            makeCacheKey(['medias', $scope, $mediaId]),
            fn() => Media::query()
                ->when($tenantId, fn (Builder $query) => $query->where('tenant_id', $tenantId))
                // Public and unassigned requests may resolve only global
                // presentation assets. A numeric id must never select a
                // tenant-owned object outside a tenant context.
                ->when(! $tenantId && ! $isPlatformAdministrator, fn (Builder $query) => $query->whereNull('tenant_id'))
                ->find($mediaId)
        );

        if ($withMediaResource && $media) {
            return new MediaSimpleResource($media);
        }

        return $media;
    }

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::saved(fn (Media $media) => $media->forgetCachedVariants());

        static::deleting(function (Media $media) {
            $media->forgetCachedVariants();

            if ($media->isFile()) {
                Storage::disk($media->disk)->delete($media->getRawOriginal('path'));
            }
        });

    }

    /**
     * Determine if media is a file
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->type == MediaType::File;
    }

    /**
     * Get files inside the folder
     *
     * @return HasMany
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /**
     * Check if the file has linked models.
     *
     * @return bool
     */
    public function hasLinked(): bool
    {
        return $this->type == MediaType::File && DB::table("model_files")->where("media_id", $this->id)->count() > 0;
    }

    /**
     * Get download for the file.
     *
     * @return StreamedResponse|null
     */
    public function download(): ?StreamedResponse
    {
        return $this->isFile() ? Storage::disk($this->disk)->download($this->path, $this->getDownloadName()) : null;
    }

    /**
     * Get the file's download name.
     *
     * @return string
     */
    public function getDownloadName(): string
    {
        $name = $this->name;
        $extension = $this->extension;

        return str($name)->endsWith(".$extension") ? $name : "$name.$extension";
    }

    /**
     * Get the file's url.
     *
     * @return Attribute
     */
    public function url(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->exists && $this->isImage()
                ? Storage::disk($this->disk)->url($this->path)
                : null
        );
    }

    /**
     * Get the file's content.
     *
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->exists && $this->isFile() ? Storage::disk($this->disk)->get($this->path) : null;
    }

    /**
     * Get the file's image base64.
     *
     * @return string|null
     */
    public function getImageBase64(): ?string
    {
        return $this->exists && $this->isFile() && $this->isImage()
            ? 'data:image/jpg;base64,' . base64_encode(Storage::disk($this->disk)->get($this->path))
            : null;
    }

    /**
     * Determine if the file type is image.
     *
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->isFile() && strtok($this->mime_type, '/') === 'image';
    }

    /**
     * Get the file's human size.
     *
     * @return Attribute
     */
    public function humanSize(): Attribute
    {
        return Attribute::make(
            get: fn() => humanFileSize($this->size ?? 0)
        );
    }

    /**
     * Get the file's download url.
     *
     * @return Attribute
     */
    public function downloadUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->isFile() ? route('admin.media.download', $this->id) : null
        );
    }

    /**
     * Get the file's preview image.
     *
     * @return Attribute
     */
    public function previewImageUrl(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->isImage() ? $this->url : MediaHelper::resolvePreviewImage($this->extension)
        );
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            'search',
            'type',
            'mime',
            'folder',
            'tab'
        ];
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->like('name', $value);
    }

    /**
     * Scope a query to only mime type.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeMime(Builder $query, string $value): void
    {
        $query->where(function ($query) use ($value) {
            $query->where(function ($query) use ($value) {
                foreach (explode(",", $value) as $mime) {
                    $query->orWhere('mime_type', 'LIKE', "$mime/%");
                }
            })->orWhere("type", MediaType::Folder);
        });
    }

    /**
     * Scope a query to only folder .
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeFolder(Builder $query, string $value): void
    {
        $query->where(function ($query) use ($value) {
            $query
                ->when($value === "root", fn($query) => $query->whereNull("media_id"))
                ->when($value !== "root", fn($query) => $query->where("media_id", $value));
        });
    }

    /**
     * Scope a query to filter media files based on the selected tab type.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeTab(Builder $query, string $value): void
    {
        $query->where(function ($query) use ($value) {
            $query->when($value === 'documents', function ($q) {
                $q->where(function ($subQuery) {
                    $subQuery->where('mime_type', 'like', 'application/%')
                        ->orWhere('mime_type', 'like', 'text/%');
                });
            })
                ->when($value === 'images', function ($q) {
                    $q->where('mime_type', 'like', 'image/%');
                })
                ->when($value === 'videos', function ($q) {
                    $q->where('mime_type', 'like', 'video/%');
                })
                ->when($value === 'audio', function ($q) {
                    $q->where('mime_type', 'like', 'audio/%');
                })
                ->when($value === 'archives', function ($q) {
                    $q->whereIn('mime_type', [
                        'application/zip',
                        'application/x-rar-compressed',
                        'application/x-7z-compressed',
                        'application/x-tar',
                    ]);
                })
                ->when($value === 'spreadsheets', function ($q) {
                    $q->whereIn('mime_type', [
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                    ]);
                })
                ->when($value === 'presentations', function ($q) {
                    $q->whereIn('mime_type', [
                        'application/vnd.ms-powerpoint',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    ]);
                })
                ->when($value === 'pdfs', function ($q) {
                    $q->where('mime_type', 'application/pdf');
                });
        });
    }

    /** @inheritDoc */
    public function getParentIdName(): string
    {
        return "media_id";
    }

    /**
     * Keep each tenant's nested folder tree structurally independent.
     *
     * @return array<int, string>
     */
    public function getScopeAttributes(): array
    {
        return ['tenant_id'];
    }

    private static function currentTenantId(): ?int
    {
        if (app()->bound(TenantContext::class) && app(TenantContext::class)->id()) {
            return (int) app(TenantContext::class)->id();
        }

        $actor = auth()->user();

        return $actor?->assignedToTenant() ? (int) $actor->tenantId() : null;
    }

    private function forgetCachedVariants(): void
    {
        foreach (['global', 'platform', $this->tenant_id ? "tenant:{$this->tenant_id}" : null] as $scope) {
            if ($scope) {
                Cache::forget(makeCacheKey(['medias', $scope, $this->id]));
            }
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
        ];
    }

    /** @inheritDoc */
    protected function deleteDescendants(): void
    {
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $this->descendants()->get()->each->delete();

        if ($this->hardDeleting()) {
            $height = $rgt - $lft + 1;

            $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

            // In case if user wants to re-create the node
            $this->makeRoot();

            static::$actionsPerformed++;
        }
    }
}
