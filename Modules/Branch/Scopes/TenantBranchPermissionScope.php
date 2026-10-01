<?php

namespace Modules\Branch\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\User\Models\User;

readonly class TenantBranchPermissionScope implements Scope
{
    public function __construct(private User $user)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereIn("{$model->getTable()}.branch_id", function ($query) {
            $query->select('id')
                ->from('branches')
                ->where('tenant_id', $this->user->tenant_id);
        });
    }
}
