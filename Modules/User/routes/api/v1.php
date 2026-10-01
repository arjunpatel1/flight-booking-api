<?php

use Modules\User\Http\Controllers\Api\V1\AccountController;
use Modules\User\Http\Controllers\Api\V1\AuthController;
use Modules\User\Http\Controllers\Api\V1\CustomerController;
use Modules\User\Http\Controllers\Api\V1\CustomerAuthController;
use Modules\User\Http\Controllers\Api\V1\CustomerEngagementController;
use Modules\User\Http\Controllers\Api\V1\CustomerExperienceController;
use Modules\User\Http\Controllers\Api\V1\CustomerWalletAdminController;
use Modules\User\Http\Controllers\Api\V1\PasskeyController;
use Modules\User\Http\Controllers\Api\V1\SsoController;
use Modules\User\Http\Controllers\Api\V1\TenantHandoffController;
use Modules\User\Http\Controllers\Api\V1\EmployeeAttendanceController;
use Modules\User\Http\Controllers\Api\V1\EmployeeCompensationController;
use Modules\User\Http\Controllers\Api\V1\EmployeePayrollController;
use Modules\User\Http\Controllers\Api\V1\EmployeeShiftController;
use Modules\User\Http\Controllers\Api\V1\RoleController;
use Modules\User\Http\Controllers\Api\V1\UserController;

Route::controller(AuthController::class)
    ->prefix('auth')
    ->group(function () {

        Route::post('login', "login")
            ->withoutMiddleware(middleware: 'auth')
            ->middleware('throttle:login');
        Route::post('mfa/verify', 'verifyMfa')
            ->withoutMiddleware(middleware: 'auth')
            ->middleware('throttle:login');
        Route::post('forgot-password', 'forgotPassword')
            ->withoutMiddleware(middleware: 'auth')
            ->middleware('throttle:login');
        Route::post('reset-password', 'resetPassword')
            ->withoutMiddleware(middleware: 'auth')
            ->middleware('throttle:login');

        Route::post('logout', "logout");
        Route::post('check', action: "check");
        Route::post('refresh', 'refreshToken');
        Route::get('sessions', 'sessions');
        Route::delete('sessions/{tokenId}', 'revokeSession');
        Route::delete('sessions', 'revokeOtherSessions');
        Route::get('api-tokens', 'apiTokens');
        Route::post('api-tokens', 'createApiToken');
        Route::delete('api-tokens/{tokenId}', 'revokeApiToken');
        Route::post('qr-token', 'generateQrToken')->middleware('can:admin.users.edit');
        Route::get('qr-token/status', 'qrTokenStatus')->middleware('can:admin.users.edit');
        Route::post('qr-login', 'qrLogin')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
    });

Route::controller(PasskeyController::class)
    ->prefix('auth/passkeys')
    ->group(function () {
        // Public login ceremony
        Route::get('login/options', 'loginOptions')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
        Route::post('login', 'loginVerify')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');

        // Authenticated management (add/list/remove a passkey)
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/', 'index');
            Route::get('register/options', 'registrationOptions');
            Route::post('register', 'store');
            Route::delete('{id}', 'destroy');
        });
    });

Route::controller(SsoController::class)
    ->prefix('auth/sso')
    ->group(function () {
        Route::get('providers', 'providers')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
        Route::get('{provider}/redirect', 'redirect')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
        Route::get('{provider}/callback', 'callback')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
        Route::post('complete', 'complete')
            ->withoutMiddleware('auth')
            ->middleware('throttle:login');
    });

Route::post('auth/tenant-handoff/complete', [TenantHandoffController::class, 'complete'])
    ->withoutMiddleware('auth')
    ->middleware('throttle:login');

