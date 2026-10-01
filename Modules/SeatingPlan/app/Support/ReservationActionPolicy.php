<?php

namespace Modules\SeatingPlan\Support;

use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ActionPolicy;
use Modules\User\Models\User;

/**
 * Canonical backend authority for reservation actions. Mirrors OrderActionPolicy/
 * TableActionPolicy: `allowed` already folds in the user's permission, so frontends
 * consume `action_policy` without local status/permission guessing.
 *
 * Status rules mirror the existing UI exactly (no workflow change):
 *  - confirm: status = pending
 *  - seat:    status in {pending, confirmed}
 *  - cancel:  status NOT in {cancelled, completed}
 */
class ReservationActionPolicy
{
    public static function make(TableReservation $reservation, ?User $user): array
    {
        $policy = new self($reservation, $user);
        $status = $reservation->status;
        $terminal = in_array($status, [ReservationStatus::Cancelled, ReservationStatus::Completed], true);
        $reason = __('seatingplan::reservations.action_not_allowed');

        return [
            'view' => $policy->forAction(true, null, ['admin.reservations.index'], 'reservation_view'),
            'confirm' => $policy->forAction(
                $status === ReservationStatus::Pending,
                $reason,
                ['admin.reservations.edit'],
                'reservation_confirm',
            ),
            'seat' => $policy->forAction(
                in_array($status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true),
                $reason,
                ['admin.reservations.edit'],
                'reservation_seat',
            ),
            'cancel' => $policy->forAction(
                ! $terminal,
                $reason,
                ['admin.reservations.edit'],
                'reservation_cancel',
                confirmationRequired: true,
            ),
        ];
    }

    private function __construct(
        private readonly TableReservation $reservation,
        private readonly ?User $user,
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
