<?php

namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryProviderApiLog extends Model
{
    protected $guarded = [];
    protected $casts = ['successful' => 'boolean', 'request_summary' => 'array', 'response_summary' => 'array'];
}
