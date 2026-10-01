<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Support\Eloquent\Model;

class CustomerAppRegistration extends Model
{
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS = 'ios';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'package_id',
        'display_name',
        'platform',
        'status',
        'branding_revision',
        'signing_certificate_fingerprint',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $registration): void {
            $registration->uuid ??= (string) Str::uuid();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CustomerAppSession::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(CustomerAppBuild::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    protected function casts(): array
    {
        return ['branding_revision' => 'integer'];
    }
}
