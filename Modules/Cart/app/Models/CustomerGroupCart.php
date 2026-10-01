<?php

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class CustomerGroupCart extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'locked_at' => 'datetime', 'completed_at' => 'datetime', 'quote_snapshot' => 'array'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CustomerGroupParticipant::class, 'group_cart_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerGroupCartItem::class, 'group_cart_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerGroupCartEvent::class, 'group_cart_id');
    }
}
