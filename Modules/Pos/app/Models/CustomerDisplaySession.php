<?php

namespace Modules\Pos\Models;

use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class CustomerDisplaySession extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'cart_id',
        'access_token_hash',
        'snapshot',
        'last_published_at',
        'expires_at',
        'closed_at',
    ];

    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            // Customer names and order lines must not be plaintext at rest.
            'snapshot' => 'encrypted:array',
            'last_published_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
