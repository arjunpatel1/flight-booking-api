<?php

namespace Modules\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Currency\Currency;
use Modules\Inventory\Database\Factories\PurchaseFactory;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Support\Eloquent\Model;
use Modules\Support\Money;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;


/**
 * @property int $id
 * @property string|null $reference_number
 * @property int $supplier_id
 * @property-read User $supplier
 * @property-read PurchaseItem[] $items
 * @property-read PurchaseReceipt[] $purchaseReceipts
 * @property string|null $notes
 * @property string $currency
 * @property float $currency_rate
 * @property Money $discount
 * @property Money $tax
 * @property Money $sub_total
 * @property Money $total
 * @property PurchaseStatus $status
 * @property Carbon|null $expected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Purchase extends Model
{
    use HasFactory,
        HasSortBy,
        HasFilters,
        SoftDeletes,
        HasCreatedBy,
        HasBranch,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        self::BRANCH_COLUMN_NAME,
        "reference_number",
        "supplier_id",
        "notes",
        "currency",
        "currency_rate",
        "discount",
        "tax",
        "sub_total",
        "total",
        "status",
        "expected_at",
    ];

    protected static function newFactory(): PurchaseFactory
    {
        return PurchaseFactory::new();
    }

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase) {
            if (empty($purchase->reference_number)) {
                $purchase->reference_number = self::generateReferenceNumber($purchase->branch_id);
            }
        });
    }

    /**
     * Generate a short unique reference number like: PO-001-001
     *
     * @param int $branchId
     * @return string
     */
    public static function generateReferenceNumber(int $branchId): string
    {
        $prefix = sprintf('PO-%03d', $branchId);

        $lastRef = self::where('reference_number', 'like', "$prefix-%")
            ->where('branch_id', $branchId)
            ->latest('id')
            ->value('reference_number');

        $sequence = 1;

        if ($lastRef && preg_match('/(\d+)$/', $lastRef, $matches)) {
            $sequence = (int)$matches[1] + 1;
        }

        return sprintf('%s-%03d', $prefix, $sequence);
    }

    /**
     * Get supplier
     *
     * @return BelongsTo
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * Get discount
     *
     * @return Attribute
     */
    public function discount(): Attribute
    {
        return Attribute::get(fn($discount) => new Money((float) ($discount ?? 0), $this->currency));
    }

    /**
     * Get tax
     *
     * @return Attribute
     */
    public function tax(): Attribute
    {
        return Attribute::get(fn($tax) => new Money((float) ($tax ?? 0), $this->currency));
    }

    /**
     * Get sub-total
     *
     * @return Attribute
     */
    public function subTotal(): Attribute
    {
        return Attribute::get(fn($subTotal) => new Money((float) ($subTotal ?? 0), $this->currency));
    }

    /**
     * Get total
     *
     * @return Attribute
     */
    public function total(): Attribute
    {
        return Attribute::get(fn($total) => new Money((float) ($total ?? 0), $this->currency));
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            'search',
            self::BRANCH_COLUMN_NAME,
            "from",
            "to",
            "supplier_id",
            "status",
            "expectedAt"
        ];
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
        $query->like('reference_number', $value);
    }

    /**
     * Scope a query to get from expected at date.
     *
     * @param Builder $query
     * @param string $date
     * @return void
     */
    public function scopeWhereExpectedAt(Builder $query, string $date): void
    {
        $query->whereDate("expected_at", "=", date("Y-m-d", strtotime($date)))
            ->whereNotNull("expected_at");
    }

    /**
     * Create or update related purchase items for this purchase.
     *
     * This method will:
     * - Create new items if no ID is provided.
     * - Update existing items if ID is provided.
     * - Delete removed items (not included in input).
     * - Automatically calculate line_total as quantity × unit_cost.
     *
     * @param array|Collection $items Array or Collection of item data.
     *                                   Each item must have:
     *                                   - ingredient_id (int)
     *                                   - quantity (float)
     *                                   - unit_cost (float)
     *                                   - id (int|null, optional for update)
     * @return void
     */
    public function syncItems(Collection|array $items): void
    {
        $items = collect($items);

        $existingIds = $this->items()->pluck('id')->toArray();
        $submittedIds = $items->pluck('id')->filter()->toArray();
        $idsToDelete = array_diff($existingIds, $submittedIds);
        PurchaseItem::destroy($idsToDelete);
        $precision = Currency::subunit($this->currency);

        foreach ($items as $item) {
            $itemData = [
                'ingredient_id' => $item['ingredient_id'],
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                "currency" => $this->currency,
                "currency_rate" => $this->currency_rate,
                'line_total' => round($item['quantity'] * $item['unit_cost'], $precision),
            ];

            if (!empty($item['id'])) {
                $this->items()->where('id', $item['id'])->update($itemData);
            } else {
                $this->items()->create($itemData);
            }
        }
    }

    /**
     * Get items
     *
     * @return HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Recalculate and update the totals after items have been saved.
     *
     * @return void
     */
    public function calculateAndSyncTotals(): void
    {
        $subTotal = $this->items()->sum('line_total');
        $discount = $this->discount->amount();
        $tax = $this->tax->amount();
        $total = max(0, $subTotal - $discount + $tax);

        $this->update([
            'sub_total' => $subTotal,
            'total' => $total
        ]);
    }


    /**
     * Efficiently recalculate received quantities and update status.
     *
     * @return void
     */
    public function recalculateReceivedQuantities(): void
    {
        /** @var PurchaseItem $items */
        $items = $this->items()->with('receiptItems')->get();

        $caseStatements = [];
        $ids = [];
        $allFullyReceived = true;
        $anyReceived = false;

        foreach ($items as $item) {
            $receivedQty = round($item->receiptItems->sum('received_quantity'), 3);
            $ids[] = $item->id;
            $caseStatements[] = "WHEN id = {$item->id} THEN {$receivedQty}";

            if ($receivedQty < $item->quantity) {
                $allFullyReceived = false;
            }

            if ($receivedQty > 0) {
                $anyReceived = true;
            }
        }

        if (!empty($caseStatements)) {
            DB::update("UPDATE purchase_items SET received_quantity = CASE " . implode(' ', $caseStatements) . " END WHERE id IN (" . implode(',', $ids) . ")");
        }

        $status = match (true) {
            $allFullyReceived => PurchaseStatus::Received,
            $anyReceived => PurchaseStatus::PartiallyReceived,
            default => PurchaseStatus::Pending,
        };

        $this->updateQuietly(['status' => $status]);
    }

    /**
     * Get purchase receipts
     *
     * @return HasMany
     */
    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            "expected_at" => "datetime",
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            self::BRANCH_COLUMN_NAME,
            "reference_number",
            "supplier_id",
            "total",
            "status",
            "expected_at"
        ];
    }
}
