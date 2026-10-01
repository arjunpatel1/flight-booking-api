<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\User\Models\User;

class PosManagerApproval extends Model
{
    use HasBranch,
        HasCreatedBy;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'branch_id',
        'manager_id',
        'pos_terminal_device_id',
        'device_id',
        'action',
        'resource_type',
        'resource_id',
        'approval_token',
        'status',
        'reason',
        'payload',
        'ip_address',
        'user_agent',
        'approved_at',
        'expires_at',
        'consumed_by',
        'consumed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id')
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    public function terminalDevice(): BelongsTo
    {
        return $this->belongsTo(PosTerminalDevice::class, 'pos_terminal_device_id');
    }

    public function toAuditPayload(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'manager' => [
                'id' => $this->manager_id,
                'name' => $this->manager?->name,
            ],
            'created_by' => $this->created_by,
            'device_id' => $this->device_id,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'approved_at' => $this->approved_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'consumed_at' => $this->consumed_at?->toISOString(),
            'consumed_by' => $this->consumed_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
