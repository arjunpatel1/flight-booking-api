<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class SaasAlertState extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'fingerprint',
        'tenant_id',
        'type',
        'severity',
        'title',
        'message',
        'status',
        'assigned_to',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'note',
        'first_seen_at',
        'last_seen_at',
        'occurrences',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
