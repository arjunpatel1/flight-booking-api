<?php

namespace Modules\WhatsAppCenter\Models;

use Modules\Branch\Traits\HasBranch;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class WhatsAppConversation extends Model
{
    use BelongsToTenant, HasBranch;
    protected $table = 'whatsapp_conversations';
    protected $fillable = ['uuid', 'tenant_id', 'branch_id', 'assignment_id', 'customer_phone', 'customer_name', 'state', 'assigned_user_id', 'last_message_at', 'closed_at'];
    protected function casts(): array { return ['last_message_at' => 'datetime', 'closed_at' => 'datetime']; }
    public function messages() { return $this->hasMany(WhatsAppMessage::class, 'conversation_id'); }
    public function assignment() { return $this->belongsTo(WhatsAppTenantAssignment::class, 'assignment_id'); }
    public function branch() { return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'branch_id'); }
}
