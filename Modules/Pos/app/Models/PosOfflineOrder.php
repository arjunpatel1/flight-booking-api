<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;

class PosOfflineOrder extends Model
{
    use HasBranch,
        HasCreatedBy,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'branch_id',
        'table_id',
        'pos_register_id',
        'pos_session_id',
        'offline_id',
        'client_request_id',
        'device_id',
        'reference_no',
        'order_number',
        'status',
        'sync_status',
        'type',
        'currency',
        'currency_rate',
        'subtotal',
        'tax_amount',
        'total',
        'payload',
        'attempts',
        'locked_at',
        'last_sync_attempt_at',
        'last_sync_error',
        'synced_at',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'currency_rate' => 'float',
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'total' => 'float',
        'attempts' => 'integer',
        'locked_at' => 'datetime',
        'last_sync_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function toQueuePayload(): array
    {
        $payload = $this->payload ?? [];

        return array_merge($payload, [
            'id' => $this->offline_id,
            'client_request_id' => $this->client_request_id,
            'device_id' => $this->device_id,
            'reference_no' => $this->reference_no,
            'order_number' => $this->order_number,
            'branch_id' => $this->branch_id,
            'table_id' => $this->table_id,
            'register_id' => $this->pos_register_id,
            'session_id' => $this->pos_session_id,
            'status' => $this->status,
            'sync_status' => $this->sync_status,
            'type' => $this->type,
            'currency' => $this->currency,
            'currency_rate' => $this->currency_rate,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total' => $this->total,
            'attempts' => $this->attempts,
            'last_sync_error' => $this->last_sync_error,
            'last_sync_attempt_at' => $this->last_sync_attempt_at?->toISOString(),
            'synced_at' => $this->synced_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ]);
    }
}
