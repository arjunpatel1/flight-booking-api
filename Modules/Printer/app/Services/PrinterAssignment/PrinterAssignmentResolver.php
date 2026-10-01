<?php

namespace Modules\Printer\Services\PrinterAssignment;

use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrinterAssignment;
use Modules\User\Models\User;

class PrinterAssignmentResolver
{
    public function resolve(Order $order, PrintContentType $type): ?Printer
    {
        $user = $this->resolveUser($order);
        $roleIds = $user?->roles?->pluck('id')->filter()->values() ?? collect();

        return $this->assignedPrinter($order->branch_id, 'user', $type, $user?->id)
            ?? $this->assignedRolePrinter($order->branch_id, $type, $roleIds)
            ?? $this->assignedPrinter($order->branch_id, 'print_type', $type)
            ?? $this->assignedPrinter($order->branch_id, 'default');
    }

    private function resolveUser(Order $order): ?User
    {
        $authUser = auth()->user();
        if ($authUser instanceof User) {
            return $authUser;
        }

        $userId = $order->cashier_id ?: $order->waiter_id ?: $order->created_by;
        return $userId ? User::query()->with('roles')->find($userId) : null;
    }

    private function assignedRolePrinter(int $branchId, PrintContentType $type, Collection $roleIds): ?Printer
    {
        if ($roleIds->isEmpty()) {
            return null;
        }

        $assignment = PrinterAssignment::query()
            ->where('branch_id', $branchId)
            ->where('scope', 'role')
            ->where('print_type', $type->value)
            ->whereIn('role_id', $roleIds)
            ->with('printer')
            ->latest('id')
            ->first();

        return $this->availablePrinter($assignment);
    }

    private function assignedPrinter(
        int $branchId,
        string $scope,
        ?PrintContentType $type = null,
        ?int $userId = null
    ): ?Printer {
        $assignment = PrinterAssignment::query()
            ->where('branch_id', $branchId)
            ->where('scope', $scope)
            ->when($type, fn($query) => $query->where('print_type', $type->value))
            ->when($userId, fn($query) => $query->where('user_id', $userId))
            ->with('printer')
            ->latest('id')
            ->first();

        return $this->availablePrinter($assignment);
    }

    private function availablePrinter(?PrinterAssignment $assignment): ?Printer
    {
        return $assignment?->printer?->is_active ? $assignment->printer : null;
    }
}
