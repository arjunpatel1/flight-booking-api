<?php

namespace Modules\Flight\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Flight\Enums\CommissionBasis;
use Modules\Flight\Enums\CommissionCalculationType;
use Modules\Flight\Enums\CommissionRecipientTier;
use Modules\Flight\Services\Commission\DTOs\CommissionCalculationRequest;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

class FlightCommissionRule extends Model
{
    use BelongsToTenant, HasFilters, HasSortBy, SoftDeletes;

    protected $table = 'flight_commission_rules';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'recipient_tier',
        'recipient_id',
        'flight_supplier_id',
        'airline_id',
        'airline_code',
        'origin_airport_id',
        'destination_airport_id',
        'cabin_class',
        'fare_family_id',
        'flight_number',
        'calculation_type',
        'calculation_basis',
        'percentage',
        'fixed_amount',
        'min_commission',
        'max_commission',
        'currency',
        'priority',
        'override_parent',
        'is_active',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'recipient_tier'   => CommissionRecipientTier::class,
        'calculation_type' => CommissionCalculationType::class,
        'calculation_basis'=> CommissionBasis::class,
        'percentage'       => 'float',
        'fixed_amount'     => 'float',
        'min_commission'   => 'float',
        'max_commission'   => 'float',
        'priority'         => 'integer',
        'override_parent'  => 'boolean',
        'is_active'        => 'boolean',
        'effective_from'   => 'datetime',
        'effective_to'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FlightSupplier::class, 'flight_supplier_id');
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(FlightAirline::class, 'airline_id');
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(FlightAirport::class, 'destination_airport_id');
    }

    public function fareFamily(): BelongsTo
    {
        return $this->belongsTo(FlightFareFamily::class, 'fare_family_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FlightCommissionLog::class, 'flight_commission_rule_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeEffectiveAt(Builder $query, ?Carbon $date = null): void
    {
        $date ??= Carbon::now();

        $query->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_from')
              ->orWhere('effective_from', '<=', $date);
        })->where(function (Builder $q) use ($date) {
            $q->whereNull('effective_to')
              ->orWhere('effective_to', '>=', $date);
        });
    }

    public function scopeForTier(Builder $query, CommissionRecipientTier|string $tier, ?int $recipientId = null): void
    {
        $val = $tier instanceof CommissionRecipientTier ? $tier->value : $tier;
        $query->where('recipient_tier', $val);

        if ($recipientId !== null) {
            $query->where(function (Builder $q) use ($recipientId) {
                $q->whereNull('recipient_id')
                  ->orWhere('recipient_id', $recipientId);
            });
        }
    }

    // ── Business Logic ───────────────────────────────────────────────────────

    public function isEffective(?Carbon $date = null): bool
    {
        $date ??= Carbon::now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to && $date->gt($this->effective_to)) {
            return false;
        }

        return true;
    }

    /**
     * Determine if this rule matches the requested flight parameters.
     */
    public function matches(CommissionCalculationRequest $req): bool
    {
        if (! $this->isEffective()) {
            return false;
        }

        // Supplier check
        if ($this->flight_supplier_id !== null && $req->supplierId !== null && (int) $this->flight_supplier_id !== (int) $req->supplierId) {
            return false;
        }

        // Airline ID or code check
        if ($this->airline_id !== null && $req->airlineId !== null && (int) $this->airline_id !== (int) $req->airlineId) {
            return false;
        }
        if ($this->airline_code !== null && $req->airlineCode !== null && strtoupper($this->airline_code) !== strtoupper($req->airlineCode)) {
            return false;
        }

        // Route check
        if ($this->origin_airport_id !== null && $req->originAirportId !== null && (int) $this->origin_airport_id !== (int) $req->originAirportId) {
            return false;
        }
        if ($this->destination_airport_id !== null && $req->destinationAirportId !== null && (int) $this->destination_airport_id !== (int) $req->destinationAirportId) {
            return false;
        }

        // Cabin check
        if ($this->cabin_class !== null && $req->cabinClass !== null && strtoupper($this->cabin_class) !== strtoupper($req->cabinClass)) {
            return false;
        }

        // Fare family check
        if ($this->fare_family_id !== null && $req->fareFamilyId !== null && (int) $this->fare_family_id !== (int) $req->fareFamilyId) {
            return false;
        }

        // Flight number check
        if ($this->flight_number !== null && $req->flightNumber !== null && strtoupper($this->flight_number) !== strtoupper($req->flightNumber)) {
            return false;
        }

        return true;
    }

    /**
     * Compute the deterministic commission amount based on the rule configuration.
     *
     * @return array{amount: float, rate_percentage: float, fixed_amount: float, min_applied: bool, max_applied: bool}
     */
    public function compute(float $baseFare, float $totalFare, int $passengersCount = 1): array
    {
        $passengers = max(1, $passengersCount);
        $basisAmount = match ($this->calculation_basis) {
            CommissionBasis::TOTAL_FARE     => $totalFare,
            CommissionBasis::PER_PASSENGER  => $baseFare * $passengers,
            CommissionBasis::PER_BOOKING    => 1.0,
            default                         => $baseFare, // BASE_FARE
        };

        $percentageAmount = 0.0;
        $fixedAmount = 0.0;

        $calcType = $this->calculation_type instanceof CommissionCalculationType
            ? $this->calculation_type
            : CommissionCalculationType::from($this->calculation_type);

        if ($calcType === CommissionCalculationType::PERCENTAGE || $calcType === CommissionCalculationType::HYBRID) {
            $percentageAmount = ($basisAmount * ($this->percentage / 100.0));
        }

        if ($calcType === CommissionCalculationType::FIXED || $calcType === CommissionCalculationType::HYBRID) {
            if ($this->calculation_basis === CommissionBasis::PER_PASSENGER) {
                $fixedAmount = $this->fixed_amount * $passengers;
            } else {
                $fixedAmount = $this->fixed_amount;
            }
        }

        $calculated = round($percentageAmount + $fixedAmount, 2);

        $minApplied = false;
        $maxApplied = false;

        // Apply min floor
        if ($this->min_commission !== null && $calculated < $this->min_commission) {
            $calculated = (float) $this->min_commission;
            $minApplied = true;
        }

        // Apply max cap
        if ($this->max_commission !== null && $calculated > $this->max_commission) {
            $calculated = (float) $this->max_commission;
            $maxApplied = true;
        }

        return [
            'amount'          => round(max(0.0, $calculated), 2),
            'rate_percentage' => (float) $this->percentage,
            'fixed_amount'    => (float) $this->fixed_amount,
            'min_applied'     => $minApplied,
            'max_applied'     => $maxApplied,
        ];
    }
}
