<?php

namespace Modules\Branch\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\User\Models\User;

readonly class TenantPermissionScope implements Scope
{
    public function __construct(private User $user)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where("{$model->getTable()}.tenant_id", $this->user->tenant_id);
    }
}
