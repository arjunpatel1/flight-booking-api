<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

class SaasBillingEvent extends Model
{
    protected $fillable = [
        'saas_billing_invoice_id',
        'type',
        'channel',
        'status',
        'message',
        'payload',
        'processed_at',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasBillingInvoice::class, 'saas_billing_invoice_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
