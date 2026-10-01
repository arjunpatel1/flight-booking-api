<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPushDevice extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'installation_id',
        'push_token',
        'token_hash',
        'platform',
        'app_version',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = ['push_token', 'token_hash', 'installation_id'];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
