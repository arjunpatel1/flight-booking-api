<?php

namespace Modules\Printer\Models;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Branch\Traits\HasBranch;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;

/**
 * @property string $id
 * @property array $printer_config
 * @property string $rendered_bytes
 * @property PrintJobStatus $status
 * @property string|null $deduplication_key
 * @property string|null $claimed_by
 * @property Carbon|null $lease_until
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrintJob extends Model
{
    use HasBranch,
        HasFilters,
        HasSortBy,
        HasTagsCache;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;
    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'printer_config',
        'rendered_bytes',
        'status',
        'deduplication_key',
        'claimed_by',
        'lease_until',
        'error_message',
        'completed_at',
        self::BRANCH_COLUMN_NAME,
    ];

    /**
     * Get allowed filter keys.
     *
     * @return array
     */
    public function allowedFilterKeys(): array
    {
        return [
            'status',
            'from',
            'to',
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /**
     * Boot the model and handle stock adjustments on create, update, and delete.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (PrintJob $job) {
            $job->id = (string)Str::uuid();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'printer_config' => "array",
            'lease_until' => "datetime",
            'completed_at' => "datetime",
        ];
    }

    /**
     * Get sortable attributes.
     *
     * @return array
     */
    protected function getSortableAttributes(): array
    {
        return [
            'status',
            self::BRANCH_COLUMN_NAME,
            'completed_at',
        ];
    }
}
