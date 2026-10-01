<?php

namespace Modules\Saas\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Support\TenantResourceNaming;

/**
 * Phase 2, Module 4/5 — carries tenant context across the queue boundary for
 * EVERY job, without editing a single job class.
 *
 * How it works, and why it is backward compatible:
 *
 *  - On dispatch, `Queue::createPayloadUsing` stamps the current tenant id into
 *    every job's payload. Jobs that do not care never read it; it is inert
 *    extra data. Control-plane jobs with no tenant get nothing stamped.
 *
 *  - On the worker, `JobProcessing` reads that id back and binds it into the
 *    TenantContext singleton *before* the job runs, so any tenant-aware code the
 *    job touches sees the right tenant. `JobProcessed`/`JobExceptionOccurred`
 *    clear it, so job N+1 can never inherit job N's tenant (the one real hazard
 *    of a long-lived worker process).
 *
 * This makes all 30 queued jobs tenant-aware at once, additively. It changes no
 * job's behaviour today (shared DB, tenant context was already implicit); it
 * makes the context *explicit and restored*, which is the Phase 2 requirement
 * and the prerequisite for Phase 3.
 *
 * Non-deferred on purpose: these hooks must register on every boot, including
 * queue workers, so this provider does no lazy binding.
 */
class SaasRuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantResourceNaming::class);
        $this->app->singleton(\Modules\Saas\Support\TenantChannelNaming::class);
        $this->app->singleton(\Modules\Saas\Support\RealtimeChannelTelemetry::class);
    }

    public function boot(): void
    {
        $this->registerCustomerAppBuildRateLimiter();
        $this->registerCustomerAppBuildWorkerRateLimiter();
        $this->registerCustomerAppContentRateLimiter();
        $this->stampTenantOnDispatch();
        $this->restoreTenantOnProcessing();
        $this->registerDualBroadcast();
    }

    private function registerCustomerAppBuildWorkerRateLimiter(): void
    {
        RateLimiter::for('customer-app-build-worker', function (Request $request): Limit {
            $worker = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $request->header('X-Build-Worker-ID')) ?: 'unknown';

            return Limit::perMinute(120)->by('customer-app-worker:'.hash('sha256', $worker.'|'.$request->ip()));
        });
    }

    /**
     * Build requests are expensive control-plane operations. Scope the named
     * limiter to the authenticated tenant and user so one restaurant cannot
     * consume another restaurant's allowance. The IP fallback only applies
     * before authentication and does not grant access to the protected route.
     */
    private function registerCustomerAppBuildRateLimiter(): void
    {
        RateLimiter::for('customer-app-builds', function (Request $request): Limit {
            $user = $request->user();
            $tenantId = is_numeric($user?->tenant_id) ? (int) $user->tenant_id : 'guest';
            $actorId = $user?->getAuthIdentifier() ?? $request->ip();
            $limit = max(1, (int) config('saas.customer_app_build.requests_per_hour', 10));

            return Limit::perHour($limit)->by("customer-app-build:{$tenantId}:{$actorId}");
        });
    }

    /**
     * Runtime content updates are cheap, but they can affect every customer app
     * device for a restaurant. Keep the limiter tenant-scoped so a noisy tenant
     * cannot consume another tenant's write allowance.
     */
    private function registerCustomerAppContentRateLimiter(): void
    {
        RateLimiter::for('customer-app-content', function (Request $request): Limit {
            $user = $request->user();
            $tenantId = is_numeric($user?->tenant_id) ? (int) $user->tenant_id : 'platform';
            $actorId = $user?->getAuthIdentifier() ?? $request->ip();
            $limit = max(10, (int) config('saas.customer_app.content_requests_per_minute', 60));

            return Limit::perMinute($limit)->by("customer-app-content:{$tenantId}:{$actorId}");
        });
    }

    /**
     * Phase 2.5 — dual-broadcast, activated only at REALTIME_CHANNEL_VERSION=v2.
     *
     * Registers a `dual` broadcast driver that wraps the configured Reverb
     * broadcaster and mirrors every v1 channel to its v2 sibling. When the flag
     * is v2, the default broadcast connection is pointed at it. At v1 (default)
     * nothing here activates — broadcasting is byte-for-byte as before.
     */
    private function registerDualBroadcast(): void
    {
        $version = (string) config('saas.realtime.channel_version', 'v1');

        \Illuminate\Support\Facades\Broadcast::extend('dual', function ($app, array $config) {
            $inner = \Illuminate\Support\Facades\Broadcast::connection(
                $config['inner'] ?? 'reverb'
            );

            return new \Modules\Saas\Broadcasting\DualChannelBroadcaster(
                $inner,
                $app->make(\Modules\Saas\Support\TenantChannelNaming::class),
                $app->make(\Modules\Saas\Support\RealtimeChannelTelemetry::class),
            );
        });

        if ($version === \Modules\Saas\Support\TenantChannelNaming::VERSION_V2) {
            // Add the dual connection (wrapping reverb) and make it the default,
            // without editing the existing reverb connection at all.
            config([
                'broadcasting.connections.reverb_dual' => ['driver' => 'dual', 'inner' => 'reverb'],
                'broadcasting.default' => 'reverb_dual',
            ]);
        }
    }

    /**
     * Add the current tenant id to every outgoing job payload.
     */
    private function stampTenantOnDispatch(): void
    {
        Queue::createPayloadUsing(function (): array {
            $tenantId = $this->currentTenantId();

            return $tenantId !== null
                ? [TenantResourceNaming::QUEUE_PAYLOAD_KEY => $tenantId]
                : [];
        });
    }

    /**
     * Bind tenant context before each job, clear it after (success or failure).
     */
    private function restoreTenantOnProcessing(): void
    {
        $context = fn (): TenantContext => $this->app->make(TenantContext::class);

        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use ($context) {
            $tenantId = $this->tenantIdFromJob($event->job);
            if ($tenantId !== null) {
                $context()->setId($tenantId);
            }
        });

        $clear = function ($event) use ($context) {
            $context()->clear();
        };

        $this->app['events']->listen(JobProcessed::class, $clear);
        $this->app['events']->listen(JobExceptionOccurred::class, $clear);
    }

    private function currentTenantId(): ?int
    {
        // Prefer an explicitly bound context; fall back to the authenticated
        // user's tenant so request-dispatched jobs are stamped even before any
        // code sets the context.
        $bound = $this->app->bound(TenantContext::class)
            ? $this->app->make(TenantContext::class)->id()
            : null;

        if ($bound !== null) {
            return $bound;
        }

        try {
            return auth()->check() ? auth()->user()?->tenant_id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tenantIdFromJob(Job $job): ?int
    {
        try {
            $payload = $job->payload();
            $value = $payload[TenantResourceNaming::QUEUE_PAYLOAD_KEY] ?? null;

            return is_numeric($value) ? (int) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
