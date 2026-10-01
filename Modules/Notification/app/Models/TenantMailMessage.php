<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantMailMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'thread_id', 'direction', 'from_address', 'to_address',
        'body_text', 'body_html', 'provider_message_id', 'status', 'sent_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'body_text' => 'encrypted',
            'body_html' => 'encrypted',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TenantMailThread::class, 'thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TenantMailAttachment::class, 'message_id');
    }
}
