<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\EmployeeAttendance;
use Modules\User\Models\EmployeeShift;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class EmployeeAttendanceSelfServiceApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        Artisan::call('permission:sync-default-roles');
    }

    public function test_waiter_can_clock_self_in_and_out_without_spoofing_user_id(): void
    {
        $branch = $this->makeBranch();
        $waiter = $this->waiter($branch->id);
        $otherWaiter = $this->waiter($branch->id);

        Sanctum::actingAs($waiter, ['*'], 'api');

        $this->postJson('/api/v1/employee-attendances/me/clock-in', [
            'branch_id' => $branch->id,
            'user_id' => $otherWaiter->id,
            'meta' => ['device_id' => 'test-terminal', 'unsafe' => 'drop-me'],
        ])
            ->assertCreated()
            ->assertJsonPath('body.user.id', $waiter->id)
            ->assertJsonPath('body.branch.id', $branch->id);

        $this->assertDatabaseHas('employee_attendances', [
            'user_id' => $waiter->id,
            'branch_id' => $branch->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseMissing('employee_attendances', [
            'user_id' => $otherWaiter->id,
            'status' => 'open',
        ]);

        $attendance = EmployeeAttendance::query()->where('user_id', $waiter->id)->firstOrFail();
        $this->assertSame(['device_id' => 'test-terminal'], $attendance->meta);

        $this->getJson('/api/v1/employee-attendances/me')
            ->assertOk()
            ->assertJsonPath('body.is_clocked_in', true)
            ->assertJsonPath('body.attendance.id', $attendance->id);

        $this->postJson('/api/v1/employee-attendances/me/clock-out')
            ->assertOk()
            ->assertJsonPath('body.status.id', 'closed');

        $this->getJson('/api/v1/employee-attendances/me')
            ->assertOk()
            ->assertJsonPath('body.is_clocked_in', false)
            ->assertJsonPath('body.attendance', null);
    }

    public function test_self_clock_in_rejects_shift_from_another_branch(): void
    {
        $branch = $this->makeBranch();
        $otherBranch = $this->makeBranch();
        $waiter = $this->waiter($branch->id);
        $otherBranchWaiter = $this->waiter($otherBranch->id);
        $shift = EmployeeShift::query()->create([
            'branch_id' => $otherBranch->id,
            'user_id' => $otherBranchWaiter->id,
            'name' => 'Other Branch Shift',
            'starts_at' => '09:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);

        Sanctum::actingAs($waiter, ['*'], 'api');

        $this->postJson('/api/v1/employee-attendances/me/clock-in', [
            'employee_shift_id' => $shift->id,
        ])->assertForbidden();
    }

    private function waiter(int $branchId): User
    {
        $waiter = User::factory()->create([
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
        $waiter->syncRoles([DefaultRole::Waiter->value]);

        return $waiter;
    }
}
