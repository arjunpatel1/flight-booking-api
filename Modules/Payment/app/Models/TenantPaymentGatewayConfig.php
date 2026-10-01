<?php

namespace Modules\Payment\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantPaymentGatewayConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'provider', 'webhook_key', 'enabled', 'test_mode', 'preferred',
        'credentials', 'credential_version', 'settlement_identity',
        'last_tested_at', 'last_test_status', 'updated_by',
    ];

    protected $hidden = ['credentials'];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'tenant_payment_gateway_branches', 'gateway_config_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $config) {
            $config->webhook_key ??= (string) Str::uuid();
        });
    }

    public function maskedCredentials(): array
    {
        return collect($this->credentials ?? [])->mapWithKeys(function ($value, $key) {
            $text = (string) $value;
            return [$key => $text === '' ? null : '••••'.substr($text, -4)];
        })->all();
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'test_mode' => 'boolean',
            'preferred' => 'boolean',
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }
}
