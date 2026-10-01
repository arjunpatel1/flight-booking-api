<?php

namespace Modules\SeatingPlan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

/**
 * @property int $id
 * @property int $table_id
 * @property-read  Table $table
 * @property int|null $changed_by
 * @property-read  User|null $changedBy
 * @property TableStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class TableStatusLog extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "table_id",
        "status",
        "changed_by",
        "note",
    ];

    /**
     * Get table
     *
     * @return BelongsTo
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class)
            ->withOutGlobalBranchPermission()
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
            "status" => TableStatus::class,
        ];
    }
}
