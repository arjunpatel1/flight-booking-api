<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Models\EmployeeAttendance;
use Modules\User\Models\EmployeeShift;
use Modules\User\Http\Requests\Api\V1\ClockInEmployeeAttendanceRequest;
use Modules\User\Http\Requests\Api\V1\ClockOutEmployeeAttendanceRequest;
use Modules\User\Services\EmployeeAttendance\EmployeeAttendanceServiceInterface;
use Modules\User\Transformers\Api\V1\EmployeeAttendanceResource;

class EmployeeAttendanceController extends Controller
{
    public function __construct(protected EmployeeAttendanceServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get($request->get('filters', []), $request->get('sorts', [])),
            resource: EmployeeAttendanceResource::class,
            filters: $request->get('with_filters') ? $this->service->getStructureFilters() : null
        );
    }

    public function clockIn(ClockInEmployeeAttendanceRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new EmployeeAttendanceResource($this->service->clockIn($request->validated())),
            resource: $this->service->label()
        );
    }

    public function clockOut(ClockOutEmployeeAttendanceRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new EmployeeAttendanceResource($this->service->clockOut($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function update(ClockOutEmployeeAttendanceRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new EmployeeAttendanceResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->summary($request->get('filters', [])));
    }

    public function me(Request $request): JsonResponse
    {
        $attendance = EmployeeAttendance::query()
            ->with(['branch:id,name', 'user:id,name', 'shift:id,name'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest('clock_in_at')
            ->first();

        return ApiResponse::success([
            'is_clocked_in' => ! is_null($attendance),
            'attendance' => $attendance ? new EmployeeAttendanceResource($attendance) : null,
        ]);
    }

    public function meClockIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_shift_id' => ['nullable', 'integer', 'exists:employee_shifts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'meta' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $branchId = $this->resolveSelfAttendanceBranchId($request, $validated);

        return ApiResponse::created(
            body: new EmployeeAttendanceResource($this->service->clockIn([
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'employee_shift_id' => $validated['employee_shift_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'meta' => $this->selfAttendanceMeta($validated['meta'] ?? []),
            ])->load(['branch:id,name', 'user:id,name', 'shift:id,name'])),
            resource: $this->service->label()
        );
    }

    public function meClockOut(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $attendance = EmployeeAttendance::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest('clock_in_at')
            ->firstOrFail();

        return ApiResponse::updated(
            body: new EmployeeAttendanceResource($this->service->clockOut(
                $attendance->id,
                $validated
            )->load(['branch:id,name', 'user:id,name', 'shift:id,name'])),
            resource: $this->service->label()
        );
    }

    private function resolveSelfAttendanceBranchId(Request $request, array $validated): int
    {
        $user = $request->user();
        $branchId = $user->branch_id ?: ($validated['branch_id'] ?? null);

        if (! empty($validated['employee_shift_id'])) {
            $shift = EmployeeShift::query()
                ->withOutGlobalBranchPermission()
                ->withoutGlobalActive()
                ->findOrFail($validated['employee_shift_id']);
            abort_if($user->branch_id && (int) $shift->branch_id !== (int) $user->branch_id, 403, 'Shift is not available for this branch.');
            $branchId = $shift->branch_id;
        }

        abort_if(empty($branchId), 422, __('validation.required', ['attribute' => 'branch_id']));
        abort_if($user->branch_id && (int) $branchId !== (int) $user->branch_id, 403, 'Branch is not available for this user.');

        return (int) $branchId;
    }

    private function selfAttendanceMeta(array $meta): ?array
    {
        $allowed = ['device_id', 'platform', 'app_version'];
        $sanitized = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $meta) && is_scalar($meta[$key])) {
                $sanitized[$key] = mb_substr((string) $meta[$key], 0, 120);
            }
        }

        return empty($sanitized) ? null : $sanitized;
    }
}
