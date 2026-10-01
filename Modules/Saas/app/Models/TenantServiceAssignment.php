<?php
namespace Modules\Saas\Models;
use Modules\Support\Eloquent\Model;
class TenantServiceAssignment extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['starts_at'=>'datetime', 'ends_at'=>'datetime']; }
    public function invoice() { return $this->belongsTo(SaasBillingInvoice::class, 'saas_billing_invoice_id'); }
    public function events() { return $this->hasMany(TenantServiceEvent::class); }
    public function reportsAccess(Tenant $tenant, array $effectiveFeatures): bool {
        if (!in_array($this->feature, $effectiveFeatures, true) || !$this->grantsAccess()) return false;
        $current = self::query()->where('tenant_id', $tenant->id)->where('feature', $this->feature)
            ->where('starts_at', '<=', now())->orderByDesc('starts_at')->orderByDesc('id')->value('id');
        return $current === $this->id;
    }
    public function grantsAccess(): bool {
        return $this->status === 'active' && $this->starts_at <= now() && $this->ends_at > now()
            && ($this->billing_mode !== 'separate' || ($this->invoice?->status === 'paid' && !$this->invoice->refunds()->exists()));
    }
}
