<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantMailAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'message_id', 'disk', 'path', 'original_name', 'mime_type',
        'size', 'status', 'quarantine_reason',
    ];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['original_name' => 'encrypted', 'size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TenantMailMessage::class, 'message_id');
    }
}
