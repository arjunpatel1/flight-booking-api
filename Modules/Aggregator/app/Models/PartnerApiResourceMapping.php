<?php

namespace Modules\Aggregator\Models;

use Modules\Support\Eloquent\Model;

class PartnerApiResourceMapping extends Model
{
    protected $fillable = ['uuid', 'partner_id', 'tenant_id', 'resource_type', 'resource_id', 'external_reference'];
}
