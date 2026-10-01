<?php

namespace Modules\Support\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Modules\Support\Traits\HasTagsCache;

class Builder extends EloquentBuilder
{
    /** @inheritDoc */
    public function delete(): mixed
    {
        $delete = parent::delete();
        $model = $this->getModel();

        if (in_array(HasTagsCache::class, class_uses($model), true)) {
            $model->clearModelTaggedCache();
        }

        return $delete;
    }


    /** @inheritDoc */
    public function update(array $values): int
    {
        $update = parent::update($values);
        $model = $this->getModel();

        if (in_array(HasTagsCache::class, class_uses($model), true)) {
            $model->clearModelTaggedCache();
        }

        return $update;
    }
}
