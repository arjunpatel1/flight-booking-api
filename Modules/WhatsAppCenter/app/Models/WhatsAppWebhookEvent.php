<?php

namespace Modules\WhatsAppCenter\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class WhatsAppWebhookEvent extends Model
{
    use BelongsToTenant;
    protected $table = 'whatsapp_webhook_events';
    protected $fillable = ['tenant_id', 'provider_profile_id', 'provider_event_id', 'provider_order_id', 'event_type', 'payload_hash', 'payload', 'status', 'processed_at', 'error'];
    protected function casts(): array { return ['payload' => 'array', 'processed_at' => 'datetime']; }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id');
    }
}
