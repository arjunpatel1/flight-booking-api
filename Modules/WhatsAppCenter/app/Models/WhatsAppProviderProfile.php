<?php

namespace Modules\WhatsAppCenter\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasUuid;

class WhatsAppProviderProfile extends Model
{
    use BelongsToTenant, HasUuid;

    protected $table = 'whatsapp_provider_profiles';

    protected $fillable = ['uuid', 'tenant_id', 'name', 'ownership_mode', 'provider', 'credentials', 'credential_version', 'status', 'validated_at', 'webhook_last_received_at', 'last_error', 'is_active'];
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'validated_at' => 'datetime', 'webhook_last_received_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsAppPhoneNumber::class, 'provider_profile_id');
    }
}
