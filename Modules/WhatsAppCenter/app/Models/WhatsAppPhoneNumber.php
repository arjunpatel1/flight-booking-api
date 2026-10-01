<?php

namespace Modules\WhatsAppCenter\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasUuid;

class WhatsAppPhoneNumber extends Model
{
    use HasUuid;

    protected $table = 'whatsapp_phone_numbers';

    protected $fillable = ['uuid', 'provider_profile_id', 'provider_phone_id', 'display_number', 'display_name', 'status', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function profile(): BelongsTo { return $this->belongsTo(WhatsAppProviderProfile::class, 'provider_profile_id'); }
}