Route::controller(CustomerAuthController::class)
    ->prefix('customer-auth')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->group(function () {
        // A QR menu runs in a browser, not inside a signed native customer
        // application.  Tenant ownership is resolved from the active menu in
        // CustomerAuthController, so never require a native app session before
        // a customer can request or verify their OTP.
        Route::post('otp/request', 'requestOtp')->middleware('throttle:login');
        Route::post('otp/verify', 'verifyOtp')->middleware('throttle:login');
        Route::post('google', 'google')->middleware([
            \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
            'throttle:login',
        ]);
        Route::post('apple', 'apple')->middleware([
            \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
            'throttle:login',
        ]);
        Route::post('register', 'register')->middleware([
            \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
            'throttle:login',
        ]);
        Route::post('login', 'login')->middleware([
            \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
            'throttle:login',
        ]);
        Route::middleware([
            'auth:sanctum',
            \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class,
        ])->group(function () {
            Route::get('me', 'me');
            Route::put('me', 'update');
            Route::post('phone-change/request', 'requestPhoneChange')->middleware('throttle:login');
            Route::post('phone-change/verify', 'verifyPhoneChange')->middleware('throttle:login');
            Route::post('email-change/request', 'requestEmailChange')->middleware('throttle:login');
            Route::post('email-change/verify', 'verifyEmailChange')->middleware('throttle:login');
            Route::get('addresses', 'addresses');
            Route::post('addresses', 'saveAddress');
            Route::put('addresses/{reference}', 'saveAddress')->whereUuid('reference');
            Route::delete('addresses/{reference}', 'deleteAddress')->whereUuid('reference');
            Route::put('push-devices/current', 'registerPushDevice');
            Route::delete('push-devices/{installationId}', 'revokePushDevice')->whereUuid('installationId');
            Route::post('logout', 'logout');
        });
    });

Route::controller(CustomerEngagementController::class)
    ->prefix('customer-app/engagement')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware(['auth:sanctum', \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class])
    ->group(function () {
        Route::get('favourites', 'favourites');
        Route::put('favourites', 'saveFavourite')->middleware('throttle:60,1');
        Route::delete('favourites/{type}/{subject}', 'deleteFavourite')->middleware('throttle:60,1');
        Route::get('reports', 'reports');
        Route::post('reports', 'submitReport')->middleware('throttle:10,1');
    });

Route::controller(CustomerExperienceController::class)
    ->prefix('customer-app')
    ->withoutMiddleware(['auth', \Modules\Saas\Http\Middleware\EnsureAuthenticatedTenant::class])
    ->middleware(['auth:sanctum', \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class, 'throttle:60,1'])
    ->group(function () {
        Route::get('wallet', 'wallet');
        Route::get('offers', 'offers');
        Route::get('referral', 'referral');
        Route::get('notifications', 'notifications');
        Route::put('notifications/read-all', 'readAllNotifications');
        Route::put('notifications/{reference}/read', 'readNotification')->whereUuid('reference');
        Route::get('support', 'support');
    });

Route::post('users/{customer}/wallet/credits', [CustomerWalletAdminController::class, 'credit'])
    ->whereNumber('customer')
    ->middleware(['auth:sanctum', 'can:admin.users.edit', 'throttle:30,1']);

Route::controller(RoleController::class)
    ->prefix('roles')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.roles.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.roles.edit|admin.roles.create');
        Route::get('/permissions/tree', 'permissionTree')->middleware('can:admin.roles.index');
        Route::get('/{id}', 'show')->middleware('permission:admin.roles.show|admin.roles.edit');
        Route::post('/', 'store')->middleware('can:admin.roles.create');
        Route::put('/{id}', 'update')->middleware('can:admin.roles.edit');
        Route::put('/{id}/permissions', 'syncPermissions')->middleware('can:admin.roles.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.roles.destroy');
    });

Route::controller(UserController::class)
    ->prefix('users')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.users.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.users.edit|admin.users.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.users.show|admin.users.edit');
        Route::post('/', 'store')->middleware('can:admin.users.create');
        Route::put('/{id}', 'update')->middleware('can:admin.users.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.users.destroy');
    });

