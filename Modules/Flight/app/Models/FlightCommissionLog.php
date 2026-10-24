<?php

namespace Modules\Flight\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Flight\Enums\CommissionRecipientTier;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class FlightCommissionLog extends Model
{
    use BelongsToTenant;

    protected $table = 'flight_commission_logs';

    protected $fillable = [
        'tenant_id',
        'flight_commission_rule_id',
        'booking_reference',
        'recipient_tier',
        'recipient_id',
        'base_amount',
        'commission_amount',
        'rate_percentage',
        'fixed_amount',
        'currency',
        'breakdown',
        'calculation_context',
    ];

    protected $casts = [
        'recipient_tier'      => CommissionRecipientTier::class,
        'base_amount'         => 'float',
        'commission_amount'   => 'float',
        'rate_percentage'     => 'float',
        'fixed_amount'        => 'float',
        'breakdown'           => 'array',
        'calculation_context' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FlightCommissionRule::class, 'flight_commission_rule_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
