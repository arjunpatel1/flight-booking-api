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

class FlightAgent extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy, SoftDeletes;

    protected $table = 'flight_agents';

    protected $fillable = [
        'tenant_id',
        'flight_distributor_id',
        'user_id',
        'name',
        'code',
        'agency_name',
        'iata_number',
        'email',
        'phone',
        'status',
        'credit_limit',
        'credit_balance',
        'commission_rate',
        'notes',
        'suspended_at',
        'suspended_reason',
    ];

    protected $casts = [
        'status'          => B2BStatus::class,
        'credit_limit'    => 'float',
        'credit_balance'  => 'float',
        'commission_rate' => 'float',
        'suspended_at'    => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(FlightDistributor::class, 'flight_distributor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(FlightB2BWallet::class, 'owner_id')
            ->where('owner_type', 'AGENT');
    }

    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(FlightInventoryBalance::class, 'holder_id')
            ->where('holder_type', HolderType::AGENT->value);
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

    /**
     * Agent is operational if both the agent AND parent distributor are active.
     */
    public function isOperational(): bool
    {
        if ($this->status !== B2BStatus::ACTIVE) {
            return false;
        }

        if ($this->distributor && ! $this->distributor->isActive()) {
            return false;
        }

        return true;
    }

    public function suspend(string $reason = 'Suspended'): bool
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
}
