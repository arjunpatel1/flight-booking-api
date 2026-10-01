<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenStationCategory extends Model
{
    protected $table = 'kitchen_station_categories';

    protected $fillable = [
        'kitchen_station_id',
        'category_id',
        'priority',
        'prep_time_override',
    ];

    protected $casts = [
        'priority' => 'integer',
        'prep_time_override' => 'integer',
    ];

    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(\Modules\Category\Models\Category::class);
    }
}
