<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;

class PartnerApiIntegration extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'tenant_id', 'name', 'environment', 'status', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(PartnerApiCredential::class, 'partner_id');
    }
}
