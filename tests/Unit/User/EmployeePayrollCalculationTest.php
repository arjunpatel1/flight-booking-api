<?php

namespace Tests\Unit\User;

use Illuminate\Support\Carbon;
use Modules\User\Models\EmployeeCompensation;
use Modules\User\Services\EmployeePayroll\EmployeePayrollService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeePayrollCalculationTest extends TestCase
{
    #[Test]
    public function monthly_pay_uses_the_configured_salary_for_a_full_month(): void
    {
        $compensation = new EmployeeCompensation([
            'pay_type' => 'monthly',
            'base_rate' => 30000,
            'standard_daily_minutes' => 480,
        ]);

        $service = new class extends EmployeePayrollService {
            public function amount(EmployeeCompensation $compensation, int $minutes, Carbon $from, Carbon $to): float
            {
                return $this->baseAmount($compensation, $minutes, $from, $to);
            }
        };

        $this->assertSame(30000.0, $service->amount(
            $compensation,
            480,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31')
        ));
    }

    #[Test]
    public function hourly_pay_uses_closed_regular_work_minutes(): void
    {
        $compensation = new EmployeeCompensation([
            'pay_type' => 'hourly',
            'base_rate' => 120,
            'standard_daily_minutes' => 480,
        ]);

        $service = new class extends EmployeePayrollService {
            public function amount(EmployeeCompensation $compensation, int $minutes): float
            {
                return $this->baseAmount($compensation, $minutes);
            }
        };

        $this->assertSame(300.0, $service->amount($compensation, 150));
    }
}
