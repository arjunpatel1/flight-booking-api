<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Database\Factories\PurchaseItemFactory;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property int $purchase_id
 * @property-read  Purchase $purchase
 * @property int $ingredient_id
 * @property-read Ingredient $ingredient
 * @property-read PurchaseReceiptItem[] $receiptItems
 * @property int $quantity
 * @property int $received_quantity
 * @property string $currency
 * @property float $currency_rate
 * @property Money $unit_cost
 * @property Money $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PurchaseItem extends Model
{
    use HasFactory, Translatable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'purchase_id',
        'ingredient_id',
        'quantity',
        'received_quantity',
        'currency',
        'currency_rate',
        'unit_cost',
        'line_total'
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["ingredient:id,name,unit_id"];

    protected static function newFactory(): PurchaseItemFactory
    {
        return PurchaseItemFactory::new();
    }

    /**
     * Get sub-total
     *
     * @return Attribute
     */
    public function unitCost(): Attribute
    {
        return Attribute::get(fn($subTotal) => new Money($subTotal, $this->currency));
    }

    /**
     * Get sub-total
     *
     * @return Attribute
     */
    public function lineTotal(): Attribute
    {
        return Attribute::get(fn($subTotal) => new Money($subTotal, $this->currency));
    }

    /**
     * Get purchase
     *
     * @return BelongsTo
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    /**
     * Get ingredient
     *
     * @return BelongsTo
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)
            ->withOutGlobalBranchPermission()
            ->withTrashed();
    }

    /**
     * Get purchase receipt items
     *
     * @return HasMany
     */
    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => "float",
            "received_quantity" => "float",
        ];
    }
}
