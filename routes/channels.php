<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

$hasAnyPermission = static function (User $user, array $permissions): bool {
    foreach ($permissions as $permission) {
        if ($user->can($permission)) {
            return true;
        }
    }

    $user->loadMissing('roles.permissions');

    return $user->roles
        ->flatMap(fn ($role) => $role->permissions)
        ->pluck('name')
        ->intersect($permissions)
        ->isNotEmpty();
};

$canUseBranch = static function (User $user, int $branchId): bool {
    if ($user->isSuperAdmin()) {
        return true;
    }

    if ($user->assignedToTenant() && ! $user->assignedToBranch()) {
        return Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($branchId)
            ->where('tenant_id', $user->tenant_id)
            ->exists();
    }

    $resolvedBranchId = $user->assignedToBranch()
        ? $user->branch_id
        : $user->effective_branch?->id;

    return (int) $resolvedBranchId === $branchId;
};

$mayUsePosBranch = static function (User $user, int $branchId) use ($hasAnyPermission, $canUseBranch): bool {
    $canUsePos = $hasAnyPermission($user, [
        'admin.pos.index',
        'admin.pos.kitchen_viewer',
        'admin.tables.viewer',
        'admin.orders.active',
    ]);

    return $canUsePos && $canUseBranch($user, $branchId);
};

Broadcast::channel('pos.orders.branch.{branchId}', $mayUsePosBranch);
Broadcast::channel('pos.kitchen.branch.{branchId}', $mayUsePosBranch);
Broadcast::channel('pos.tables.branch.{branchId}', $mayUsePosBranch);

$mayUseVoiceBranch = static function (User $user, int $branchId) use ($hasAnyPermission, $canUseBranch): bool {
    $canUseVoice = $hasAnyPermission($user, [
        'admin.voice.settings.edit',
        'admin.pos.index',
        'admin.pos.kitchen_viewer',
        'admin.tables.viewer',
        'admin.orders.active',
    ]);

    return $canUseVoice && $canUseBranch($user, $branchId);
};

Broadcast::channel('branch.{branchId}', $mayUseVoiceBranch);
Broadcast::channel(
    'notifications.user.{userId}',
    static fn (User $user, int $userId): bool => (int) $user->id === $userId
);

/*
|--------------------------------------------------------------------------
| v2 — tenant-namespaced channel authorizers (Phase 2.5)
|--------------------------------------------------------------------------
|
| These mirror the v1 authorizers above with one added guard: the branch/user
| must actually belong to {tenantId}. That extra check is what makes the v2
| channel collision-proof — a user of tenant A cannot authorize
| tenant.B.branch.6 even if they have a branch 6 of their own.
|
| Registered unconditionally so a migrated client can subscribe as soon as it
| is deployed, regardless of the broadcast-side flag. Authorizing a v2 channel
| is harmless while v2 broadcasts are off; it simply receives nothing yet.
|
| Telemetry: each successful authorization records a v1/v2 subscription so
| retirement of v1 can be decided from data.
*/
$telemetry = static function (string $version): void {
    try {
        app(\Modules\Saas\Support\RealtimeChannelTelemetry::class)->recordSubscribe($version);
    } catch (\Throwable) {
        // never let telemetry block an authorization
    }
};

$branchBelongsToTenant = static function (int $branchId, int $tenantId): bool {
    return Branch::query()->withoutGlobalScopes()
        ->whereKey($branchId)->where('tenant_id', $tenantId)->exists();
};

foreach (['orders', 'kitchen', 'tables'] as $kind) {
    Broadcast::channel(
        "pos.tenant.{tenantId}.{$kind}.branch.{branchId}",
        static function (User $user, int $tenantId, int $branchId) use ($mayUsePosBranch, $branchBelongsToTenant, $telemetry): bool {
            $ok = $branchBelongsToTenant($branchId, $tenantId) && $mayUsePosBranch($user, $branchId);
            if ($ok) {
                $telemetry('v2');
            }

            return $ok;
        }
    );
}

Broadcast::channel(
    'tenant.{tenantId}.branch.{branchId}',
    static function (User $user, int $tenantId, int $branchId) use ($mayUseVoiceBranch, $branchBelongsToTenant, $telemetry): bool {
        $ok = $branchBelongsToTenant($branchId, $tenantId) && $mayUseVoiceBranch($user, $branchId);
        if ($ok) {
            $telemetry('v2');
        }

        return $ok;
    }
);

Broadcast::channel(
    'notifications.tenant.{tenantId}.user.{userId}',
    static function (User $user, int $tenantId, int $userId) use ($telemetry): bool {
        $ok = (int) $user->id === $userId && (int) $user->tenant_id === $tenantId;
        if ($ok) {
            $telemetry('v2');
        }

        return $ok;
    }
);

Broadcast::channel(
    'customer-group.tenant.{tenantId}.group.{groupId}',
    static function (User $user, int $tenantId, string $groupId) use ($telemetry): bool {
        $ok = (int) $user->tenant_id === $tenantId
            && $user->hasRole(\Modules\User\Enums\DefaultRole::Customer->value)
            && \Modules\Cart\Models\CustomerGroupCart::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($groupId)
                ->whereIn('status', ['active', 'locked', 'checkout'])
                ->where('expires_at', '>', now())
                ->exists()
            && \Modules\Cart\Models\CustomerGroupParticipant::query()
                ->where('tenant_id', $tenantId)
                ->where('group_cart_id', $groupId)
                ->where('customer_id', $user->id)
                ->where('status', 'active')
                ->exists();
        if ($ok) {
            $telemetry('v2');
        }

        return $ok;
    }
);
