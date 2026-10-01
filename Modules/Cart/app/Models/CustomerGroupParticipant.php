<?php

namespace Modules\Cart\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasUuid;
use Modules\User\Models\User;

class CustomerGroupParticipant extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroupCart::class, 'group_cart_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerGroupCartItem::class, 'participant_id');
    }
}
