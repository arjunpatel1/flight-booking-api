<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Jobs\RunSaasDeploymentJob;
use Modules\Saas\Jobs\RunTenantServerAutomationJob;
use Modules\Saas\Models\SaasDeliveryJob;
use Modules\Saas\Models\SaasDeploymentRun;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Infrastructure\InfrastructureHealthService;
use Modules\Saas\Services\Infrastructure\SaasDeploymentService;
use Modules\Saas\Services\Infrastructure\SaasTerminalService;
use Modules\Saas\Services\Provisioning\SaasServerAutomationService;
use Modules\Saas\Support\ProvisioningWorkflow;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Support\ApiResponse;

/**
 * Infrastructure dashboard — READ ONLY (Phase 1).
 *
 * Reports platform infrastructure health and the tenant infrastructure
 * registry. There are deliberately no write actions: no provisioning, no
 * connection changes, no control buttons. It observes; it never acts.
 *
 * Guarded by `admin.saas.index` (platform operators only).
 */
class SaasInfrastructureController extends Controller
{
    public function __construct(
        private readonly InfrastructureHealthService $health,
        private readonly SaasServerAutomationService $serverAutomation,
        private readonly SaasDeploymentService $deployment,
        private readonly SaasTerminalService $terminal,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'platform' => $this->health->platform(),
            'tenants' => $this->health->tenants(),
            'tenant_domains' => Tenant::query()
                ->withoutGlobalActive()
                ->select(['id', 'name', 'domain', 'contact_email', 'is_active'])
                ->where('is_active', true)
                ->whereNotNull('domain')
                ->orderBy('name')
                ->get(),
            'server_automation' => $this->serverAutomation->health()['automation'],
            'deployment' => $this->deployment->configuration(),
            'deployment_runs' => SaasDeploymentRun::query()->latest()->limit(10)->get(),
            'terminal' => $this->terminal->configuration(),
            // The dedicated-database plan, shown as a disabled preview so
            // operators can see what is coming without being able to trigger it.
            'dedicated_provisioning' => [
                'enabled' => ProvisioningWorkflow::dedicatedProvisioningEnabled(),
                'stages' => ProvisioningWorkflow::dedicatedDatabaseStages(),
            ],
            // Phase 2.5 realtime migration status, read-only.
            'realtime' => [
                'channel_version' => (string) config('saas.realtime.channel_version', 'v1'),
                'telemetry' => app(\Modules\Saas\Support\RealtimeChannelTelemetry::class)->snapshot(),
            ],
        ]);
    }

    public function horizon(Request $request, string $command): JsonResponse
    {
        $commands = [
            'pause' => ['artisan' => 'horizon:pause', 'message' => 'Horizon queue processing paused.'],
            'continue' => ['artisan' => 'horizon:continue', 'message' => 'Horizon queue processing resumed.'],
            'terminate' => ['artisan' => 'horizon:terminate', 'message' => 'Horizon workers will restart through Supervisor.'],
            'snapshot' => ['artisan' => 'horizon:snapshot', 'message' => 'Horizon metrics snapshot captured.'],
        ];

        abort_unless(isset($commands[$command]), 404);

        Artisan::call($commands[$command]['artisan']);

        activity('saas.infrastructure')
            ->causedBy($request->user())
            ->withProperties(['command' => $command])
            ->log('Horizon control command executed.');

        return ApiResponse::success([
            'message' => $commands[$command]['message'],
            'output' => trim(Artisan::output()),
        ]);
    }

    /**
     * Clear application caches from the admin panel.
     *
     * Configuration changes (settings, translations, permissions) are cached
     * aggressively, so applying them previously needed shell access. Each scope
     * maps to the artisan command an operator would otherwise run by hand.
     */
    public function cache(Request $request, string $scope): JsonResponse
    {
        $scopes = [
            'application' => ['artisan' => ['cache:clear'], 'message' => 'Application cache cleared.'],
            'config' => ['artisan' => ['config:clear'], 'message' => 'Configuration cache cleared.'],
            'route' => ['artisan' => ['route:clear'], 'message' => 'Route cache cleared.'],
            'view' => ['artisan' => ['view:clear'], 'message' => 'Compiled views cleared.'],
            'permission' => ['artisan' => ['permission:cache-reset'], 'message' => 'Permission cache cleared.'],
            'all' => [
                'artisan' => ['cache:clear', 'config:clear', 'route:clear', 'view:clear', 'permission:cache-reset'],
                'message' => 'All caches cleared.',
            ],
        ];

        abort_unless(isset($scopes[$scope]), 404);

        $output = [];
        foreach ($scopes[$scope]['artisan'] as $command) {
            Artisan::call($command);
            $output[$command] = trim(Artisan::output());
        }

        // Settings are held in a container singleton built from a tagged cache.
        // Clearing caches without rebinding leaves the process serving an empty
        // settings collection, which silently nulls locale, timezone and
        // currency for the rest of the request.
        app(SettingServiceInterface::class)->refreshSettingBinding();

        activity('saas.infrastructure')
            ->causedBy($request->user())
            ->withProperties(['scope' => $scope, 'commands' => array_keys($output)])
            ->log('Cache cleared from the admin panel.');

        return ApiResponse::success([
            'message' => $scopes[$scope]['message'],
            'cleared' => array_keys($output),
            'output' => $output,
        ]);
    }

    /**
     * Queue a tenant-bound SSL preview or installation.
     *
     * The hostname is always read from the tenant record. Operators cannot
     * submit an arbitrary hostname, filesystem path, or shell command from the
     * browser. Apply mode is still protected by the server automation feature
     * flags and the worker's scoped sudo policy.
     */
    public function tenantSsl(Request $request, int $tenant): JsonResponse
    {
        $validated = $request->validate([
            'apply' => ['required', 'boolean'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ]);

        $restaurant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenant);
        $domain = strtolower(trim((string) $restaurant->domain));

        abort_unless(
            $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
            422,
            'This restaurant does not have a valid domain. Update its domain before installing SSL.'
        );

        $email = $validated['email']
            ?: config('saas.server_automation.ssl_email')
            ?: $restaurant->contact_email;

        abort_unless(filled($email), 422, 'Add a certificate email before installing SSL.');

        $job = SaasDeliveryJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $restaurant->id,
            'type' => 'server_automation',
            'payload' => [
                'mode' => 'tenant_ssl',
                'tenant_domain' => $domain,
                'frontend_root' => config('saas.server_automation.frontend_root'),
                'email' => $email,
                'apply' => (bool) $validated['apply'],
            ],
        ]);

        RunTenantServerAutomationJob::dispatch($job->id)
            ->onQueue(config('saas.queues.delivery', 'delivery'));

        activity('saas.infrastructure')
            ->causedBy($request->user())
            ->performedOn($restaurant)
            ->withProperties([
                'tenant_id' => $restaurant->id,
                'domain' => $domain,
                'delivery_job_uuid' => $job->uuid,
                'apply' => (bool) $validated['apply'],
            ])
            ->log($validated['apply'] ? 'Tenant SSL installation queued.' : 'Tenant SSL preview queued.');

        return ApiResponse::success([
            'job' => [
                'uuid' => $job->uuid,
                'status' => $job->status,
                'tenant_id' => $restaurant->id,
                'tenant_name' => $restaurant->name,
                'domain' => $domain,
                'apply' => (bool) $validated['apply'],
            ],
        ], $validated['apply'] ? 'SSL installation queued.' : 'SSL preview queued.', 202);
    }

    public function release(Request $request): JsonResponse
    {
        $allowed = config('saas.deployment.allowed_branches', []);
        $validated = $request->validate([
            'target' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(config('saas.deployment.repositories', [])))],
            'branch' => ['required', 'string', 'max:120', \Illuminate\Validation\Rule::in($allowed)],
            'apply' => ['required', 'boolean'],
        ]);

        abort_unless(config('saas.deployment.enabled'), 403, 'Release controls are disabled on this server.');
        abort_if($validated['apply'] && ! config('saas.deployment.allow_apply'), 403, 'Release apply mode is disabled.');

        $run = SaasDeploymentRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'requested_by' => $request->user()?->id,
            'target' => $validated['target'],
            'branch' => $validated['branch'],
            'apply' => (bool) $validated['apply'],
        ]);
        RunSaasDeploymentJob::dispatch($run->id)->onQueue(config('saas.queues.delivery', 'delivery'));

        activity('saas.infrastructure')
            ->causedBy($request->user())
            ->withProperties(['run_uuid' => $run->uuid, 'target' => $run->target, 'branch' => $run->branch, 'apply' => $run->apply])
            ->log($run->apply ? 'Versioned platform release queued.' : 'Platform release preview queued.');

        return ApiResponse::success($run, $run->apply ? 'Release queued.' : 'Release preview queued.', 202);
    }

    public function releaseStatus(string $uuid): JsonResponse
    {
        return ApiResponse::success(SaasDeploymentRun::query()->where('uuid', $uuid)->firstOrFail());
    }

    public function terminal(Request $request): JsonResponse
    {
        $validated = $request->validate(['command' => ['required', 'string', 'max:80']]);
        $result = $this->terminal->execute($validated['command']);

        activity('saas.infrastructure')
            ->causedBy($request->user())
            ->withProperties(['command' => $validated['command'], 'exit_code' => $result['exit_code']])
            ->log('Audited terminal command executed.');

        return ApiResponse::success($result, $result['successful'] ? 'Command completed.' : 'Command failed.');
    }
}
