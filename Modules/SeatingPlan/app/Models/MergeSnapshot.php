<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;


/**
 * @property int $id
 * @property int $table_merge_id
 * @property-read TableMerge $tableMerge
 * @property array $snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MergeSnapshot extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "table_merge_id",
        "snapshot"
    ];

    /**
     * Get table merge
     *
     * @return BelongsTo
     */
    public function tableMerge(): BelongsTo
    {
        return $this->belongsTo(TableMerge::class)
            ->withOutGlobalBranchPermission()
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
            "snapshot" => "array",
        ];
    }
}
