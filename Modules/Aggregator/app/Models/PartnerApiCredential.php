<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class PartnerApiCredential extends Model
{
    protected $fillable = [
        'uuid', 'partner_id', 'tenant_id', 'api_key', 'secret_fingerprint',
        'secret_ciphertext', 'scopes', 'branch_ids', 'ip_allowlist',
        'requests_per_minute', 'orders_per_minute', 'burst_limit', 'status',
        'signature_version', 'expires_at', 'grace_expires_at', 'last_used_at', 'rotated_from_id',
    ];

    protected $hidden = ['secret_ciphertext', 'secret_fingerprint'];

    protected function casts(): array
    {
        return [
            'secret_ciphertext' => 'encrypted',
            'scopes' => 'array',
            'branch_ids' => 'array',
            'ip_allowlist' => 'array',
            'signature_version' => 'integer',
            'expires_at' => 'datetime',
            'grace_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PartnerApiIntegration::class, 'partner_id');
    }
}
