<?php

namespace Modules\Invoice\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Branch\Traits\HasBranch;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Enums\InvoicePartyType;
use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Enums\InvoiceStatus;
use Modules\Invoice\Enums\InvoiceType;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentAllocation;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * @property int $id
 * @property int|null $order_id
 * @property int|null $table_merge_id
 * @property int $seller_party_id
 * @property int|null $buyer_party_id
 * @property string $invoice_number
 * @property string $uuid
 * @property InvoiceType $type
 * @property InvoicePurpose $purpose
 * @property InvoiceKind $invoice_kind
 * @property int|null $reference_invoice_id
 * @property string $currency
 * @property float $currency_rate
 * @property Money $subtotal
 * @property Money $tax_total
 * @property Money $discount_total
 * @property Money $total
 * @property Money $rounding_adjustment
 * @property Money $paid_amount
 * @property Money $refunded_amount
 * @property Money $net_paid
 * @property InvoiceStatus $status
 * @property array|null $file_info
 * @property Carbon $issued_at
 * @property int|null $invoice_counter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @property-read Order|null $order
 * @property-read TableMerge|null $tableMerge
 * @property-read InvoiceParty $seller
 * @property-read InvoiceParty|null $buyer
 * @property-read Invoice|null $referenceInvoice
 * @property-read Collection|Invoice[] $relatedInvoices
 * @property-read Collection|PaymentAllocation[] $allocations
 * @property-read Collection|InvoiceTax[] $taxes
 * @property-read Collection|InvoiceDiscount[] $discounts
 * @property-read Collection|InvoiceLine[] $lines
 */
class Invoice extends Model
{
    use SoftDeletes, HasBranch, HasSortBy, HasFilters;

    /**
     * Default date column
     *
     * @var string
     */
    public static string $defaultDateColumn = 'issued_at';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'order_id',
        'table_merge_id',
        'seller_party_id',
        'buyer_party_id',
        'invoice_number',
        'uuid',
        'type',
        'purpose',
        'invoice_kind',
        'reference_invoice_id',
        'currency',
        'currency_rate',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'rounding_adjustment',
        'status',
        'issued_at',
        'invoice_counter',
        'paid_amount',
        'refunded_amount',
        'net_paid',
        'file_info',
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (!$invoice->uuid) {
                $invoice->uuid = Str::uuid();
            }
        });
    }

    /**
     * Get order
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get seller
     *
     * @return BelongsTo
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(InvoiceParty::class, 'seller_party_id');
    }

    /**
     * Get related invoices
     *
     * @return HasMany
     */
    public function relatedInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'reference_invoice_id');
    }

    public function scopeIssued($query)
    {
        return $query->where('status', InvoiceStatus::Issued);
    }

    /**
     * Get reference invoice
     *
     * @return BelongsTo
     */
    public function referenceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'reference_invoice_id');
    }

    /**
     * Get buyer
     *
     * @return BelongsTo
     */
    public function buyer(): BelongsTo
    {
        return $this
            ->belongsTo(InvoiceParty::class, 'buyer_party_id')
            ->withDefault([
                'id' => null,
                'legal_name' => User::walkInName(),
                'type' => InvoicePartyType::Buyer,
            ]);
    }

    /**
     * Get subtotal
     *
     * @return Attribute<Money,static>
     */
    public function subtotal(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get tax total
     *
     * @return Attribute<Money,static>
     */
    public function taxTotal(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get discount total
     *
     * @return Attribute<Money,static>
     */
    public function discountTotal(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }


    /**
     * Get total
     *
     * @return Attribute<Money,static>
     */
    public function total(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get rounding adjustment
     *
     * @return Attribute<Money,static>
     */
    public function roundingAdjustment(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get paid amount
     *
     * @return Attribute<Money,static>
     */
    public function paidAmount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get refunded amount
     *
     * @return Attribute<Money,static>
     */
    public function refundedAmount(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get net paid
     *
     * @return Attribute<Money,static>
     */
    public function netPaid(): Attribute
    {
        return Attribute::get(fn($amount) => new Money($amount, $this->currency));
    }

    /**
     * Get discounts
     *
     * @return HasMany
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(InvoiceDiscount::class);
    }

    /**
     * Get invoice taxes
     *
     * @return HasMany
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class)
            ->whereNull("invoice_line_id");
    }

    /**
     * Get table merge
     *
     * @return BelongsTo
     */
    public function tableMerge(): BelongsTo
    {
        return $this->belongsTo(TableMerge::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    /**
     * Get payment allocations
     *
     * @return HasMany
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)
            ->with("payment:id,method,type,transaction_id");
    }

    /**
     * Get invoice lines
     * @return HasMany
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Scope a query to search across all fields.
     *
     * @param Builder $query
     * @param string $value
     * @return void
     */
    public function scopeSearch(Builder $query, string $value): void
    {
        $query->where(fn(Builder $query) => $query
            ->like('invoice_number', $value)
            ->orLike('uuid', $value)
            ->orWhereHas('buyer', fn(Builder $query) => $query
                ->like('legal_name', $value)
                ->orLike('vat_tin', $value)
                ->orLike('cr_number', $value))
            ->orWhereHas('seller', fn(Builder $query) => $query
                ->like('legal_name', $value)
                ->orLike('vat_tin', $value)
                ->orLike('cr_number', $value))
        );
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "type",
            "purpose",
            "invoice_kind",
            "status",
            "settlement_status",
            "from",
            "to",
            self::BRANCH_COLUMN_NAME
        ];
    }

    public function scopeSettlementStatus(Builder $query, string $status): void
    {
        match ($status) {
            'paid' => $query->whereRaw('net_paid >= total'),
            'partial' => $query->whereRaw('net_paid > 0 AND net_paid < total'),
            'unpaid' => $query->whereRaw('net_paid <= 0'),
            'refunded' => $query->whereRaw('refunded_amount > 0'),
            default => null,
        };
    }

    /**
     * Get qrcode base64
     *
     * @param int $size
     * @return string
     */
    public function getQrcodeBase64(int $size = 120): string
    {
        $format = extension_loaded('imagick') ? 'png' : 'svg';

        if ($this->seller->country_code == "SA") {
            return base64_encode(zatca()
                ->sellerName($this->seller->legal_name)
                ->vatRegistrationNumber($this->seller->vat_tin)
                ->timestamp($this->issued_at)
                ->totalWithVat($this->total->amount())
                ->vatTotal($this->tax_total->amount())
                ->toQrCode(
                    qrCodeOptions()
                        ->format($format)
                        ->size($size)
                ));
        } else {
            return base64_encode(
                QrCode::format($format)
                    ->size($size)
                    ->generate($this->getPDFUrl())
            );
        }
    }

    public function getQrcodeMimeType(): string
    {
        return extension_loaded('imagick') ? 'image/png' : 'image/svg+xml';
    }

    /**
     * Get pdf url
     *
     * @return string
     */
    public function getPDFUrl(): string
    {
        return route('invoices.pdf.index', $this->uuid);
    }

    /**
     * Get download url
     *
     * @return string
     */
    public function getDownloadUrl(): string
    {
        return route('invoices.pdf.download', $this->uuid);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'type' => InvoiceType::class,
            'purpose' => InvoicePurpose::class,
            'invoice_kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'file_info' => 'array'
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "invoice_number",
            self::BRANCH_COLUMN_NAME,
            "type",
            "status",
            "purpose",
            "invoice_kind",
            "total",
        ];
    }
}
