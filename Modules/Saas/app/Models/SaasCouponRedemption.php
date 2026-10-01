<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class SaasCouponRedemption extends Model
{
    protected $fillable = [
        'saas_coupon_id',
        'tenant_id',
        'tenant_subscription_id',
        'saas_billing_invoice_id',
        'email',
        'mobile',
        'discount_amount',
        'metadata',
        'redeemed_at',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(SaasCoupon::class, 'saas_coupon_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'discount_amount' => 'decimal:2',
            'metadata' => 'array',
            'redeemed_at' => 'datetime',
        ];
    }
}

