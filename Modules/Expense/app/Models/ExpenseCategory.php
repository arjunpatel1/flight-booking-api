<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Translation\Traits\Translatable;

class ExpenseCategory extends Model
{
    use HasFactory,
        SoftDeletes,
        HasActiveStatus,
        HasBranch,
        HasFilters,
        HasSortBy,
        Translatable;

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'description',
        'is_active',
        'display_order',
    ];

    protected array $translatable = ['name', 'description'];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withOutGlobalBranchPermission();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeSearch($query, string $value): void
    {
        $query->whereLikeTranslation('name', $value)
            ->orLike('code', $value);
    }

    protected function getSortableAttributes(): array
    {
        return [
            'name',
            'code',
            'is_active',
            'display_order',
        ];
    }
}
