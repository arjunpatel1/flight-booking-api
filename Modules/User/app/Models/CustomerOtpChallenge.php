<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOtpChallenge extends Model
{
    protected $fillable = [
        'reference', 'tenant_id', 'branch_id', 'user_id', 'phone', 'email', 'channel', 'purpose', 'otp_hash',
        'installation_hash', 'ip_hash', 'attempts', 'max_attempts', 'sent_at',
        'expires_at', 'consumed_at', 'provider_reference',
    ];

    protected $hidden = ['otp_hash', 'installation_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
