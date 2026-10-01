<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Support\Eloquent\Model;

class CustomerAppBuildArtifact extends Model
{
    protected $fillable = [
        'uuid', 'customer_app_build_id', 'tenant_id', 'platform', 'build_type',
        'version', 'checksum', 'storage_disk', 'storage_reference', 'size_bytes', 'revoked_at',
        'mime_type', 'verified_at', 'retention_expires_at', 'verification_metadata',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $artifact) => $artifact->uuid ??= (string) Str::uuid());
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(CustomerAppBuild::class, 'customer_app_build_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isDownloadable(): bool
    {
        return $this->revoked_at === null
            && $this->verified_at !== null
            && ($this->retention_expires_at === null || $this->retention_expires_at->isFuture());
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer', 'revoked_at' => 'datetime',
            'verified_at' => 'datetime', 'retention_expires_at' => 'datetime',
            'verification_metadata' => 'array',
        ];
    }
}
