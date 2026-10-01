<?php

namespace Modules\Import\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Import\Services\Import\ImportService;
use Modules\Import\Services\Import\ImportServiceInterface;

class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImportServiceInterface::class, ImportService::class);
    }
}
