<?php

namespace Modules\WhatsAppCenter\Models;

use Modules\Support\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'name',
        'label',
        'category',
        'language_code',
        'body',
        'variables',
        'event',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
