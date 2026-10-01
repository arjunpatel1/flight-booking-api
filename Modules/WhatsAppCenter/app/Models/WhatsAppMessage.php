<?php

namespace Modules\WhatsAppCenter\Models;

use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use BelongsToTenant;
    protected $table = 'whatsapp_messages';
    protected $fillable = ['tenant_id', 'conversation_id', 'provider_message_id', 'direction', 'type', 'body', 'payload', 'status', 'sent_at', 'delivered_at', 'read_at'];
    protected function casts(): array { return ['payload' => 'array', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime']; }
}
