<?php

namespace Modules\Core\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Traits\WithModuleRegistration;
use Nwidart\Modules\Facades\Module;
use Nwidart\Modules\Laravel\Module as LaravelModule;

class ModuleServiceProvider extends ServiceProvider
{
    use WithModuleRegistration;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Performance Optimization
        |--------------------------------------------------------------------------
        | 1. Reflection Class: exhaust a memory a little bit, and it is replaced
        | with manual registration.
        |
        | 2. module_path: This method is taking the most processing time, which is
        | interesting, and it is replaced with `$module->getPath()`
        |
        */
        /** @var LaravelModule $module */
        foreach (Module::getOrdered() as $module) {
            $this->registerConfig($module);
            $this->registerTranslations($module);
        }
       
        $this->configClearModulesCache();
    }

    /**
     * Clear modules cache on optimize:clear
     *
     * @return void
     */
    private function configClearModulesCache(): void
    {
        if (!app()->runningInConsole()) {
            return;
        }

        Event::listen(CommandStarting::class, function ($event) {
            if (in_array($event->command, ['optimize:clear', 'config:clear'])) {
                File::delete(File::glob('bootstrap/cache/modules.php'));
                File::delete(File::glob('bootstrap/cache/*_module.php'));
            }
        });
    }
}
