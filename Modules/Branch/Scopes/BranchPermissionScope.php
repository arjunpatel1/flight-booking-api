<?php

namespace Modules\Branch\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Log;
use Modules\Tax\Models\Tax;
use Modules\User\Models\User;

readonly class BranchPermissionScope implements Scope
{
    /**
     * Create a new instance of BranchPermissionScope
     *
     * @param User $user
     */
    public function __construct(private User $user)
    {
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param Builder $builder
     * @param Model $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            fn(Builder $query) => $query
                ->where("{$model->getTable()}.branch_id", $this->user->branch_id)
                ->when($model->getMorphClass() == Tax::class, fn(Builder $query) => $query->orWhereNull("{$model->getTable()}.branch_id"))
        );
    }
}
