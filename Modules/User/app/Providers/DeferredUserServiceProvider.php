<?php

namespace Modules\User\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\User\Services\Account\AccountService;
use Modules\User\Services\Account\AccountServiceInterface;
use Modules\User\Services\Auth\AuthService;
use Modules\User\Services\Auth\AuthServiceInterface;
use Modules\User\Services\Customer\CustomerService;
use Modules\User\Services\Customer\CustomerServiceInterface;
use Modules\User\Services\EmployeeAttendance\EmployeeAttendanceService;
use Modules\User\Services\EmployeeAttendance\EmployeeAttendanceServiceInterface;
use Modules\User\Services\EmployeeCompensation\EmployeeCompensationService;
use Modules\User\Services\EmployeeCompensation\EmployeeCompensationServiceInterface;
use Modules\User\Services\EmployeePayroll\EmployeePayrollService;
use Modules\User\Services\EmployeePayroll\EmployeePayrollServiceInterface;
use Modules\User\Services\EmployeeShift\EmployeeShiftService;
use Modules\User\Services\EmployeeShift\EmployeeShiftServiceInterface;
use Modules\User\Services\Role\RoleService;
use Modules\User\Services\Role\RoleServiceInterface;
use Modules\User\Services\User\UserService;
use Modules\User\Services\User\UserServiceInterface;

class DeferredUserServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: AuthServiceInterface::class,
            concrete: fn($app) => $app->make(AuthService::class)
        );

        $this->app->singleton(
            abstract: RoleServiceInterface::class,
            concrete: fn($app) => $app->make(RoleService::class)
        );

        $this->app->singleton(
            abstract: UserServiceInterface::class,
            concrete: fn($app) => $app->make(UserService::class)
        );

        $this->app->singleton(
            abstract: AccountServiceInterface::class,
            concrete: fn($app) => $app->make(AccountService::class)
        );

        $this->app->singleton(
            abstract: CustomerServiceInterface::class,
            concrete: fn($app) => $app->make(CustomerService::class)
        );

        $this->app->singleton(
            abstract: EmployeeShiftServiceInterface::class,
            concrete: fn($app) => $app->make(EmployeeShiftService::class)
        );

        $this->app->singleton(
            abstract: EmployeeAttendanceServiceInterface::class,
            concrete: fn($app) => $app->make(EmployeeAttendanceService::class)
        );

        $this->app->singleton(
            abstract: EmployeeCompensationServiceInterface::class,
            concrete: fn($app) => $app->make(EmployeeCompensationService::class)
        );

        $this->app->singleton(
            abstract: EmployeePayrollServiceInterface::class,
            concrete: fn($app) => $app->make(EmployeePayrollService::class)
        );

    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AuthServiceInterface::class,
            RoleServiceInterface::class,
            UserServiceInterface::class,
            AccountServiceInterface::class,
            CustomerServiceInterface::class,
            EmployeeShiftServiceInterface::class,
            EmployeeAttendanceServiceInterface::class,
            EmployeeCompensationServiceInterface::class,
            EmployeePayrollServiceInterface::class
        ];
    }
}
