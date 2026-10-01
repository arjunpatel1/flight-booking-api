<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Support\Eloquent\Model;

class CustomerAppSession extends Model
{
    protected $fillable = [
        'uuid',
        'customer_app_registration_id',
        'tenant_id',
        'installation_id',
        'token_hash',
        'expires_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(CustomerAppRegistration::class, 'customer_app_registration_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
