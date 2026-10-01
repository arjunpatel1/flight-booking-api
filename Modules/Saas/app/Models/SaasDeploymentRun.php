<?php

namespace Modules\Saas\Models;

use Modules\Support\Eloquent\Model;

class SaasDeploymentRun extends Model
{
    protected $fillable = ['uuid', 'requested_by', 'target', 'branch', 'apply'];

    protected function casts(): array
    {
        return [
            'apply' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
