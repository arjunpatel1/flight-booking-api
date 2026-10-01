<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Database\Factories\PurchaseReceiptItemFactory;
use Modules\Support\Eloquent\Model;

/**
 * @property int $id
 * @property int $purchase_receipt_id
 * @property float $received_quantity
 * @property-read PurchaseReceipt $purchaseReceipt
 * @property int $purchase_item_id
 * @property-read PurchaseItem $item
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PurchaseReceiptItem extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "purchase_receipt_id",
        "purchase_item_id",
        "received_quantity"
    ];

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = ["item"];


    /**
     * Get purchase receipt
     *
     * @return BelongsTo
     */
    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    /**
     * Get purchase item
     *
     * @return BelongsTo
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "received_quantity" => "float",
        ];
    }
}
