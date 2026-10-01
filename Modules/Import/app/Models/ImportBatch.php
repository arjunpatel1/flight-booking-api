<?php

namespace Modules\Import\Models;

use Illuminate\Database\Eloquent\Builder;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Enums\ImportType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\User\Models\User;

class ImportBatch extends Model
{
    use HasCreatedBy, HasFilters, HasSortBy;

    protected $fillable = [
        'type',
        'status',
        'original_filename',
        'source_file_path',
        'options',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'errors',
        'started_at',
        'finished_at',
    ];

    public function allowedFilterKeys(): array
    {
        return ['search', 'type', 'status', 'from', 'to'];
    }

    public function scopeSearch(Builder $query, string $value): void
    {
        $query->whereLike('original_filename', $value);
    }

    /**
     * Import files are tenant-owned through the user who created the batch.
     * Platform administrators retain estate-wide visibility; restaurant users
     * may only access batches created by users in their own tenant.
     */
    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        $isPlatformAdmin = $actor?->isSuperAdmin()
            && ! $actor->assignedToTenant()
            && ! $actor->assignedToBranch();

        if ($isPlatformAdmin) {
            return $query;
        }

        $tenantId = $actor?->tenantId();
        if (! $tenantId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('createdBy', fn (Builder $creator) => $creator
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId));
    }

    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'options' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
