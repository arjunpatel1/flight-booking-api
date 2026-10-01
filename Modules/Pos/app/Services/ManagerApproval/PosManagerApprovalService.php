<?php

namespace Modules\Pos\Services\ManagerApproval;

use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Pos\Models\PosManagerApproval;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class PosManagerApprovalService
{
    private const SUPPORTED_ACTIONS = [
        'order.cancel',
        'order.refund',
    ];

    public function metaForAction(int $branchId, string $action, string $resourceType, string $resourceId): array
    {
        $managers = $this->configuredManagers($branchId);

        return [
            // Cancellation/refund are privileged actions. Missing manager setup
            // must block the action instead of silently downgrading security.
            'required' => true,
            'configured' => $managers->isNotEmpty(),
            'action' => $action,
            'branch_id' => $branchId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'managers' => $managers->values(),
        ];
    }

    public function consume(
        ?string $token,
        int $branchId,
        string $action,
        string $resourceType,
        string $resourceId,
        array $performedPayload = [],
    ): ?PosManagerApproval {
        abort_unless($this->storageReady(), 503, __('pos::pos.manager_approval_storage_not_ready'));
        abort_if(empty($token), 422, __('pos::pos.manager_approval_required'));

        $approval = PosManagerApproval::query()
            ->where('approval_token', $token)
            ->lockForUpdate()
            ->first();

        abort_if(
            ! $approval
            || $approval->status !== 'approved'
            || $approval->branch_id !== $branchId
            || $approval->action !== $action
            || $approval->resource_type !== $resourceType
            || $approval->resource_id !== $resourceId
            || ! $approval->expires_at
            || $approval->expires_at->isPast(),
            422,
            __('pos::pos.manager_approval_invalid')
        );

        $approval->forceFill([
            'status' => 'consumed',
            'consumed_at' => now(),
            'consumed_by' => auth()->id(),
            'payload' => [
                ...($approval->payload ?? []),
                'performed' => $performedPayload,
            ],
        ])->save();

        activity('pos_manager_approvals')
            ->event('consumed')
            ->causedBy(auth()->user())
            ->performedOn($approval)
            ->withProperties($approval->toAuditPayload())
            ->log('POS manager approval consumed');

        return $approval;
    }

    public function isSupportedAction(string $action): bool
    {
        return in_array($action, self::SUPPORTED_ACTIONS, true);
    }

    public function actorCanAccessBranch(User $actor, int $branchId): bool
    {
        $branch = Branch::query()->withoutGlobalScopes()->select(['id', 'tenant_id'])->find($branchId);

        if (! $branch) {
            return false;
        }

        if ($actor->isSuperAdmin() && ! $actor->assignedToTenant() && ! $actor->assignedToBranch()) {
            return true;
        }

        return $actor->tenantId() !== null
            && $actor->tenantId() === (int) $branch->tenant_id
            && (! $actor->assignedToBranch() || $actor->branchId() === $branch->id);
    }

    public function canApproveAtBranch(User $manager, int $branchId, User $actor): bool
    {
        if (! $this->actorCanAccessBranch($actor, $branchId)) {
            return false;
        }

        // Platform operators are never exposed in tenant manager selectors.
        // They may approve only their own request, under the explicit
        // SuperAdmin role, for a branch which was resolved above.
        if ($manager->isSuperAdmin() && ! $manager->assignedToTenant() && ! $manager->assignedToBranch()) {
            return $actor->is($manager);
        }

        return $this->configuredManagers($branchId)->contains('id', $manager->id);
    }

    /**
     * Managers visible in administration, including accounts which still need
     * to configure a POS PIN. Approval itself continues to require a PIN.
     */
    public function eligibleManagers(?int $branchId = null)
    {
        if (! $this->storageReady()) {
            return collect();
        }

        $tenantId = $branchId
            ? Branch::query()->withoutGlobalScopes()->whereKey($branchId)->value('tenant_id')
            : auth()->user()?->tenantId();

        if (! $tenantId) {
            return collect();
        }

        return User::query()
            ->withOutGlobalBranchPermission()
            ->select(['id', 'name', 'branch_id', 'pos_pin_hash'])
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($query) => $query->where(
                fn ($scope) => $scope->where('branch_id', $branchId)->orWhereNull('branch_id')
            ))
            ->where(function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', [
                    DefaultRole::SuperAdmin->value,
                    DefaultRole::Admin->value,
                    DefaultRole::EnterpriseAdmin->value,
                    DefaultRole::AdminBranch->value,
                    DefaultRole::Manager->value,
                ]))->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'admin.orders.cancel'))
                    ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('name', 'admin.orders.cancel'));
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'pin_configured' => ! empty($manager->pos_pin_hash),
            ]);
    }

    public function storageReady(): bool
    {
        return Schema::hasTable('pos_manager_approvals')
            && Schema::hasColumn('pos_manager_approvals', 'consumed_at')
            && Schema::hasColumn('users', 'pos_pin_hash');
    }

    private function configuredManagers(int $branchId)
    {
        if (! $this->storageReady()) {
            return collect();
        }

        return $this->eligibleManagers($branchId)
            ->where('pin_configured', true)
            ->map(fn (array $manager) => [
                'id' => $manager['id'],
                'name' => $manager['name'],
            ])
            ->values();
    }
}
