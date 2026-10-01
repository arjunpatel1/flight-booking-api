<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class TenantMailThread extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'reference', 'subject', 'participant_email', 'last_message_at', 'read_at'];

    public function messages(): HasMany
    {
        return $this->hasMany(TenantMailMessage::class, 'thread_id');
    }

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
