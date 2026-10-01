<?php

namespace Modules\Saas\Services\Launch;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Saas\Enums\OnboardingRequestStatus;
use Modules\Saas\Models\SaasContentItem;
use Modules\Saas\Models\SaasDeliveryJob;
use Modules\Saas\Models\SaasOnboardingRequest;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Services\Content\SaasContentCatalogService;
use Throwable;

/**
 * Launch certification: is this installation actually ready to sell?
 *
 * Every check answers one question with a status, a human explanation, and
 * where to go to fix it. Checks are **read-only and non-destructive** — this
 * never restarts a worker or writes config; it reports, and points at the
 * screen that acts.
 *
 * Infrastructure that cannot be proven from inside PHP (Supervisor running,
 * Nginx/Apache vhost live, cron installed, a real TLS handshake) is reported
 * as `manual` rather than `ok`. Claiming a green tick for something we did not
 * actually observe is worse than admitting we cannot see it.
 */
class LaunchReadinessService
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const BLOCKER = 'blocker';
    /** Requires a human to verify outside the application. */
    public const MANUAL = 'manual';

    public function __construct(private readonly SaasContentCatalogService $catalog)
    {
    }

    public function report(): array
    {
        $checks = $this->checks();

        $counts = [
            'blocker' => 0,
            'warning' => 0,
            'ok' => 0,
            'manual' => 0,
        ];

        foreach ($checks as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
        }

        $automated = $counts['ok'] + $counts['warning'] + $counts['blocker'];

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => [
                'app_env' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
            ],
            'summary' => [
                ...$counts,
                'total' => count($checks),
                // Manual items are excluded: a score cannot honestly count
                // things nobody has verified.
                'score' => $automated > 0 ? (int) round($counts['ok'] / $automated * 100) : 0,
                'is_go' => $counts['blocker'] === 0,
            ],
            'checks' => $checks,
            'queues' => $this->queueSnapshot(),
            'onboarding' => $this->onboardingSnapshot(),
            'provisioning' => $this->provisioningSnapshot(),
        ];
    }

    /**
     * @return array<int, array{key:string,group:string,label:string,status:string,message:string,fix:?string,fix_route:?string}>
     */
    private function checks(): array
    {
        return [
            // --- Environment -------------------------------------------------
            $this->check('environment', 'Environment', 'App environment',
                app()->environment('production') ? self::OK : self::WARNING,
                app()->environment('production')
                    ? 'Running in production.'
                    : 'APP_ENV is "' . app()->environment() . '". Production installs should be "production".',
                'Set APP_ENV=production'),

            $this->check('debug', 'Environment', 'Debug mode',
                config('app.debug') ? self::BLOCKER : self::OK,
                config('app.debug')
                    ? 'APP_DEBUG is on — stack traces and config would leak to customers.'
                    : 'Debug mode is off.',
                'Set APP_DEBUG=false'),

            $this->check('app_key', 'Environment', 'Application key',
                filled(config('app.key')) ? self::OK : self::BLOCKER,
                filled(config('app.key'))
                    ? 'Application key is set.'
                    : 'APP_KEY is empty — encryption and activation keys cannot work.',
                'php artisan key:generate'),

            // --- Database ----------------------------------------------------
            $this->databaseCheck(),
            $this->migrationCheck(),

            // --- Cache / queue / realtime ------------------------------------
            $this->check('redis', 'Infrastructure', 'Cache driver',
                in_array(config('cache.default'), ['redis', 'memcached'], true) ? self::OK : self::WARNING,
                'Cache driver is "' . config('cache.default') . '".',
                'Set CACHE_STORE=redis for production'),

            $this->queueCheck(),
            $this->failedJobsCheck(),

            $this->check('reverb', 'Infrastructure', 'Reverb (realtime)',
                filled(config('broadcasting.connections.reverb.key')) ? self::OK : self::WARNING,
                filled(config('broadcasting.connections.reverb.key'))
                    ? 'Reverb credentials are configured.'
                    : 'Reverb app key is missing — live order and print events will not broadcast.',
                'Set REVERB_APP_KEY / REVERB_APP_SECRET'),

            $this->check('scheduler', 'Infrastructure', 'Scheduler (cron)', self::MANUAL,
                'Confirm `php artisan schedule:run` is in cron every minute. Cannot be observed from inside the app.',
                'Add the Laravel scheduler cron entry'),

            $this->check('supervisor', 'Infrastructure', 'Queue workers (Supervisor)', self::MANUAL,
                'Confirm Supervisor is running workers for the default and provisioning queues. '
                . 'Without them the onboarding pipeline stalls silently.',
                'See Modules/Saas/deploy/nexdine-supervisor.conf'),

            $this->check('webserver', 'Infrastructure', 'Web server & SSL', self::MANUAL,
                'Confirm the vhost is live and TLS terminates correctly for the root and tenant subdomains.',
                'See Modules/Saas/deploy/nginx-tenant-ssl.sh'),

            $this->storageCheck(),

            // --- Commerce ----------------------------------------------------
            $this->paymentsCheck(),
            $this->check('onboarding_mode', 'Commerce', 'Onboarding approval mode',
                self::OK,
                'Approval mode is "' . config('saas.onboarding.default_approval_mode') . '"; '
                . 'self-service is "' . config('saas.self_service.mode') . '".',
                null,
                'admin.saas.onboarding_requests'),

            // --- Customer-facing content -------------------------------------
            $this->downloadsCheck(),
            $this->documentationCheck(),
            $this->supportCheck(),

            // --- Communication -----------------------------------------------
            $this->mailCheck(),
            $this->check('whatsapp', 'Communication', 'WhatsApp',
                config('saas.workspace.welcome_notifications.whatsapp_enabled') ? self::OK : self::WARNING,
                config('saas.workspace.welcome_notifications.whatsapp_enabled')
                    ? 'Welcome WhatsApp is enabled. Confirm the tenant_welcome template is approved.'
                    : 'Welcome WhatsApp is disabled. Customers will not receive a WhatsApp on activation.',
                'Set SAAS_WELCOME_WHATSAPP_ENABLED=true once the template is approved'),

            // --- Monitoring ---------------------------------------------------
            $this->check('monitoring', 'Monitoring', 'Operations Center', self::OK,
                'Operations Center and health checks are available.',
                null,
                'admin.saas.operations_center'),
        ];
    }

    private function databaseCheck(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->check('database', 'Database', 'Connection', self::OK,
                'Connected to "' . DB::connection()->getDatabaseName() . '".');
        } catch (Throwable $exception) {
            return $this->check('database', 'Database', 'Connection', self::BLOCKER,
                'Cannot connect: ' . $exception->getMessage(), 'Check DB_* environment values');
        }
    }

    /**
     * Every table this platform's newer features depend on. A missing table
     * here means a migration was never run on this environment.
     */
    private function migrationCheck(): array
    {
        $required = [
            'tenants', 'branches', 'users', 'saas_provisioning_runs',
            'saas_onboarding_invites', 'saas_onboarding_requests',
            'saas_content_items', 'saas_billing_invoices',
            'saas_customer_success_records', 'idempotency_keys',
        ];

        try {
            $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        } catch (Throwable $exception) {
            return $this->check('migrations', 'Database', 'Schema', self::BLOCKER,
                'Cannot inspect the schema: ' . $exception->getMessage());
        }

        // tenants.lifecycle_stage arrives with a later migration than the table.
        if (! $missing && Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'lifecycle_stage')) {
            $missing[] = 'tenants.lifecycle_stage';
        }

        return $this->check('migrations', 'Database', 'Schema', $missing ? self::BLOCKER : self::OK,
            $missing
                ? 'Missing: ' . implode(', ', $missing) . '. Migrations have not been run on this environment.'
                : 'All required tables and columns are present.',
            $missing ? 'php artisan migrate --force' : null);
    }

    private function queueCheck(): array
    {
        $driver = config('queue.default');

        return $this->check('queue', 'Infrastructure', 'Queue driver',
            $driver === 'sync' ? self::BLOCKER : self::OK,
            $driver === 'sync'
                ? 'Queue driver is "sync" — provisioning would run inside the web request and time out.'
                : 'Queue driver is "' . $driver . '".',
            $driver === 'sync' ? 'Set QUEUE_CONNECTION=redis or database' : null);
    }

    private function failedJobsCheck(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->check('failed_jobs', 'Infrastructure', 'Failed jobs', self::WARNING,
                'The failed_jobs table is missing — failures cannot be inspected.',
                'php artisan queue:failed-table && php artisan migrate');
        }

        $count = DB::table('failed_jobs')->count();

        return $this->check('failed_jobs', 'Infrastructure', 'Failed jobs',
            $count === 0 ? self::OK : self::WARNING,
            $count === 0 ? 'No failed jobs.' : "{$count} failed job(s) waiting to be reviewed.",
            $count > 0 ? 'Review and retry failed jobs' : null,
            'admin.saas.operations_center');
    }

    private function storageCheck(): array
    {
        $path = storage_path('app');
        $writable = is_writable($path);
        $publicLinked = file_exists(public_path('storage'));

        return $this->check('storage', 'Infrastructure', 'Storage',
            $writable ? ($publicLinked ? self::OK : self::WARNING) : self::BLOCKER,
            match (true) {
                ! $writable => "Storage path is not writable: {$path}",
                ! $publicLinked => 'public/storage symlink is missing — uploaded images will 404.',
                default => 'Storage is writable and the public symlink exists.',
            },
            $writable ? ($publicLinked ? null : 'php artisan storage:link') : 'Fix storage permissions');
    }

    private function paymentsCheck(): array
    {
        $gateways = array_filter([
            'razorpay' => config('saas.billing.razorpay.webhook_secret'),
            'stripe' => config('saas.billing.stripe.webhook_secret'),
        ]);

        return $this->check('payments', 'Commerce', 'Payment webhooks',
            $gateways ? self::OK : self::WARNING,
            $gateways
                ? 'Webhook secrets configured for: ' . implode(', ', array_keys($gateways)) . '.'
                : 'No payment webhook secrets configured. Paid signups cannot settle automatically.',
            $gateways ? null : 'Set SAAS_BILLING_RAZORPAY_WEBHOOK_SECRET or the Stripe equivalent');
    }

    private function downloadsCheck(): array
    {
        $downloads = collect($this->catalog->downloads());
        $available = $downloads->where('is_downloadable', true);

        return $this->check('downloads', 'Content', 'Download Center',
            $available->isEmpty() ? self::BLOCKER : self::OK,
            $available->isEmpty()
                ? 'No downloadable apps are published. A customer completing onboarding reaches an empty Download Center.'
                : $available->count() . ' of ' . $downloads->count() . ' items are downloadable.',
            $available->isEmpty() ? 'Publish the Waiter App and Print Agent' : null,
            'admin.saas.content');
    }

    private function documentationCheck(): array
    {
        $articles = collect($this->catalog->documentation())
            ->flatMap(fn (array $section) => $section['articles'] ?? [])
            ->where('is_available', true);

        return $this->check('documentation', 'Content', 'Documentation',
            $articles->isEmpty() ? self::WARNING : self::OK,
            $articles->isEmpty()
                ? 'No documentation articles have a URL. Customers cannot self-serve answers.'
                : $articles->count() . ' article(s) published.',
            $articles->isEmpty() ? 'Publish documentation links' : null,
            'admin.saas.content');
    }

    private function supportCheck(): array
    {
        $support = $this->catalog->support();
        $reachable = array_filter([
            $support['email'] ?? null,
            $support['phone'] ?? null,
            $support['whatsapp'] ?? null,
        ]);

        return $this->check('support', 'Content', 'Support contacts',
            $reachable ? self::OK : self::BLOCKER,
            $reachable
                ? count($reachable) . ' support channel(s) configured.'
                : 'No support channel is configured. A stuck customer has no way to reach anyone.',
            $reachable ? null : 'Add a support email or phone number',
            'admin.saas.content');
    }

    private function mailCheck(): array
    {
        $mailer = config('mail.default');
        $configured = $mailer !== 'log' && filled(config('mail.from.address'));

        return $this->check('mail', 'Communication', 'Email delivery',
            $configured ? self::OK : self::BLOCKER,
            $configured
                ? "Mailer is \"{$mailer}\" from " . config('mail.from.address') . '.'
                : "Mailer is \"{$mailer}\" — welcome emails and password resets will not reach customers.",
            $configured ? null : 'Configure MAIL_MAILER and MAIL_FROM_ADDRESS');
    }

    private function queueSnapshot(): array
    {
        return [
            'driver' => config('queue.default'),
            'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }

    private function onboardingSnapshot(): array
    {
        if (! Schema::hasTable('saas_onboarding_requests')) {
            return ['available' => false];
        }

        $byStatus = SaasOnboardingRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'available' => true,
            'awaiting_payment' => (int) ($byStatus[OnboardingRequestStatus::AwaitingPayment->value] ?? 0),
            'awaiting_approval' => (int) ($byStatus[OnboardingRequestStatus::AwaitingApproval->value] ?? 0),
            'provisioning' => (int) ($byStatus[OnboardingRequestStatus::Provisioning->value] ?? 0),
            'failed' => (int) ($byStatus[OnboardingRequestStatus::Failed->value] ?? 0),
            'completed' => (int) ($byStatus[OnboardingRequestStatus::Completed->value] ?? 0),
        ];
    }

    private function provisioningSnapshot(): array
    {
        if (! Schema::hasTable('saas_provisioning_runs')) {
            return ['available' => false];
        }

        $byStatus = SaasProvisioningRun::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'available' => true,
            'by_status' => $byStatus,
            'delivery_pending' => Schema::hasTable('saas_delivery_jobs')
                ? SaasDeliveryJob::query()->whereIn('status', ['pending', 'processing'])->count()
                : null,
        ];
    }

    private function check(
        string $key,
        string $group,
        string $label,
        string $status,
        string $message,
        ?string $fix = null,
        ?string $fixRoute = null,
    ): array {
        return compact('key', 'group', 'label', 'status', 'message', 'fix') + ['fix_route' => $fixRoute];
    }
}
