<?php

namespace Modules\Payment\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoice\Models\Invoice;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;

/**
 * Represents the portion of a payment that was assigned to a specific invoice.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $invoice_id
 * @property string $currency
 * @property float $currency_rate
 * @property Money $amount
 *
 * @property-read Payment $payment
 * @property-read Invoice $invoice
 */
class PaymentAllocation extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'payment_id',
        'invoice_id',
        'currency',
        'currency_rate',
        'amount',
    ];


    /**
     * Get payment
     *
     * @return BelongsTo<Payment,static>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get invoice
     *
     * @return BelongsTo<Invoice,static>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get amount
     *
     * @return Attribute<Money,static>
     */
    public function amount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }
}
