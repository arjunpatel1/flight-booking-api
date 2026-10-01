<?php

namespace Modules\Core\Traits;

use Nwidart\Modules\Laravel\Module as LaravelModule;

trait WithModuleRegistration
{
    /**
     * Register config.
     *
     * @param LaravelModule $module
     * @return void
     */
    protected function registerConfig(LaravelModule $module): void
    {
        $file = $module->getPath() . DIRECTORY_SEPARATOR . 'config/config.php';
        if (file_exists($file)) {
            $this->mergeConfigFrom($file, $module->getLowerName());
        }
    }

    /**
     * Register translations.
     *
     * @param LaravelModule $module
     * @return void
     */
    protected function registerTranslations(LaravelModule $module): void
    {
        $path = $module->getPath() . DIRECTORY_SEPARATOR . 'lang';
        $this->loadTranslationsFrom($path, $module->getLowerName());
        $this->loadJsonTranslationsFrom($path);
    }
}
