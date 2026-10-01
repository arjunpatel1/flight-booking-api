<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class TenantFeatureLimit extends Model
{
    protected $fillable = [
        'tenant_id',
        'feature',
        'limit',
        'used',
        'resets_at',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'limit' => 'integer',
            'used' => 'integer',
            'resets_at' => 'datetime',
        ];
    }
}
