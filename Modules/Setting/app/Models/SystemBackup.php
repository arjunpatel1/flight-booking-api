<?php

namespace Modules\Setting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string $disk
 * @property string|null $path
 * @property int|null $size
 * @property array|null $meta
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class SystemBackup extends Model
{
    use HasCreatedBy, HasFilters, HasSortBy, HasActivityLog;

    protected $fillable = [
        'type',
        'status',
        'disk',
        'path',
        'size',
        'meta',
        'error_message',
        'started_at',
        'finished_at',
        'created_by',
    ];

    public function allowedFilterKeys(): array
    {
        return ['type', 'status', 'from', 'to'];
    }

    protected function getSortableAttributes(): array
    {
        return ['type', 'status', 'size', 'created_at', 'finished_at'];
    }

    protected function casts(): array
    {
        return [
            'size' => 'int',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function restores(): HasMany
    {
        return $this->hasMany(SystemRestore::class);
    }
}
