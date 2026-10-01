<?php

namespace Modules\Expense\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Expense\Services\Expense\ExpenseService;
use Modules\Expense\Services\Expense\ExpenseServiceInterface;
use Modules\Expense\Services\ExpenseCategory\ExpenseCategoryService;
use Modules\Expense\Services\ExpenseCategory\ExpenseCategoryServiceInterface;

class DeferredExpenseServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service bindings.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: ExpenseServiceInterface::class,
            concrete: fn($app) => $app->make(ExpenseService::class)
        );

        $this->app->singleton(
            abstract: ExpenseCategoryServiceInterface::class,
            concrete: fn($app) => $app->make(ExpenseCategoryService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            ExpenseServiceInterface::class,
            ExpenseCategoryServiceInterface::class,
        ];
    }
}
