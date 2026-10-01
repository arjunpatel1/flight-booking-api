<?php

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class CustomerGroupCartEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroupCart::class, 'group_cart_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_customer_id');
    }
}
