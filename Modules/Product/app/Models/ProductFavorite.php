<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $branch_id
 * @property int $product_id
 * @property-read User $user
 * @property-read Branch|null $branch
 * @property-read Product $product
 */
class ProductFavorite extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'product_id',
    ];

    /**
     * Get the user that owns the favorite.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the branch that owns the favorite.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the product that is favorited.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
