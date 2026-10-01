<?php

namespace Modules\Aggregator\Models;

use Modules\Support\Eloquent\Model;

class PartnerApiIdempotency extends Model
{
    protected $table = 'partner_api_idempotency';

    protected $fillable = [
        'credential_id', 'key_hash', 'request_hash', 'status', 'response_code',
        'response_body', 'locked_until', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['response_body' => 'encrypted', 'locked_until' => 'datetime', 'completed_at' => 'datetime'];
    }
}
