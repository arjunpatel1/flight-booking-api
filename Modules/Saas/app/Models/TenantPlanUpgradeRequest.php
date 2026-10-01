<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Support\Eloquent\Model;

class TenantPlanUpgradeRequest extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'tenant_id', 'current_plan_id', 'requested_plan_id', 'requested_by', 'status', 'contact_name', 'contact_email', 'contact_phone', 'reason', 'notes', 'decided_by', 'decision_note', 'decided_at'];
    protected $casts = ['decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function currentPlan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'current_plan_id'); }
    public function requestedPlan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'requested_plan_id'); }
}
