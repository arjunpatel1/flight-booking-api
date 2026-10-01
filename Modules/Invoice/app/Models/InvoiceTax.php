<?php

namespace Modules\Invoice\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int|null $invoice_line_id
 * @property int|null $tax_id
 * @property array $name
 * @property string $code
 * @property float $rate
 * @property string $currency
 * @property float $currency_rate
 * @property Money $amount
 * @property TaxType $type
 * @property bool $compound
 *
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property-read Invoice $invoice
 * @property-read InvoiceLine|null $invoiceLine
 * @property-read Tax|null $tax
 */
class InvoiceTax extends Model
{
    use SoftDeletes, Translatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'invoice_line_id',
        'tax_id',
        'name',
        'code',
        'rate',
        'currency',
        'currency_rate',
        'amount',
        'type',
        'compound',
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The invoice this tax belongs to.
     *
     * @return BelongsTo<Invoice,static>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The invoice line this tax is applied to (optional).
     *
     * @return BelongsTo<InvoiceLine,static>
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * Get tax
     *
     * @return BelongsTo<Tax,static>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'compound' => 'boolean',
            'type' => TaxType::class
        ];
    }
}
