<?php

namespace Modules\User\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;
use Modules\User\Console\Permission\{SyncDefaultRoles, SyncPermissions};
use Modules\User\Models\User;
use Modules\User\Services\CustomerOtp\CustomerOtpSender;
use Modules\User\Services\CustomerOtp\NotificationCustomerOtpSender;

class UserServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(CustomerOtpSender::class, NotificationCustomerOtpSender::class);
        // This token-based API replaces laravel/passkeys' stateful-guard routes
        // with custom Sanctum-token endpoints (see PasskeyController).
        Passkeys::ignoreRoutes();
        Passkeys::useUserModel(User::class);
    }

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerPasswordResetUrl();
    }

    /**
     * Point the password-reset link at the SPA reset page instead of a backend URL.
     */
    private function registerPasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            return $frontend . '/auth/reset-password?token=' . $token
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /**
     * Register command
     *
     * @return void
     */
    private function registerCommands(): void
    {
        $this->commands([
            SyncPermissions::class,
            SyncDefaultRoles::class
        ]);
    }
}
