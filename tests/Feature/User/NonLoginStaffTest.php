<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Modules\User\Services\Auth\AuthServiceInterface;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Employee management for non-login staff.
 *
 * The employee domain (attendance, shifts, compensation, payroll) already keys
 * on users.id, so staff who never sign in are recorded as users flagged
 * can_login = false rather than as a parallel entity.
 */
class NonLoginStaffTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    private function staffRoleId(): int
    {
        $role = Role::findOrCreate(DefaultRole::Waiter->value, 'api');
        $role->forceFill(['built_in' => true])->saveQuietly();

        return $role->id;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Kitchen Porter',
            'gender' => 'male',
            'is_active' => true,
            'roles' => [$this->staffRoleId()],
            ...$overrides,
        ];
    }

    public function test_staff_without_credentials_can_be_created(): void
    {
        $actor = $this->actingAsUserWithPermissions(['admin.users.create']);
        $branch = $this->makeBranch();
        $actor->forceFill(['branch_id' => $branch->id])->save();

        $this->postJson('/api/v1/users', $this->payload([
            'can_login' => false,
            'branch_id' => $branch->id,
        ]))->assertCreated();

        $staff = User::withoutGlobalActive()->where('name', 'Kitchen Porter')->firstOrFail();
        $this->assertFalse((bool) $staff->can_login);
        $this->assertNull($staff->email);
        $this->assertNull($staff->username);
    }

    public function test_a_login_account_still_requires_credentials(): void
    {
        $actor = $this->actingAsUserWithPermissions(['admin.users.create']);
        $branch = $this->makeBranch();
        $actor->forceFill(['branch_id' => $branch->id])->save();

        $this->postJson('/api/v1/users', $this->payload([
            'can_login' => true,
            'branch_id' => $branch->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'email', 'password']);
    }

    public function test_credentials_are_still_required_when_the_flag_is_absent(): void
    {
        $actor = $this->actingAsUserWithPermissions(['admin.users.create']);
        $branch = $this->makeBranch();
        $actor->forceFill(['branch_id' => $branch->id])->save();

        // Existing clients do not send can_login; they must keep the old rules.
        $this->postJson('/api/v1/users', $this->payload(['branch_id' => $branch->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'email', 'password']);
    }

    /**
     * Security: even if a password somehow exists on the record, a staff-only
     * account must never authenticate.
     */
    public function test_non_login_staff_cannot_sign_in(): void
    {
        $staff = User::factory()->create(['is_active' => true]);
        $staff->forceFill([
            'can_login' => false,
            'email' => 'porter@example.test',
            'password' => Hash::make('Sup3rSecret!'),
        ])->save();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(AuthServiceInterface::class)->login([
            'identifier' => 'porter@example.test',
            'password' => 'Sup3rSecret!',
        ]);
    }

    public function test_a_normal_account_can_still_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill([
            'can_login' => true,
            'email' => 'manager@example.test',
            'password' => Hash::make('Sup3rSecret!'),
        ])->save();

        $result = app(AuthServiceInterface::class)->login([
            'identifier' => 'manager@example.test',
            'password' => 'Sup3rSecret!',
        ]);

        $this->assertNotEmpty($result['token'] ?? null);
    }

    public function test_existing_accounts_default_to_being_able_to_log_in(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->assertTrue((bool) $user->fresh()->can_login);
    }

    public function test_non_login_staff_can_still_be_clocked_in(): void
    {
        $actor = $this->actingAsUserWithPermissions(['admin.employee_attendances.clock_in']);
        $branch = $this->makeBranch();
        $actor->forceFill(['branch_id' => $branch->id])->save();
        $actor->refresh();

        $staff = User::factory()->create(['is_active' => true]);
        $staff->forceFill(['branch_id' => $branch->id, 'can_login' => false])->save();

        $this->postJson('/api/v1/employee-attendances/clock-in', [
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
        ])->assertCreated();
    }
}
