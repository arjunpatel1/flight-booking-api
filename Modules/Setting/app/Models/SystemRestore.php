<?php

namespace Modules\Setting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * @property int $id
 * @property int $system_backup_id
 * @property int|null $safety_backup_id
 * @property string $status
 * @property string|null $reason
 * @property array|null $meta
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class SystemRestore extends Model
{
    use HasCreatedBy, HasFilters, HasSortBy, HasActivityLog;

    protected $fillable = [
        'system_backup_id',
        'safety_backup_id',
        'status',
        'reason',
        'meta',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(SystemBackup::class, 'system_backup_id');
    }

    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(SystemBackup::class, 'safety_backup_id');
    }

    public function allowedFilterKeys(): array
    {
        return ['system_backup_id', 'status', 'from', 'to'];
    }

    protected function getSortableAttributes(): array
    {
        return ['status', 'created_at', 'finished_at'];
    }

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
