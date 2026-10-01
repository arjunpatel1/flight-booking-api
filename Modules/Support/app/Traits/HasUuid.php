<?php

namespace Modules\Support\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 */
trait HasUuid
{
    /**
     * Save model uuid if not filled
     *
     * @return void
     */
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model) {
            /** @var Model|self $model */
            if (empty($model->uuid)) {
                $model->uuid = (string)Str::uuid();
            }
        });
    }

    /**
     * Return a model by uuid
     *
     * @param string $uuid
     * @return Model|null
     */
    public static function findByUuid(string $uuid): ?Model
    {
        return static::query()->where('uuid', $uuid)->first();
    }

    /**
     * Return a model by uuid, and fail if not exists
     *
     * @param string $uuid
     * @return Model
     */
    public static function findOrFailByUuid(string $uuid): Model
    {
        return static::query()->where('uuid', $uuid)->firstOrFail();
    }
}
