<?php

namespace Modules\Printer\Models;

use Carbon\Carbon;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property string $agent_id
 * @property string $secret
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrintAgent extends Model
{
    use HasCreatedBy,
        HasActiveStatus,
        HasBranch,
        HasSortBy,
        HasFilters,
        Translatable,
        HasActivityLog;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        self::BRANCH_COLUMN_NAME,
        self::ACTIVE_COLUMN_NAME,
        'agent_id',
        'secret',
        'name',
        'last_seen_at',
        'status',
        'version',
        'platform',
        'machine_name',
        'queue_status',
        'printer_inventory',
        'health_payload',
        'last_error',
        'last_print_success_at',
        'last_print_failed_at',
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['secret'];

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (PrintAgent $agent) {
            $agent->secret = bin2hex(random_bytes(40));
        });

    }

    /**
     * Sync active agent
     *
     * @deprecated Multiple active print agents are allowed per branch because
     * each terminal/workstation can run its own local silent-print agent.
     *
     * @return void
     */
    public function syncActiveAgent(): void
    {
        //
    }

    /**
     * Determine id has active agent
     *
     * @param int|null $exceptionId
     * @param int|null $branchId
     * @return bool
     */
    public static function hasActiveAgent(?int $exceptionId = null, ?int $branchId = null): bool
    {
        return static::withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where(function ($query) use ($exceptionId) {
                $query->where('id', '<>', $exceptionId);
            })
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "agent_id",
            "last_seen_at",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => "encrypted",
            'last_seen_at' => "datetime",
            'queue_status' => "array",
            'printer_inventory' => "array",
            'health_payload' => "array",
            'last_print_success_at' => "datetime",
            'last_print_failed_at' => "datetime",
            self::ACTIVE_COLUMN_NAME => "boolean",
        ];
    }
}
