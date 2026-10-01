<?php

namespace Modules\User;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;

class Permission
{
    /**
     * Register all system permissions
     *
     * @var Collection
     */
    protected Collection $permissions;

    /**
     * Create a new instance from Permission
     *
     * @return void
     */
    public function __construct()
    {
        $this->loadPermissions();
    }

    /**
     * Load Permissions from a module config file
     *
     * @return void
     */
    private function loadPermissions(): void
    {
        $this->permissions = collect();

        foreach (Module::getOrdered() as $module) {
            $permissions = config($module->getLowerName() . ".permissions", []);
            foreach ($permissions as $group => $permission) {
                $this->register(
                    group: $group,
                    actions: $permission,
                    moduleName: $module->getLowerName(),
                );
            }
        }
    }

    /**
     * Register permission
     *
     * @param string $group
     * @param array $actions
     * @param string $moduleName
     */
    public function register(string $group, array $actions, string $moduleName): void
    {
        if ($this->exists($group)) {
            $this->permissions = $this->permissions->map(function ($permission) use ($group, $actions) {
                if ($permission["group"] == $group) {
                    $permission['actions'] = array_unique([...$permission['actions'], ...$actions], SORT_REGULAR);
                }
                return $permission;
            });
        } else {
            $this->permissions->push([
                "module" => $moduleName,
                "group" => $group,
                "actions" => $actions
            ]);
        }
    }

    /**
     * Determine if permission exists
     *
     * @param string $group
     * @return bool
     */
    public function exists(string $group): bool
    {
        return !is_null($this->permissions->where('group', $group)->first());
    }

    /**
     * Get all permissions
     *
     * @return Collection
     */
    public function all(): Collection
    {
        return $this->permissions;
    }

    /**
     * Get specific permission
     *
     * @param string $group
     * @return array|null
     */
    public function get(string $group): ?array
    {
        return $this->permissions->where('group', $group)->first();
    }

    /**
     * Get permission names
     *
     * @param Collection|null $permissions
     * @return array
     */
    public function getPermissionNames(?Collection $permissions = null): array
    {
        $permissions = ($permissions ?: $this->permissions);

        return $permissions->map(
            fn($permission) => array_map(fn($action) => "admin.{$permission['group']}.$action->value", $permission['actions'])
        )
            ->flatten()
            ->toArray();
    }

    /**
     * Format permissions
     *
     * @param Collection|null $permissions
     * @return array
     */
    public function formatPermissions(?Collection $permissions = null): array
    {
        return ($permissions ?: $this->permissions)->map(
            function ($permission) {
                $singularGroup = $permission['group'] == "media" ? "media" : Str::singular($permission['group']);
                $singularGroup = $singularGroup == "po" ? "pos" : $singularGroup;
                $pluralGroup = $permission['group'] == "menus" ? "menus" : Str::plural($permission['group']);

                $titleKey = "{$permission['module']}::$pluralGroup.$singularGroup";
                $title = __($titleKey);
                if ($title === $titleKey) {
                    $title = Str::headline($permission['group']);
                }
                $actions = [];

                foreach ($permission['actions'] as $action) {
                    $actionKey = "admin::permissions.$action->value";
                    $actionName = __($actionKey, ["resource" => $title]);
                    if ($actionName === $actionKey) {
                        $actionName = Str::headline($action->value) . " {$title}";
                    }

                    $actions[] = [
                        "id" => "admin.{$permission['group']}.$action->value",
                        "name" => $actionName
                    ];
                }

                return [
                    "title" => $title,
                    "actions" => $actions
                ];
            }
        )->values()->toArray();
    }
}
