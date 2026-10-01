<?php

namespace Modules\Printer\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class PrintAgentPairing extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code_hash',
        'challenge_token_hash',
        'device_public_id',
        'device_name',
        'platform',
        'agent_version',
        'status',
        'attempts',
        'expires_at',
        'claimed_at',
        'completed_at',
        'claimed_by',
        'tenant_id',
        'branch_id',
        'print_agent_id',
    ];

    protected $hidden = ['code_hash', 'challenge_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'print_agent_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
