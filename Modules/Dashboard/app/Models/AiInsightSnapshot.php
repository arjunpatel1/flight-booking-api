<?php

namespace Modules\Dashboard\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;
use Modules\User\Models\User;

class AiInsightSnapshot extends Model
{
    protected $fillable = [
        'window_days',
        'currency',
        'summary',
        'recommendations',
        'payload',
        'generated_at',
        'generated_by',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by')->withTrashed();
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'recommendations' => 'array',
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
