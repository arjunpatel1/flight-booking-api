<?php

namespace Modules\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class AsSerialize implements CastsAttributes
{
    /** @inheritDoc */
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        return unserialize($value);
    }

    /** @inheritDoc */
    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        return serialize($value);
    }
}
