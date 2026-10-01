<?php

namespace Modules\Voice\Models;

use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $agent_id
 * @property string $bundle
 * @property \Illuminate\Support\Carbon $created_at
 */
class VoiceExportBundle extends Model
{
    use HasBranch;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'agent_id', 'bundle'];
}
