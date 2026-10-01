<?php

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\Product;
use Modules\Support\Eloquent\Model;

class CustomerGroupCartItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'unit_price_snapshot' => 'decimal:4'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroupCart::class, 'group_cart_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(CustomerGroupParticipant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
