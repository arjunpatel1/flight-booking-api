<?php

namespace Modules\Admin\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void {}
}
