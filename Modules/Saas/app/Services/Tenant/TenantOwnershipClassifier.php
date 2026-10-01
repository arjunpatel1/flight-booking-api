<?php

namespace Modules\Saas\Services\Tenant;

class TenantOwnershipClassifier
{
    public function classify(array $columns): string
    {
        $columns = collect($columns)->map(fn (mixed $column) => is_array($column)
            ? ($column['name'] ?? null)
            : (is_object($column) ? ($column->name ?? null) : $column))
            ->filter()
            ->all();

        if (in_array('tenant_id', $columns, true) && in_array('branch_id', $columns, true)) {
            return 'tenant_branch';
        }

        if (in_array('tenant_id', $columns, true)) {
            return 'tenant';
        }

        if (in_array('branch_id', $columns, true)) {
            return 'branch';
        }

        return 'global_or_reference';
    }
}
