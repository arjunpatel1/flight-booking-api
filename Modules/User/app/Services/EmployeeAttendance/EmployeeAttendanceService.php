<?php

namespace Modules\User\Services\EmployeeAttendance;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\EmployeeAttendance;
use Modules\User\Models\EmployeeShift;
use Modules\User\Models\User;

class EmployeeAttendanceService implements EmployeeAttendanceServiceInterface
{
    public function label(): string
    {
        return __('user::employee_attendances.employee_attendance');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $page = EmployeeAttendance::query()
            ->with(['branch:id,name', 'user:id,name', 'shift:id,name,starts_at,ends_at'])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();

        // Older/manual attendance rows may predate employee_shift_id. Resolve
        // the employee's current assigned shift for display so Attendance uses
        // the same assignment shown by Employee Management. New clock-ins keep
        // persisting the explicit shift id above.
        $missing = $page->getCollection()->filter(fn (EmployeeAttendance $row) => ! $row->shift);
        if ($missing->isNotEmpty()) {
            $shiftMap = EmployeeShift::query()
                ->withoutGlobalActive()
                ->where('is_active', true)
                ->whereIn('user_id', $missing->pluck('user_id')->unique())
                ->whereIn('branch_id', $missing->pluck('branch_id')->unique())
                ->orderByDesc('updated_at')
                ->get(['id', 'user_id', 'branch_id', 'name', 'starts_at', 'ends_at'])
                ->unique(fn (EmployeeShift $shift) => "{$shift->user_id}:{$shift->branch_id}")
                ->keyBy(fn (EmployeeShift $shift) => "{$shift->user_id}:{$shift->branch_id}");

            $missing->each(function (EmployeeAttendance $row) use ($shiftMap): void {
                $fallback = $shiftMap->get("{$row->user_id}:{$row->branch_id}");
                if ($fallback) {
                    $row->setRelation('shift', $fallback);
                }
            });
        }

        return $page;
    }

    public function clockIn(array $data): EmployeeAttendance
    {
        return DB::transaction(function () use ($data) {
                // A database row lock works even when Redis is restarting and
                // prevents two terminals clocking in the same employee.
                $employee = User::query()
                    ->withoutGlobalActive()
                    ->lockForUpdate()
                    ->findOrFail($data['user_id']);

                $open = EmployeeAttendance::query()
                    ->where('user_id', $data['user_id'])
                    ->where('status', 'open')
                    ->exists();

                if ($open) {
                    throw ValidationException::withMessages([
                        'user_id' => __('user::employee_attendances.employee_already_clocked_in'),
                    ]);
                }

                if (! empty($data['employee_shift_id'])) {
                    $shift = EmployeeShift::query()
                        ->withoutGlobalActive()
                        ->where('user_id', $employee->id)
                        ->findOrFail($data['employee_shift_id']);
                    $data['branch_id'] = $shift->branch_id;
                } else {
                    $shift = EmployeeShift::query()
                        ->withoutGlobalActive()
                        ->where('user_id', $employee->id)
                        ->where('is_active', true)
                        ->when($data['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
                        ->latest('updated_at')
                        ->first();

                    if ($shift) {
                        $data['employee_shift_id'] = $shift->id;
                        $data['branch_id'] = $shift->branch_id;
                    }
                }

                $branchId = (int) ($data['branch_id'] ?? 0);
                if ($employee->branch_id && (int) $employee->branch_id !== $branchId) {
                    throw ValidationException::withMessages([
                        'user_id' => __('user::employee_attendances.employee_branch_mismatch'),
                    ]);
                }

                return EmployeeAttendance::query()->create([
                    ...$data,
                    'clock_in_at' => $data['clock_in_at'] ?? now(),
                    'status' => 'open',
                ]);
            });
    }

    public function clockOut(int $id, array $data): EmployeeAttendance
    {
        return DB::transaction(function () use ($id, $data) {
            $attendance = EmployeeAttendance::query()->findOrFail($id);

            if ($attendance->status === 'closed') {
                throw ValidationException::withMessages([
                    'id' => __('user::employee_attendances.attendance_already_closed'),
                ]);
            }

            $attendance->update([
                'clock_out_at' => $data['clock_out_at'] ?? now(),
                'break_minutes' => $data['break_minutes'] ?? $attendance->break_minutes,
                'notes' => $data['notes'] ?? $attendance->notes,
                'status' => 'closed',
            ]);

            return $attendance->refresh();
        });
    }

    public function update(int $id, array $data): EmployeeAttendance
    {
        $attendance = EmployeeAttendance::query()->findOrFail($id);
        $attendance->update($data);

        return $attendance->refresh();
    }

    public function summary(array $filters = []): array
    {
        $query = EmployeeAttendance::query()->filters($filters);

        return [
            'total' => (clone $query)->count(),
            'open' => (clone $query)->where('status', 'open')->count(),
            'closed' => (clone $query)->where('status', 'closed')->count(),
            'worked_minutes' => (clone $query)->get()->sum('worked_minutes'),
        ];
    }

    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                'key' => 'user_id',
                'label' => __('user::employee_attendances.filters.employee'),
                'type' => 'select',
                'options' => User::query()
                    ->where('can_login', false)
                    ->select('id', 'name')
                    ->get()
                    ->map(fn(User $user) => ['id' => $user->id, 'name' => $user->name]),
            ],
            [
                'key' => 'status',
                'label' => __('user::employee_attendances.filters.status'),
                'type' => 'select',
                'options' => [
                    ['id' => 'open', 'name' => __('user::employee_attendances.statuses.open')],
                    ['id' => 'closed', 'name' => __('user::employee_attendances.statuses.closed')],
                ],
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }
}
