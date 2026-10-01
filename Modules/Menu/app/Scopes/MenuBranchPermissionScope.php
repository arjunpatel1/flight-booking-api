<?php

namespace Modules\Menu\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Log;
use Modules\User\Models\User;

readonly class MenuBranchPermissionScope implements Scope
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
        $builder->whereHas('menu', fn(Builder $query) => $query->where("branch_id", $this->user->branch_id));
    }
}
