<?php

namespace Modules\User\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Collection all()
 * @method static array get(string $group)
 * @method static bool exists(string $group)
 * @method static void register(string $group, array $actions)
 * @method static array getPermissionNames(Collection $permissions = null)
 * @method static array formatPermissions(Collection $permissions = null)
 *
 * @see \Modules\User\Permission
 */
class Permission extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \Modules\User\Permission::class;
    }
}
