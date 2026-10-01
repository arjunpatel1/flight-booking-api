<?php

namespace Modules\User\Services\EmployeeShift;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Branch\Models\Branch;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\EmployeeShift;
use Modules\User\Models\User;

class EmployeeShiftService implements EmployeeShiftServiceInterface
{
    public function label(): string
    {
        return __('user::employee_shifts.employee_shift');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return EmployeeShift::query()
            ->withoutGlobalActive()
            ->with(['branch:id,name', 'user:id,name'])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): EmployeeShift
    {
        return EmployeeShift::query()
            ->withoutGlobalActive()
            ->with(['branch:id,name', 'user:id,name'])
            ->findOrFail($id);
    }

    public function store(array $data): EmployeeShift
    {
        return EmployeeShift::query()->create($data);
    }

    public function update(int $id, array $data): EmployeeShift
    {
        $shift = $this->show($id);
        $shift->update($data);

        return $shift->refresh();
    }

    public function destroy(int|array|string $ids): bool
    {
        return EmployeeShift::query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                'key' => 'user_id',
                'label' => __('user::employee_shifts.filters.employee'),
                'type' => 'select',
                'options' => User::query()
                    ->where('can_login', false)
                    ->select('id', 'name')
                    ->get()
                    ->map(fn(User $user) => ['id' => $user->id, 'name' => $user->name]),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function getFormMeta(?int $branchId = null): array
    {
        return [
            'branches' => Branch::list(),
            'employees' => User::query()
                ->where('can_login', false)
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->select('id', 'name')
                ->get()
                ->map(fn(User $user) => ['id' => $user->id, 'name' => $user->name]),
            'shifts' => EmployeeShift::query()
                ->withoutGlobalActive()
                ->where('is_active', true)
                ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
                ->select('id', 'user_id', 'branch_id', 'name', 'starts_at', 'ends_at')
                ->orderBy('starts_at')
                ->get()
                ->map(fn(EmployeeShift $shift) => [
                    'id' => $shift->id,
                    'user_id' => $shift->user_id,
                    'branch_id' => $shift->branch_id,
                    'name' => $shift->name,
                    'starts_at' => $shift->starts_at,
                    'ends_at' => $shift->ends_at,
                    'label' => "{$shift->name} ({$shift->starts_at}–{$shift->ends_at})",
                ]),
        ];
    }
}
