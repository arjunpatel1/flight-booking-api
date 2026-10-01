<?php

namespace Modules\User\Database\Seeders;

use App\NexDine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()
            ->updateOrCreate(
                ['username' => 'admin'],
                [
                    'name' => 'Myteknoland',
                    'email' => 'admin@myteknoland.com',
                    'password' => 12345678,
                    'gender' => GenderType::Male,
                    'is_active' => true,
                ])
            ->syncRoles([DefaultRole::SuperAdmin->value]);

        if (! NexDine::seedDemoData()) {
            return;
        }

        Branch::query()
            ->select(['id', 'name'])
            ->get()
            ->each(function (Branch $branch) {
                foreach ([DefaultRole::AdminBranch, DefaultRole::Manager, DefaultRole::Cashier, DefaultRole::Kitchen, DefaultRole::Waiter] as $role) {
                    $this->createBranchUser($branch, $role);
                }
            });
    }

    private function createBranchUser(Branch $branch, DefaultRole $role): void
    {
        $username = "{$role->value}_branch_{$branch->id}";

        $user = User::query()->updateOrCreate(
            ['username' => $username],
            [
                'name' => Str::headline($role->value)." Branch {$branch->id}",
                'email' => "{$username}@myteknoland.com",
                'password' => 'password',
                'gender' => GenderType::Male,
                'branch_id' => $branch->id,
                'phone_country_iso_code' => 'JO',
                'phone' => fake()->unique()->numerify('79#######'),
                'is_active' => true,
            ]
        );

        $user->syncRoles([$role->value]);
    }
}
