<?php

namespace Modules\WhatsAppCenter\Models;

use Modules\Branch\Traits\HasBranch;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class WhatsAppOrderSession extends Model
{
    use BelongsToTenant, HasBranch;
    protected $table = 'whatsapp_order_sessions';
    protected $fillable = ['uuid', 'tenant_id', 'branch_id', 'conversation_id', 'cart_uuid', 'order_id', 'order_type', 'state', 'delivery_address', 'quoted_total', 'expires_at', 'confirmed_at'];
    protected function casts(): array { return ['delivery_address' => 'array', 'quoted_total' => 'decimal:2', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime']; }
    public function conversation() { return $this->belongsTo(WhatsAppConversation::class, 'conversation_id'); }
    public function branch() { return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'branch_id'); }
    public function order() { return $this->belongsTo(\Modules\Order\Models\Order::class, 'order_id'); }
}
