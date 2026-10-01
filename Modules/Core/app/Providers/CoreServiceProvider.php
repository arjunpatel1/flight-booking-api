<?php

namespace Modules\Core\Providers;

use App\NexDine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Console\PruneIdempotencyKeysCommand;
use Modules\Core\Console\UpgradeCommand;
use Modules\Installer\Http\Middleware\CheckInstalled;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Spatie\Permission\Middleware\{PermissionMiddleware, RoleMiddleware, RoleOrPermissionMiddleware};

class CoreServiceProvider extends ServiceProvider
{

    /**
     * Core module specific middlewares.
     *
     * @var array
     */
    protected array $middlewares = [
        'role' => RoleMiddleware::class,
        'permission' => PermissionMiddleware::class,
        'role_or_permission' => RoleOrPermissionMiddleware::class,
        "checkInstalled" => CheckInstalled::class
    ];

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->setupAppCacheDriver();
        $this->registerSetting();
        $this->setupAppName();
        $this->setupMailConfig();
        $this->setupFilesystem();
        $this->setupAppLocale();
        $this->setupAppTimezone();
        $this->activityLog();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerSchedule();
    }

    /**
     * Setup application cache driver.
     *
     * @return void
     */
    private function setupAppCacheDriver(): void
    {
        $this->app['config']->set('cache.default', NexDine::cacheEnabled() ? config('cache.default') : 'array');
    }

    /**
     * Register setting binding.
     *
     * @return void
     */
    private function registerSetting(): void
    {
        $this->app->singleton('setting', fn() => new SettingRepository(Setting::allCached()));
    }

    /**
     * Setup application name.
     *
     * @return void
     */
    private function setupAppName(): void
    {
        $this->app['config']->set('app.name', setting('app_name', config('app.name')));
    }

    /**
     * Setup application mail config.
     *
     * @return void
     */
    private function setupMailConfig(): void
    {
        // Honour the deployment mailer when no database override has been
        // configured. Defaulting to SMTP silently points fresh installations
        // at 127.0.0.1:2525 and makes queued communication appear successful
        // before it fails in the worker.
        $this->app['config']->set('mail.default', setting('mail_mailer', config('mail.default')));
        $this->app['config']->set('mail.from.address', setting('mail_from_address', config('mail.from.address')));
        $this->app['config']->set('mail.from.name', setting('mail_from_name', config('mail.from.name')));

        if ($this->app['config']->get('mail.default') === 'smtp') {
            $this->app['config']->set(
                'mail.mailers.smtp.host',
                setting('mail_host', config('mail.mailers.smtp.host'))
            );
            $this->app['config']->set(
                'mail.mailers.smtp.port',
                setting('mail_port', config('mail.mailers.smtp.port'))
            );
            $this->app['config']->set(
                'mail.mailers.smtp.username',
                setting('mail_username', config('mail.mailers.smtp.username'))
            );
            $this->app['config']->set(
                'mail.mailers.smtp.password',
                setting('mail_password', config('mail.mailers.smtp.password'))
            );
            $this->app['config']->set(
                'mail.mailers.smtp.encryption',
                setting('mail_encryption', config('mail.mailers.smtp.encryption'))
            );
        }
    }

    /**
     * Setup application filesystem config.
     *
     * @return void
     */
    private function setupFilesystem(): void
    {
        $disk = setting('default_filesystem_disk', config("filesystems.default"));
        $this->app['config']->set('filesystems.default', $disk);
        $this->app['config']->set('media-library.disk_name', $disk);

        if (setting('default_filesystem_disk') === "s3") {
            $this->app['config']->set('filesystems.disks.s3.key', setting('filesystem_s3_key'));
            $this->app['config']->set('filesystems.disks.s3.secret', setting('filesystem_s3_secret'));
            $this->app['config']->set('filesystems.disks.s3.region', setting('filesystem_s3_region'));
            $this->app['config']->set('filesystems.disks.s3.bucket', setting('filesystem_s3_bucket'));
            $this->app['config']->set('filesystems.disks.s3.url', setting('filesystem_s3_url'));
            $this->app['config']->set('filesystems.disks.s3.endpoint', setting('filesystem_s3_endpoint'));
            $this->app['config']->set('filesystems.disks.s3.use_path_style_endpoint', (bool)setting('filesystem_s3_use_path_style_endpoint', false));
        }
    }

    /**
     * Setup application locale.
     *
     * @return void
     */
    private function setupAppLocale(): void
    {
        $this->app['config']->set('app.locale', $defaultLocale = setting('default_locale', $this->app['config']->get('app.locale')));
        $this->app['config']->set('app.fallback_locale', $defaultLocale);
        $this->app->setLocale($defaultLocale);
    }

    /**
     * Setup application timezone.
     *
     * @return void
     */
    private function setupAppTimezone(): void
    {
        $timezone = setting('default_timezone') ?: config('app.timezone');

        date_default_timezone_set($timezone);

        $this->app['config']->set('app.timezone', $timezone);
    }

    /**
     * Enabled activity log.
     *
     * @return void
     */
    private function activityLog(): void
    {
        // Capture the published package defaults before overwriting them. The
        // fallbacks previously read config('activity_log.*') — an underscored
        // key that does not exist (the file is config/activitylog.php), so they
        // always resolved to null and silently disabled auditing for every
        // request, regardless of ACTIVITY_LOGGER_ENABLED.
        $enabledDefault = $this->app['config']->get('activitylog.enabled', true);
        $retentionDefault = $this->app['config']->get('activitylog.delete_records_older_than_days');

        $this->app['config']->set(
            'activitylog.enabled',
            $this->app->runningInConsole()
                ? false
                : (bool) setting('activity_log.enabled', $enabledDefault)
        );

        $this->app['config']->set(
            'activitylog.delete_records_older_than_days',
            setting('activity_log.delete_records_older_than_days', $retentionDefault)
        );
    }

    /**
     * Register the filters.
     *
     * @return void
     */
    private function registerMiddleware(): void
    {
        foreach ($this->middlewares as $name => $middleware) {
            $this->app['router']->aliasMiddleware($name, $middleware);
        }
    }

    /**
     * Register command
     *
     * @return void
     */
    private function registerCommands(): void
    {
        $this->commands([
            PruneIdempotencyKeysCommand::class,
            UpgradeCommand::class,
        ]);
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('core:prune-idempotency')
                ->dailyAt('03:30')
                ->onOneServer()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
