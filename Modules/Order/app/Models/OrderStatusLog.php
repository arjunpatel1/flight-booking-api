<?php

namespace Modules\Order\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Enums\OrderStatus;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property int $order_id
 * @property-read  Order $order
 * @property int|null $changed_by
 * @property-read  User|null $changedBy
 * @property int|null $reason_id
 * @property-read  Reason|null $reason
 * @property OrderStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrderStatusLog extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "order_id",
        "changed_by",
        "reason_id",
        "status",
        "note",
    ];

    /**
     * Get order
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)
            ->withOutGlobalBranchPermission();
    }

    /**
     * Get reason
     *
     * @return BelongsTo
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class)
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get changed by
     *
     * @return BelongsTo
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "changed_by")
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->withTrashed();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "status" => OrderStatus::class,
        ];
    }
}
