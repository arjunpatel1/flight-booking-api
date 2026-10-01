<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\User\Models\EmployeeShift;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class EmployeeAttendanceClockInTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    /**
     * Regression: ClockInEmployeeAttendanceRequest::attributes() returned a
     * string (the raw lang key) where Laravel's validator factory requires an
     * array, so building the validator raised a TypeError and every clock-in
     * answered 500 Server Error before the handler ever ran.
     */
    public function test_clock_in_succeeds_for_a_permitted_user(): void
    {
        $this->actingAsUserWithPermissions(['admin.employee_attendances.clock_in']);

        $branch = $this->makeBranch();
        $employee = User::factory()->create(['is_active' => true, 'can_login' => false]);
        $employee->forceFill(['branch_id' => $branch->id])->save();

        $this->postJson('/api/v1/employee-attendances/clock-in', [
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
        ])->assertCreated();
    }

    public function test_clock_in_still_validates_its_input(): void
    {
        $this->actingAsUserWithPermissions(['admin.employee_attendances.clock_in']);

        // Missing user_id must surface a 422, not a 500 from the validator.
        $this->postJson('/api/v1/employee-attendances/clock-in', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_clock_out_builds_its_validator(): void
    {
        $this->actingAsUserWithPermissions([
            'admin.employee_attendances.clock_in',
            'admin.employee_attendances.clock_out',
        ]);

        $branch = $this->makeBranch();
        $employee = User::factory()->create(['is_active' => true, 'can_login' => false]);
        $employee->forceFill(['branch_id' => $branch->id])->save();

        $created = $this->postJson('/api/v1/employee-attendances/clock-in', [
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
        ])->assertCreated()->json('body.id');

        $this->postJson("/api/v1/employee-attendances/{$created}/clock-out", [
            'break_minutes' => 15,
        ])->assertOk();
    }

    public function test_admin_clock_in_rejects_login_accounts_and_attaches_an_employee_shift(): void
    {
        $this->actingAsUserWithPermissions(['admin.employee_attendances.clock_in']);

        $branch = $this->makeBranch();
        $employee = User::factory()->create([
            'branch_id' => $branch->id,
            'can_login' => false,
            'is_active' => true,
        ]);
        $loginAccount = User::factory()->create([
            'branch_id' => $branch->id,
            'can_login' => true,
            'is_active' => true,
        ]);
        $shift = EmployeeShift::query()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'name' => 'Morning',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/employee-attendances/clock-in', [
            'user_id' => $loginAccount->id,
            'branch_id' => $branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->postJson('/api/v1/employee-attendances/clock-in', [
            'user_id' => $employee->id,
            'branch_id' => $branch->id,
        ])->assertCreated()->assertJsonPath('body.shift.id', $shift->id);
    }
}
