<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightFareFamily extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy;

    protected $table = 'flight_fare_families';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'cabin_class',
        'description',
        'features',
        'baggage_allowance',
        'changeability',
        'refundability',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'baggage_allowance' => 'array',
        'changeability' => 'array',
        'refundability' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function fares(): HasMany
    {
        return $this->hasMany(FlightFare::class, 'fare_family_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Copy this family's refundable/changeable flags onto the fares built from
     * it. Fares keep their own copy (set when a route is saved), so without
     * this an edited family leaves its existing fares, and the cancellation
     * quotes read from them, on the old values.
     *
     * Fares match by fare_family_id, or by code when that is empty. A global
     * family (no tenant) only covers tenants without their own family of that
     * code, the same lookup the route screen uses. A flag the family leaves
     * unset is left alone on its fares. Returns fares changed.
     */
    public function syncFlagsToFares(): int
    {
        $flags = [];
        if (is_bool(data_get($this->refundability, 'allowed'))) {
            $flags['is_refundable'] = $this->refundability['allowed'];
        }
        if (is_bool(data_get($this->changeability, 'allowed'))) {
            $flags['is_changeable'] = $this->changeability['allowed'];
        }
        if ($flags === []) {
            return 0;
        }

        return FlightFare::withoutGlobalScopes()
            ->where(function ($match) {
                $match->where('fare_family_id', $this->id)
                    ->orWhere(function ($byCode) {
                        $byCode->whereNull('fare_family_id')
                            ->whereRaw('UPPER(fare_family_code) = ?', [strtoupper($this->code)]);

                        if ($this->tenant_id !== null) {
                            $byCode->where('tenant_id', $this->tenant_id);
                        } else {
                            $byCode->where(fn ($tenant) => $tenant
                                ->whereNull('tenant_id')
                                ->orWhereNotIn('tenant_id', static::withoutGlobalScopes()
                                    ->whereNotNull('tenant_id')
                                    ->whereRaw('UPPER(code) = ?', [strtoupper($this->code)])
                                    ->select('tenant_id')));
                        }
                    });
            })
            ->where(function ($stale) use ($flags) {
                foreach ($flags as $column => $value) {
                    $stale->orWhere($column, '!=', $value)->orWhereNull($column);
                }
            })
            ->update($flags);
    }
}
