<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class ReportExportAudit extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'report_export_id',
        'branch_id',
        'requested_by',
        'report_key',
        'format',
        'ip_address',
        'user_agent',
        'filters',
        'row_count',
        'downloaded_at',
        'expires_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'downloaded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function reportExport()
    {
        return $this->belongsTo(ReportExport::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('requested_by', $userId);
    }

    public function scopeForReport($query, $reportKey)
    {
        return $query->where('report_key', $reportKey);
    }

    public function scopeDownloaded($query)
    {
        return $query->whereNotNull('downloaded_at');
    }

    public function scopePending($query)
    {
        return $query->whereNull('downloaded_at');
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function markAsDownloaded(): void
    {
        $this->update(['downloaded_at' => now()]);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isDownloaded(): bool
    {
        return $this->downloaded_at !== null;
    }
}
