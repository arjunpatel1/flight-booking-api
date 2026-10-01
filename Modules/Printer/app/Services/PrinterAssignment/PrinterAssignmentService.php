<?php

namespace Modules\Printer\Services\PrinterAssignment;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrinterAssignment;
use Modules\User\Models\Role;
use Modules\User\Models\User;

class PrinterAssignmentService
{
    public function resolveBranchId(?int $branchId = null): int
    {
        if (auth()->user()?->assignedToBranch()) {
            return (int) auth()->user()->branch_id;
        }

        if (! empty($branchId)) {
            return $branchId;
        }

        return (int) Branch::query()->value('id');
    }

    public function show(?int $branchId): array
    {
        $branchId = $this->resolveBranchId($branchId);

        $assignments = PrinterAssignment::query()
            ->where('branch_id', $branchId)
            ->get();

        return [
            'branch_id' => $branchId,
            'default_printer_id' => $assignments->firstWhere('scope', 'default')?->printer_id,
            'print_types' => collect(PrintContentType::cases())->mapWithKeys(fn(PrintContentType $type) => [
                $type->value => $assignments
                    ->where('scope', 'print_type')
                    ->first(fn(PrinterAssignment $assignment) => $assignment->print_type === $type)?->printer_id,
            ])->all(),
            'users' => $assignments
                ->where('scope', 'user')
                ->map(fn(PrinterAssignment $assignment) => [
                    'user_id' => $assignment->user_id,
                    'print_type' => $assignment->print_type?->value,
                    'printer_id' => $assignment->printer_id,
                ])
                ->values(),
            'roles' => $assignments
                ->where('scope', 'role')
                ->map(fn(PrinterAssignment $assignment) => [
                    'role_id' => $assignment->role_id,
                    'print_type' => $assignment->print_type?->value,
                    'printer_id' => $assignment->printer_id,
                ])
                ->values(),
        ];
    }

    public function meta(?int $branchId = null): array
    {
        return [
            'branches' => Branch::list(),
            'printers' => Printer::list($branchId),
            'print_types' => PrintContentType::toArrayTrans(),
            'users' => User::list($branchId)->values(),
            'roles' => Role::list(auth()->user()?->assignedToBranch() ?? false)->values(),
        ];
    }

    public function save(array $data): array
    {
        $branchId = $this->resolveBranchId((int) ($data['branch_id'] ?? 0));

        DB::transaction(function () use ($data, $branchId) {
            PrinterAssignment::query()->where('branch_id', $branchId)->delete();

            if (! empty($data['default_printer_id'])) {
                PrinterAssignment::query()->create([
                    'branch_id' => $branchId,
                    'scope' => 'default',
                    'printer_id' => $data['default_printer_id'],
                ]);
            }

            foreach (($data['print_types'] ?? []) as $printType => $printerId) {
                if ($printerId) {
                    PrinterAssignment::query()->create([
                        'branch_id' => $branchId,
                        'scope' => 'print_type',
                        'print_type' => $printType,
                        'printer_id' => $printerId,
                    ]);
                }
            }

            foreach ($this->validUserRows($data['users'] ?? []) as $row) {
                if (! empty($row['printer_id'])) {
                    PrinterAssignment::query()->create([
                        'branch_id' => $branchId,
                        'scope' => 'user',
                        'user_id' => $row['user_id'],
                        'print_type' => $row['print_type'],
                        'printer_id' => $row['printer_id'],
                    ]);
                }
            }

            foreach ($this->validRoleRows($data['roles'] ?? []) as $row) {
                if (! empty($row['printer_id'])) {
                    PrinterAssignment::query()->create([
                        'branch_id' => $branchId,
                        'scope' => 'role',
                        'role_id' => $row['role_id'],
                        'print_type' => $row['print_type'],
                        'printer_id' => $row['printer_id'],
                    ]);
                }
            }
        });

        return $this->show($branchId);
    }

    private function validUserRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn(array $row) => ! empty($row['user_id']) && ! empty($row['print_type']))
            ->values()
            ->all();
    }

    private function validRoleRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn(array $row) => ! empty($row['role_id']) && ! empty($row['print_type']))
            ->values()
            ->all();
    }
}
