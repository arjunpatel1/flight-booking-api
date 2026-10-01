<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property string|null $reference
 * @property int $purchase_id
 * @property-read Purchase $purchase
 * @property int $received_by
 * @property-read User $receivedBy
 * @property-read PurchaseItem[] $items
 * @property string|null $notes
 * @property Carbon|null $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PurchaseReceipt extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "purchase_id",
        "received_by",
        "reference",
        "notes",
        "received_at",
    ];

    /**
     * Get purchase
     *
     * @return BelongsTo
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Get received by
     *
     * @return BelongsTo
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "received_by")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get items
     *
     * @return HasMany
     */
    public function items(): HasMany
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
            "received_at" => "datetime",
        ];
    }
}
