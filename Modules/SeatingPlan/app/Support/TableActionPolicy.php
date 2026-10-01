<?php

namespace Modules\SeatingPlan\Support;

use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\Support\ActionPolicy;
use Modules\User\Models\User;

/**
 * Canonical backend authority for table-level actions (POS table viewer).
 * Mirrors OrderActionPolicy: `allowed` already folds in the user's permission,
 * so frontends consume `action_policy` without any local can()/business guess.
 *
 * Business rules are sourced from the existing behaviour:
 *  - merge: table is not cleaning/merged and is not part of a current merge
 *  - split: table is merged and has no remaining active orders
 *  - make_available: table is awaiting cleaning
 *  - transfer/assign_waiter/create_order/view: permission-gated
 */
class TableActionPolicy
{
    public static function make(Table $table, ?User $user, int $activeOrderCount = 0): array
    {
        $policy = new self($table, $user, $activeOrderCount);

        $notMergedOrCleaning = ! in_array($table->status, [TableStatus::Cleaning, TableStatus::Merged], true)
            && is_null($table->current_merge_id);
        $isMerged = ! is_null($table->current_merge_id) || $table->status === TableStatus::Merged;

        return [
            'view' => $policy->forAction(true, null, ['admin.tables.viewer'], 'table_view'),
            'create_order' => $policy->forAction(
                true,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.orders.create'],
                'table_create_order',
            ),
            'merge' => $policy->forAction(
                $notMergedOrCleaning,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.tables.merge'],
                'table_merge',
            ),
            'transfer' => $policy->forAction(
                $activeOrderCount > 0,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.tables.transfer'],
                'table_transfer',
            ),
            'split' => $policy->forAction(
                $isMerged && $activeOrderCount === 0,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.tables.split'],
                'table_split',
                confirmationRequired: true,
            ),
            'assign_waiter' => $policy->forAction(
                true,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.tables.assign_waiter'],
                'table_assign_waiter',
            ),
            'make_available' => $policy->forAction(
                $table->status === TableStatus::Cleaning,
                __('seatingplan::tables.action_not_allowed'),
                ['admin.tables.update_status'],
                'table_make_available',
            ),
        ];
    }

    private function __construct(
        private readonly Table $table,
        private readonly ?User $user,
        private readonly int $activeOrderCount,
    ) {
    }

    private function forAction(
        bool $businessAllowed,
        ?string $businessReason,
        array $permissions,
        string $loadingKey,
        bool $confirmationRequired = false,
    ): array {
        $permissionAllowed = $this->canAny($permissions);
        $allowed = $businessAllowed && $permissionAllowed;
        $reason = match (true) {
            ! $permissionAllowed => 'Permission denied.',
            ! $businessAllowed => $businessReason,
            default => null,
        };

        return ActionPolicy::make(
            allowed: $allowed,
            reason: $reason,
            loadingKey: $loadingKey,
            permissions: $permissions,
            confirmationRequired: $confirmationRequired,
        );
    }

    private function canAny(array $permissions): bool
    {
        if (empty($permissions)) {
            return true;
        }

        return $this->user !== null && collect($permissions)->contains(
            fn(string $permission) => $this->user->can($permission)
        );
    }
}
