<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;

class SaasBillingInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'invoice_number',
        'amount',
        'currency',
        'status',
        'gateway',
        'gateway_reference',
        'payment_url',
        'issued_at',
        'due_at',
        'paid_at',
        'metadata',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SaasBillingEvent::class);
    }
    public function refunds(): HasMany { return $this->hasMany(SaasBillingRefund::class); }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
