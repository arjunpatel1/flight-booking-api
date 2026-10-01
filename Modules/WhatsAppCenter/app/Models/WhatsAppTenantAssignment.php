<?php

namespace Modules\WhatsAppCenter\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasUuid;

class WhatsAppTenantAssignment extends Model
{
    use BelongsToTenant, HasUuid;

    protected $table = 'whatsapp_tenant_assignments';

    protected $fillable = ['uuid', 'tenant_id', 'provider_profile_id', 'phone_number_id', 'ownership_mode', 'allowed_branch_ids', 'capabilities', 'monthly_message_limit', 'monthly_order_limit', 'is_active', 'suspended_at'];
    protected function casts(): array { return ['allowed_branch_ids' => 'array', 'capabilities' => 'array', 'is_active' => 'boolean', 'suspended_at' => 'datetime']; }
    // A platform-managed profile and number belong to the control plane and
    // may have no tenant_id. The tenant-scoped assignment grants access to
    // these specific records, so the relation must not hide them.
    public function profile(): BelongsTo { return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id')->withoutGlobalTenant(); }
    public function phoneNumber(): BelongsTo { return $this->belongsTo(WhatsAppPhoneNumber::class, 'phone_number_id'); }
}
