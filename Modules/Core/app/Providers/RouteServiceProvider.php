<?php

namespace Modules\Core\Providers;

use App\NexDine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\User\Exceptions\TooManyLoginAttemptsException;
use Nwidart\Modules\Facades\Module;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->routes(fn() => $this->mapModulesRoutes());
        $this->configureRateLimiting();

        Route::pattern('id', '[0-9]+');
    }

    /**
     * Map routes from all enabled modules.
     *
     * @return void
     */
    private function mapModulesRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach (Module::getOrdered() as $module) {
            foreach (config('core.routes') as $config) {
                $this->mapRoutes(
                    "{$module->getPath()}/routes/{$config['file']}",
                    "Modules\\{$module->getName()}\\{$config['namespace']}",
                    $config
                );
            }
        }
    }

    /**
     * Map routes.
     *
     * @param string $path
     * @param string $namespace
     * @param array $config
     * @return void
     */
    private function mapRoutes(string $path, string $namespace, array $config): void
    {
        if (!file_exists($path)) {
            return;
        }

        $route = Route::middleware($config['middleware'])
            ->namespace($namespace);

        if (isset($config["name"])) {
            $route->name($config["name"]);
        }

        if (NexDine::routeDomainEnabled()) {
            $route->domain($config['domain']);
            if (isset($config['version'])) {
                $route->prefix($config['version']);
            }
        } else {
            if (isset($config['prefix'])) {
                $route->prefix($config['prefix']);
            }

            if (isset($config['version'])) {
                $route->prefix("{$config['prefix']}/{$config['version']}");
            }
        }

        $route->group(fn() => require $path);
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->user()) {
                $terminal = trim((string) $request->header('X-NexDine-Device-Id')) ?: $request->ip();

                return [
                    Limit::perMinute(600)->by("{$request->user()->id}|{$terminal}"),
                    Limit::perMinute(3000)->by("user|{$request->user()->id}"),
                ];
            }

            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('customer-app-builds', fn (Request $request) => Limit::perHour(
            max(1, (int) config('saas.customer_app_build.requests_per_hour', 10))
        )->by('customer-app-build|'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('pos-terminal-heartbeat', function (Request $request) {
            $deviceId = trim((string) ($request->input('device_id')
                ?: $request->header('X-NexDine-Device-Id')));
            $identity = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(30)->by("{$identity}|{$deviceId}");
        });

        RateLimiter::for('pos-offline-order', function (Request $request) {
            $deviceId = trim((string) ($request->input('device_id')
                ?: $request->header('X-NexDine-Device-Id')));
            $identity = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(120)->by("{$identity}|{$deviceId}");
        });

        RateLimiter::for('customer-app-bootstrap', fn (Request $request) => Limit::perMinute(20)
            ->by('customer-app-bootstrap|'.$request->ip())
            ->response(fn () => response()->json([
                'message' => 'Restaurant services are temporarily offline. Please retry shortly.',
                'body' => ['code' => 'CUSTOMER_APP_THROTTLED'],
            ], 429)));

        RateLimiter::for('login', function (Request $request) {
            $username = 'identifier';
            $identifierValue = $request->{$username};
            $identifierStr = is_scalar($identifierValue) ? (string) $identifierValue : md5(json_encode($identifierValue));
            $maxAttempts = (int) env('LOGIN_RATE_LIMIT', 3);
            return Limit::perMinute($maxAttempts)->by($identifierStr . $request->ip())
                ->response(function ($request, $headers) use ($username) {
                    throw new TooManyLoginAttemptsException(
                        retryAfter: $headers['Retry-After']
                    );
                });
        });

    }
}
