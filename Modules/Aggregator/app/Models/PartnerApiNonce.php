<?php

namespace Modules\Aggregator\Models;

use Modules\Support\Eloquent\Model;

class PartnerApiNonce extends Model
{
    protected $fillable = ['credential_id', 'nonce_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
