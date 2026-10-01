<?php

namespace Modules\User\Services\EmployeePayroll;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Modules\User\Models\EmployeeAttendance;
use Modules\User\Models\EmployeeCompensation;
use Modules\User\Models\EmployeePayrollRun;
use Modules\User\Models\EmployeePayslip;

class EmployeePayrollService implements EmployeePayrollServiceInterface
{
    public function preview(array $filters = []): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now()->endOfMonth())->endOfDay();

        $attendances = EmployeeAttendance::query()
            ->with(['user:id,name', 'branch:id,name'])
            ->where('status', 'closed')
            ->whereBetween('clock_in_at', [$from, $to])
            ->when($filters['branch_id'] ?? null, fn($query, $branchId) => $query->where('branch_id', $branchId))
            ->get()
            ->groupBy('user_id');

        // Payroll eligibility comes from effective compensation, not from the
        // presence of attendance alone. This keeps salaried employees visible
        // even when they have no closed row in the selected period.
        $compensations = EmployeeCompensation::query()
            ->withoutGlobalActive()
            ->with(['user:id,name', 'branch:id,name'])
            ->where('is_active', true)
            ->whereHas('user', fn($query) => $query
                ->where('users.is_active', true)
                ->where('users.can_login', false)
                ->whereNull('users.deleted_at'))
            ->whereDate('effective_from', '<=', $to)
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($filters['branch_id'] ?? null, fn($query, $branchId) => $query->where('branch_id', $branchId))
            ->orderByDesc('effective_from')
            ->get()
            ->unique(fn(EmployeeCompensation $row) => $row->branch_id . ':' . $row->user_id)
            ->values();

        return $compensations->map(function (EmployeeCompensation $compensation) use ($attendances, $from, $to) {
            $rows = $attendances->get($compensation->user_id, collect())
                ->where('branch_id', $compensation->branch_id);

            $workedMinutes = $rows->sum('worked_minutes');
            $regularMinutes = $rows->sum(fn($row) => min($row->worked_minutes, $compensation->standard_daily_minutes ?? 480));
            $overtimeMinutes = max(0, $workedMinutes - $regularMinutes);
            $baseAmount = $this->baseAmount($compensation, $regularMinutes, $from, $to);
            $overtimeAmount = round(($overtimeMinutes / 60) * ($compensation->overtime_rate ?? 0), 2);

            return [
                'user' => ['id' => $compensation->user_id, 'name' => $compensation->user?->name],
                'branch' => ['id' => $compensation->branch_id, 'name' => $compensation->branch?->name],
                'pay_type' => $compensation->pay_type,
                'worked_minutes' => $workedMinutes,
                'regular_minutes' => $regularMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'base_amount' => $baseAmount,
                'overtime_amount' => $overtimeAmount,
                'gross_amount' => round($baseAmount + $overtimeAmount, 2),
                'deduction_amount' => 0.0,
                'net_amount' => round($baseAmount + $overtimeAmount, 2),
            ];
        })->values()->all();
    }

    public function runs(array $filters = []): array
    {
        return EmployeePayrollRun::query()
            ->with(['branch:id,name', 'approvedBy:id,name', 'paidBy:id,name'])
            ->when($filters['branch_id'] ?? null, fn($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'] ?? null, fn($query, $status) => $query->where('status', $status))
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn(EmployeePayrollRun $run) => $this->runPayload($run))
            ->all();
    }

    public function show(int $id): array
    {
        $run = EmployeePayrollRun::query()
            ->with([
                'branch:id,name',
                'approvedBy:id,name',
                'paidBy:id,name',
                'payslips.user:id,name,email,phone,branch_id',
                'payslips.deductions',
            ])
            ->findOrFail($id);

        return $this->runPayload($run, true);
    }

    public function generate(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $branchId = (int) ($data['branch_id'] ?? 0);
            abort_if($branchId <= 0, 422, 'A branch is required to generate payroll.');
            $rows = collect($this->preview([
                ...$data,
                'branch_id' => $branchId,
            ]));
            abort_if($rows->isEmpty(), 422, 'No closed attendance records found for this payroll period.');

            $deductions = collect($data['deductions'] ?? [])->groupBy('user_id');
            $periodFrom = Carbon::parse($data['from'] ?? now()->startOfMonth())->toDateString();
            $periodTo = Carbon::parse($data['to'] ?? now()->endOfMonth())->toDateString();

            $run = EmployeePayrollRun::query()->create([
                'branch_id' => $branchId,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($rows as $row) {
                $userDeductions = $deductions->get($row['user']['id'], collect());
                $deductionAmount = round($userDeductions->sum(fn($item) => (float) ($item['amount'] ?? 0)), 2);
                $grossAmount = round((float) $row['gross_amount'], 2);
                $payslip = EmployeePayslip::query()->create([
                    'branch_id' => $branchId,
                    'employee_payroll_run_id' => $run->id,
                    'user_id' => $row['user']['id'],
                    'worked_minutes' => $row['worked_minutes'],
                    'regular_minutes' => $row['regular_minutes'],
                    'overtime_minutes' => $row['overtime_minutes'],
                    'base_amount' => $row['base_amount'],
                    'overtime_amount' => $row['overtime_amount'],
                    'gross_amount' => $grossAmount,
                    'deduction_amount' => $deductionAmount,
                    'net_amount' => max(0, round($grossAmount - $deductionAmount, 2)),
                    'meta' => [
                        'pay_type' => $row['pay_type'],
                    ],
                ]);

                foreach ($userDeductions as $deduction) {
                    $payslip->deductions()->create([
                        'type' => $deduction['type'] ?? 'other',
                        'label' => $deduction['label'] ?? 'Deduction',
                        'amount' => (float) ($deduction['amount'] ?? 0),
                        'notes' => $deduction['notes'] ?? null,
                    ]);
                }
            }

            $this->syncRunTotals($run);

            return $this->show($run->id);
        });
    }

    public function approve(int $id): array
    {
        $run = EmployeePayrollRun::query()->where('status', 'draft')->findOrFail($id);
        $run->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $this->show($run->id);
    }

    public function markPaid(int $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data) {
            $run = EmployeePayrollRun::query()->lockForUpdate()->findOrFail($id);
            abort_unless($run->status === 'approved', 422, 'Only an approved payroll run can be marked paid.');

            $run->update([
                'status' => 'paid',
                'paid_by' => auth()->id(),
                'paid_at' => Carbon::parse($data['paid_at'] ?? now()),
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
            ]);

            return $this->show($run->id);
        });
    }

    public function void(int $id, string $reason): array
    {
        return DB::transaction(function () use ($id, $reason) {
            $run = EmployeePayrollRun::query()->lockForUpdate()->findOrFail($id);
            abort_if($run->status === 'paid', 422, 'A paid payroll run cannot be voided.');
            abort_if($run->status === 'void', 422, 'This payroll run is already void.');

            $run->update([
                'status' => 'void',
                'voided_by' => auth()->id(),
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $this->show($run->id);
        });
    }

    public function statutoryExport(int $id): array
    {
        $run = EmployeePayrollRun::query()
            ->with(['payslips.user:id,name,email,phone', 'payslips.deductions'])
            ->findOrFail($id);

        return $run->payslips
            ->map(fn(EmployeePayslip $payslip) => [
                'employee_id' => $payslip->user_id,
                'employee_name' => $payslip->user?->name,
                'period_from' => $run->period_from?->toDateString(),
                'period_to' => $run->period_to?->toDateString(),
                'gross_amount' => $payslip->gross_amount,
                'deductions' => $payslip->deduction_amount,
                'net_amount' => $payslip->net_amount,
                'pf_amount' => $payslip->deductions->where('type', 'pf')->sum('amount'),
                'esi_amount' => $payslip->deductions->where('type', 'esi')->sum('amount'),
                'tds_amount' => $payslip->deductions->where('type', 'tds')->sum('amount'),
            ])
            ->values()
            ->all();
    }

    protected function baseAmount(
        ?EmployeeCompensation $compensation,
        int $regularMinutes,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): float
    {
        if ($compensation === null) {
            return 0;
        }

        if ($compensation->pay_type === 'monthly') {
            $from ??= now()->startOfMonth();
            $to ??= now()->endOfMonth();
            $amount = 0.0;
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                $amount += $compensation->base_rate / $day->daysInMonth;
            }

            return round($amount, 2);
        }

        return round(($regularMinutes / 60) * $compensation->base_rate, 2);
    }

    private function syncRunTotals(EmployeePayrollRun $run): void
    {
        $run->load('payslips');
        $run->update([
            'gross_amount' => round($run->payslips->sum('gross_amount'), 2),
            'deduction_amount' => round($run->payslips->sum('deduction_amount'), 2),
            'net_amount' => round($run->payslips->sum('net_amount'), 2),
        ]);
    }

    private function runPayload(EmployeePayrollRun $run, bool $withPayslips = false): array
    {
        $payload = [
            'id' => $run->id,
            'branch' => ['id' => $run->branch_id, 'name' => $run->branch?->name],
            'period_from' => $run->period_from?->toDateString(),
            'period_to' => $run->period_to?->toDateString(),
            'status' => $run->status,
            'gross_amount' => $run->gross_amount,
            'deduction_amount' => $run->deduction_amount,
            'net_amount' => $run->net_amount,
            'approved_by' => $run->approvedBy?->name,
            'approved_at' => $run->approved_at ? dateTimeFormat($run->approved_at) : null,
            'paid_at' => $run->paid_at ? dateTimeFormat($run->paid_at) : null,
            'paid_by' => $run->paidBy?->name,
            'payment_method' => $run->payment_method,
            'payment_reference' => $run->payment_reference,
            'voided_at' => $run->voided_at ? dateTimeFormat($run->voided_at) : null,
            'void_reason' => $run->void_reason,
        ];

        if ($withPayslips) {
            $payload['payslips'] = $run->payslips->map(fn(EmployeePayslip $payslip) => [
                'id' => $payslip->id,
                'user' => ['id' => $payslip->user_id, 'name' => $payslip->user?->name],
                'worked_minutes' => $payslip->worked_minutes,
                'regular_minutes' => $payslip->regular_minutes,
                'overtime_minutes' => $payslip->overtime_minutes,
                'base_amount' => $payslip->base_amount,
                'overtime_amount' => $payslip->overtime_amount,
                'gross_amount' => $payslip->gross_amount,
                'deduction_amount' => $payslip->deduction_amount,
                'net_amount' => $payslip->net_amount,
                'deductions' => $payslip->deductions->map(fn($deduction) => [
                    'type' => $deduction->type,
                    'label' => $deduction->label,
                    'amount' => $deduction->amount,
                    'notes' => $deduction->notes,
                ])->values(),
            ])->values();
        }

        return $payload;
    }
}
