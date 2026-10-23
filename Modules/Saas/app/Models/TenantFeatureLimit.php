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
        'overage_allowed',
        'overage_unit_price',
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
            'overage_allowed' => 'boolean',
            'overage_unit_price' => 'float',
            'resets_at' => 'datetime',
        ];
    }
}
