<?php

namespace Modules\Report\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Branch\Models\Branch;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

class DashboardSnapshot extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'snapshot_type',
        'business_date',
        'period',
        'data',
        'expires_at',
        'generated_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'data' => 'array',
        'expires_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForType($query, $type)
    {
        return $query->where('snapshot_type', $type);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('business_date', $date);
    }

    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('generated_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
