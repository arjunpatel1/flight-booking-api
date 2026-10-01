<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class SaasDeliveryJob extends Model
{
    protected $fillable = [
        'uuid',
        'tenant_id',
        'provisioning_run_id',
        'type',
        'status',
        'progress',
        'payload',
        'result',
        'error',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provisioningRun(): BelongsTo
    {
        return $this->belongsTo(SaasProvisioningRun::class, 'provisioning_run_id');
    }

    public function processing(): void
    {
        $this->forceFill([
            'status' => 'processing',
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function complete(array $result = []): void
    {
        $this->forceFill([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result,
            'completed_at' => now(),
            'error' => null,
        ])->save();
    }

    public function failWith(string $error): void
    {
        $this->forceFill([
            'status' => 'failed',
            'error' => $error,
            'failed_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
