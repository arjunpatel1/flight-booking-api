<?php

namespace Modules\Flight\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\B2BStatus;
use Modules\Flight\Enums\HolderType;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class FlightDistributor extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy, SoftDeletes;

    protected $table = 'flight_distributors';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'code',
        'company_name',
        'email',
        'phone',
        'status',
        'credit_limit',
        'credit_balance',
        'commission_rate',
        'allow_sub_agents',
        'max_agents',
        'notes',
        'suspended_at',
        'suspended_reason',
    ];

    protected $casts = [
        'status'           => B2BStatus::class,
        'credit_limit'     => 'float',
        'credit_balance'   => 'float',
        'commission_rate'  => 'float',
        'allow_sub_agents' => 'boolean',
        'max_agents'       => 'integer',
        'suspended_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(FlightAgent::class, 'flight_distributor_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(FlightB2BWallet::class, 'owner_id')
            ->where('owner_type', 'DISTRIBUTOR');
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(FlightInventoryBalance::class, 'holder_id')
            ->where('holder_type', HolderType::DISTRIBUTOR->value);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', B2BStatus::ACTIVE->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === B2BStatus::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === B2BStatus::SUSPENDED;
    }

    public function suspend(string $reason = 'Suspended by admin'): bool
    {
        return $this->update([
            'status'           => B2BStatus::SUSPENDED->value,
            'suspended_at'     => Carbon::now(),
            'suspended_reason' => $reason,
        ]);
    }

    public function activate(): bool
    {
        return $this->update([
            'status'           => B2BStatus::ACTIVE->value,
            'suspended_at'     => null,
            'suspended_reason' => null,
        ]);
    }

    public function adjustCreditLimit(float $newLimit): bool
    {
        $res = $this->update(['credit_limit' => max(0.0, $newLimit)]);
        if ($this->wallet) {
            $this->wallet->update(['credit_limit' => max(0.0, $newLimit)]);
        }
        return $res;
    }
}
