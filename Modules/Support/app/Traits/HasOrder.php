<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $order
 */
trait HasOrder
{
    const ORDER_COLUMN_NAME = 'order';

    /**
     * Boot the model.
     */
    public static function bootHasOrder(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->order)) {
                /** @var self $model */
                $model->order = static::query()->max(self::ORDER_COLUMN_NAME) + 1;
            }
        });
    }
}
