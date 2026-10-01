<?php

namespace Modules\User\Console\Permission;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\User\Facades\Permission;
use Modules\User\Models\Permission as ModelPermission;

class SyncPermissions extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'permission:sync-permissions';

    /**
     * The console command description.
     */
    protected $description = 'Sync register permissions with database.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        Artisan::call('permission:cache-reset');
        
        $permissionNames = array_diff(
            Permission::getPermissionNames(),
            DB::table((new ModelPermission)->getTable())->pluck('name')->toArray()
        );

        foreach ($permissionNames as $permission) {
            ModelPermission::create(["name" => $permission]);
        }
    }
}
