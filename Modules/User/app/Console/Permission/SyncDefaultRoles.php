<?php

namespace Modules\User\Console\Permission;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\User\Enums\DefaultRole;
use Modules\User\Facades\Permission;
use Modules\User\Models\Role;
use Symfony\Component\Console\Input\InputOption;

class SyncDefaultRoles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $name = 'permission:sync-default-roles';

    /**
     * The console command description.
     */
    protected $description = 'Sync default roles with database.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        if ($this->option('force')) {
            $this->forceCreateOrUpdate();
        } else {
            $this->createMissingRoles();
        }

        $this->syncPermissions();
    }

    /**
     * Force sync default roles, even if they already exist
     *
     * @return void
     */
    private function forceCreateOrUpdate(): void
    {
        foreach (DefaultRole::cases() as $case) {

            Role::updateOrCreate(
                attributes: [
                    "name" => $case->value
                ],
                values: [
                    "built_in" => true,
                    'display_name' => $case->allTrans()
                ]
            );
        }
    }

    /**
     * Create only the missing default rules
     *
     * @return void
     */
    private function createMissingRoles(): void
    {
        $roles = array_diff(
            DefaultRole::values(),
            DB::table((new Role)->getTable())->pluck('name')->toArray()
        );

        foreach ($roles as $role) {
            Role::create([
                "name" => $role,
                "built_in" => true,
                'display_name' => DefaultRole::tryFrom($role)->allTrans()
            ]);
        }
    }

    /**
     * Roles sync permissions
     *
     * @return void
     */
    public function syncPermissions(): void
    {
        $allPermission = Permission::getPermissionNames();

        $roles = Role::whereIn('name', DefaultRole::values())
            ->whereNot("name", DefaultRole::SuperAdmin->value)
            ->get();


        /** @var Role $role */
        foreach ($roles as $role) {
            $rolePermission = DefaultRole::from($role->name)->getPermissions();
            $role->syncPermissions(DefaultRole::expandPermissions($rolePermission, $allPermission));
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    public function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Force sync default roles, even if they already exist.'],
        ];
    }
}