Route::controller(CustomerController::class)
    ->prefix('customers')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.customers.index');
        Route::get('/engagement/summary', 'engagementSummary')->middleware('can:admin.customers.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.customers.index|admin.customers.edit|admin.customers.create');
        Route::post('/quick-store', 'quickStore')->middleware('can:admin.customers.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.customers.show|admin.customers.edit');
        Route::get('/{id}/detail', 'showDetail')->middleware('permission:admin.customers.show|admin.customers.edit');
        Route::post('/', 'store')->middleware('can:admin.customers.create');
        Route::put('/{id}', 'update')->middleware('can:admin.customers.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.customers.destroy');
    });

Route::controller(EmployeeShiftController::class)
    ->prefix('employee-shifts')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.employee_shifts.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.employee_shifts.edit|admin.employee_shifts.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.employee_shifts.show|admin.employee_shifts.edit');
        Route::post('/', 'store')->middleware('can:admin.employee_shifts.create');
        Route::put('/{id}', 'update')->middleware('can:admin.employee_shifts.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.employee_shifts.destroy');
    });

Route::controller(EmployeeAttendanceController::class)
    ->prefix('employee-attendances')
    ->group(function () {
        Route::get('/me', 'me');
        Route::post('/me/clock-in', 'meClockIn')->middleware('throttle:20,1');
        Route::post('/me/clock-out', 'meClockOut')->middleware('throttle:20,1');
        Route::get('/', 'index')->middleware('can:admin.employee_attendances.index');
        Route::get('/summary', 'summary')->middleware('can:admin.employee_attendances.summary');
        Route::post('/clock-in', 'clockIn')->middleware('can:admin.employee_attendances.clock_in');
        Route::post('/{id}/clock-out', 'clockOut')->middleware('can:admin.employee_attendances.clock_out');
        Route::put('/{id}', 'update')->middleware('can:admin.employee_attendances.edit');
    });

Route::controller(EmployeeCompensationController::class)
    ->prefix('employee-compensations')
    ->group(function () {
        Route::get('/', 'index')->middleware('can:admin.employee_compensations.index');
        Route::get('/form/meta', 'getFormMeta')->middleware('permission:admin.employee_compensations.edit|admin.employee_compensations.create');
        Route::get('/{id}', 'show')->middleware('permission:admin.employee_compensations.show|admin.employee_compensations.edit');
        Route::post('/', 'store')->middleware('can:admin.employee_compensations.create');
        Route::put('/{id}', 'update')->middleware('can:admin.employee_compensations.edit');
        Route::delete('/{ids}', 'destroy')->middleware('can:admin.employee_compensations.destroy');
    });

Route::controller(EmployeePayrollController::class)
    ->prefix('employee-payroll')
    ->group(function () {
        Route::get('/meta', 'meta')->middleware('can:admin.employee_payroll.index');
        Route::get('/preview', 'preview')->middleware('can:admin.employee_payroll.index');
        Route::get('/runs', 'runs')->middleware('can:admin.employee_payroll.index');
        Route::get('/runs/{id}', 'show')->middleware('can:admin.employee_payroll.show');
        Route::post('/runs', 'generate')->middleware('can:admin.employee_payroll.create');
        Route::post('/runs/{id}/approve', 'approve')->middleware('can:admin.employee_payroll.approve');
        Route::post('/runs/{id}/paid', 'markPaid')->middleware('can:admin.employee_payroll.approve');
        Route::post('/runs/{id}/void', 'void')->middleware('can:admin.employee_payroll.approve');
        Route::get('/runs/{id}/statutory-export', 'statutoryExport')->middleware('can:admin.employee_payroll.export');
        Route::get('/runs/{id}/payslips.pdf', 'payslipsPdf')->middleware('can:admin.employee_payroll.export');
    });

Route::controller(AccountController::class)
    ->prefix('accounts')
    ->group(function () {
        Route::get('me', 'me');
        Route::put('profile/update', 'updateProfile')->middleware('can:admin.profiles.edit');
        Route::put('password/update', 'updatePassword');
        Route::post('mfa/setup', 'beginMfaSetup');
        Route::post('mfa/confirm', 'confirmMfaSetup');
        Route::delete('mfa', 'disableMfa');
    });
