<?php

namespace Modules\User\Services\EmployeeCompensation;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Branch\Models\Branch;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Models\EmployeeCompensation;
use Modules\User\Models\User;

class EmployeeCompensationService implements EmployeeCompensationServiceInterface
{
    public function label(): string
    {
        return __('user::employee_compensations.employee_compensation');
    }

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return EmployeeCompensation::query()
            ->withoutGlobalActive()
            ->with(['branch:id,name', 'user:id,name'])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int $id): EmployeeCompensation
    {
        return EmployeeCompensation::query()
            ->withoutGlobalActive()
            ->with(['branch:id,name', 'user:id,name'])
            ->findOrFail($id);
    }

    public function store(array $data): EmployeeCompensation
    {
        return EmployeeCompensation::query()->create($data);
    }

    public function update(int $id, array $data): EmployeeCompensation
    {
        $compensation = $this->show($id);
        $compensation->update($data);

        return $compensation->refresh();
    }

    public function destroy(int|array|string $ids): bool
    {
        return EmployeeCompensation::query()
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
                'label' => __('user::employee_compensations.filters.employee'),
                'type' => 'select',
                'options' => User::query()
                    ->where('can_login', false)
                    ->select('id', 'name')
                    ->get()
                    ->map(fn(User $user) => ['id' => $user->id, 'name' => $user->name]),
            ],
            [
                'key' => 'pay_type',
                'label' => __('user::employee_compensations.filters.pay_type'),
                'type' => 'select',
                'options' => collect(['monthly', 'hourly'])
                    ->map(fn(string $type) => ['id' => $type, 'name' => __("user::employee_compensations.pay_types.{$type}")]),
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
            'pay_types' => collect(['monthly', 'hourly'])
                ->map(fn(string $type) => ['id' => $type, 'name' => __("user::employee_compensations.pay_types.{$type}")]),
        ];
    }
}
