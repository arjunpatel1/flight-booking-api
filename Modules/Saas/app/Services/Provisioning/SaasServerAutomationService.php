<?php

namespace Modules\Saas\Services\Provisioning;

use Symfony\Component\Process\Process;

class SaasServerAutomationService
{
    public function health(): array
    {
        $automation = config('saas.server_automation');
        $scripts = $automation['scripts'] ?? [];
        $supervisorctl = (string) data_get($automation, 'processes.supervisorctl', '/usr/bin/supervisorctl');
        $tenantScript = $scripts['tenant_nginx'] ?? null;
        $applyBlockers = collect([
            ($automation['enabled'] ?? false) ? null : 'Server automation is disabled.',
            ($automation['allow_apply'] ?? false) ? null : 'Apply mode is disabled.',
            $tenantScript && is_file($tenantScript) ? null : 'Tenant nginx SSL script is missing.',
            $tenantScript && is_executable($tenantScript) ? null : 'Tenant nginx SSL script is not executable.',
            filled($automation['frontend_root'] ?? null) ? null : 'Frontend build path is not configured.',
            filled($automation['ssl_email'] ?? null) ? null : 'Certificate notices email is not configured.',
        ])->filter()->values()->all();

        return [
            'automation' => [
                'enabled' => (bool) ($automation['enabled'] ?? false),
                'allow_apply' => (bool) ($automation['allow_apply'] ?? false),
                'use_sudo' => (bool) ($automation['use_sudo'] ?? false),
                'default_web_server' => $this->webServer(),
                'timeout' => (int) ($automation['timeout'] ?? 300),
                'apply_ready' => $applyBlockers === [],
                'apply_blockers' => $applyBlockers,
            ],
            'web_servers' => [
                'apache' => $this->scriptHealth($scripts['apache'] ?? null),
                'nginx' => $this->scriptHealth($scripts['nginx'] ?? null),
                'tenant_nginx' => $this->scriptHealth($scripts['tenant_nginx'] ?? null),
            ],
            'processes' => [
                'supervisorctl' => [
                    'path' => $supervisorctl,
                    'available' => is_file($supervisorctl) && is_executable($supervisorctl),
                    'reverb_program' => data_get($automation, 'processes.reverb_program'),
                    'worker_program' => data_get($automation, 'processes.worker_program'),
                ],
                'reverb' => [
                    'broadcast_connection' => config('broadcasting.default'),
                    'app_id' => filled(config('reverb.apps.apps.0.app_id')) ? 'configured' : 'missing',
                    'host' => config('reverb.servers.reverb.hostname'),
                    'port' => config('reverb.servers.reverb.port'),
                ],
                'queues' => config('saas.queues'),
            ],
            'integrations' => [
                'billing' => [
                    'default_gateway' => config('saas.billing.gateway'),
                    'razorpay' => filled(config('saas.billing.razorpay.key_id')) && filled(config('saas.billing.razorpay.key_secret')),
                    'stripe' => filled(config('saas.billing.stripe.secret')),
                ],
                'delivery' => [
                    'waiter_app_webhook' => filled(config('saas.waiter_app_build.webhook_url')),
                ],
                'notifications' => [
                    'alert_recipient' => filled(config('saas.alerts.recipient')),
                    'channels' => config('saas.alerts.channels', []),
                    'mail_from' => filled(config('mail.from.address')),
                ],
                'restore' => [
                    'execution_enabled' => (bool) config('saas.restore.execution_enabled'),
                    'environment_allowed' => in_array(app()->environment(), config('saas.restore.allowed_environments', []), true),
                    'environment' => app()->environment(),
                ],
            ],
        ];
    }

    public function configureWebServerSsl(array $data): array
    {
        if (($data['mode'] ?? null) === 'tenant_ssl') {
            return $this->configureTenantSsl($data);
        }

        $webServer = $this->webServer($data['web_server'] ?? null);
        $script = data_get(config('saas.server_automation'), "scripts.{$webServer}");
        abort_unless($script && is_file($script), 500, "SaaS {$webServer} automation script is missing.");

        return $this->runAutomation($script, $data, $webServer);
    }

    public function configureTenantSsl(array $data): array
    {
        $script = (string) data_get(
            config('saas.server_automation'),
            'scripts.tenant_nginx',
            base_path('Modules/Saas/deploy/nginx-tenant-ssl.sh')
        );
        $apply = (bool) ($data['apply'] ?? false);

        abort_unless(is_file($script), 500, 'Tenant Nginx SSL automation script is missing.');
        abort_if(
            $apply && ! config('saas.server_automation.enabled'),
            403,
            'Server automation is disabled. Enable SAAS_SERVER_AUTOMATION_ENABLED=true before applying changes.'
        );
        abort_if(
            $apply && ! config('saas.server_automation.allow_apply'),
            403,
            'Apply mode is disabled. Enable SAAS_SERVER_AUTOMATION_ALLOW_APPLY=true after server sudoers are configured.'
        );

        $domain = $this->cleanDomain((string) ($data['tenant_domain'] ?? ''));
        abort_unless(
            $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),
            422,
            'A valid tenant domain is required.'
        );

        $domainSuffix = strtolower(trim((string) config('saas.server_automation.tenant_domain_suffix')));
        abort_if(
            $apply && ($domainSuffix === '' || ! str_ends_with($domain, '.'.$domainSuffix)),
            422,
            'The tenant domain is outside the configured NexDine domain suffix.'
        );

        // Do not allow HTTP payloads to select privileged filesystem paths or
        // certificate identities. Apply mode only uses server configuration;
        // the root-owned helper independently enforces the same values.
        $frontendRoot = (string) config('saas.server_automation.frontend_root');
        $certificateEmail = (string) config('saas.server_automation.ssl_email');
        abort_if($apply && ($frontendRoot === '' || $certificateEmail === ''), 500, 'Tenant SSL server configuration is incomplete.');

        $command = [];
        if ($apply && config('saas.server_automation.use_sudo')) {
            $command[] = 'sudo';
        }
        array_push(
            $command,
            $script,
            '--tenant-domain',
            $domain,
            '--frontend-root',
            $apply ? $frontendRoot : (string) (($data['frontend_root'] ?? null) ?: $frontendRoot),
            '--email',
            $apply ? $certificateEmail : (string) (($data['email'] ?? null) ?: $certificateEmail)
        );
        if ($apply) {
            $command[] = '--apply';
        }

        $process = new Process($command, base_path(), timeout: (int) config('saas.server_automation.timeout', 300));
        $process->run();

        return [
            'web_server' => 'nginx',
            'mode' => 'tenant_ssl',
            'tenant_domain' => $domain,
            'applied' => $apply,
            'successful' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'command' => $this->redactedCommand($command),
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
            'automation_enabled' => (bool) config('saas.server_automation.enabled'),
            'allow_apply' => (bool) config('saas.server_automation.allow_apply'),
        ];
    }

    public function configureApacheSsl(array $data): array
    {
        return $this->runAutomation(
            (string) data_get(config('saas.server_automation'), 'scripts.apache', base_path('Modules/Saas/deploy/apache-saas-ssl.sh')),
            $data,
            'apache'
        );
    }

    private function runAutomation(string $script, array $data, string $webServer): array
    {
        $apply = (bool) ($data['apply'] ?? false);

        abort_unless(is_file($script), 500, "SaaS {$webServer} automation script is missing.");

        abort_if(
            $apply && ! config('saas.server_automation.enabled'),
            403,
            'Server automation is disabled. Enable SAAS_SERVER_AUTOMATION_ENABLED=true before applying changes.'
        );

        abort_if(
            $apply && ! config('saas.server_automation.allow_apply'),
            403,
            'Apply mode is disabled. Enable SAAS_SERVER_AUTOMATION_ALLOW_APPLY=true after server sudoers are configured.'
        );

        $command = $this->command($script, $data, $apply);
        $process = new Process($command, base_path(), timeout: (int) config('saas.server_automation.timeout', 300));
        $process->run();

        return [
            'web_server' => $webServer,
            'applied' => $apply,
            'successful' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'command' => $this->redactedCommand($command),
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
            'automation_enabled' => (bool) config('saas.server_automation.enabled'),
            'allow_apply' => (bool) config('saas.server_automation.allow_apply'),
        ];
    }

    private function command(string $script, array $data, bool $apply): array
    {
        $command = [];
        if ($apply && config('saas.server_automation.use_sudo')) {
            $command[] = 'sudo';
        }

        array_push(
            $command,
            $script,
            '--api-domain',
            $this->cleanDomain($data['api_domain']),
            '--root-domain',
            $this->cleanDomain($data['root_domain']),
            '--tenant-domains',
            $this->cleanTenantDomains($data['tenant_domains']),
            '--frontend-root',
            $data['frontend_root'] ?: config('saas.server_automation.frontend_root'),
            '--api-root',
            $data['api_root'] ?: config('saas.server_automation.api_root'),
            '--email',
            $data['email']
        );

        if ((bool) ($data['install_packages'] ?? false)) {
            $command[] = '--install-packages';
        }

        if ($apply) {
            $command[] = '--apply';
        }

        return $command;
    }

    private function cleanDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    private function cleanTenantDomains(string $domains): string
    {
        return collect(explode(',', $domains))
            ->map(fn (string $domain) => $this->cleanDomain($domain))
            ->filter()
            ->unique()
            ->implode(',');
    }

    private function redactedCommand(array $command): string
    {
        return collect($command)
            ->map(fn (string $part) => str_contains($part, ' ') ? escapeshellarg($part) : $part)
            ->join(' ');
    }

    private function webServer(?string $webServer = null): string
    {
        $selected = strtolower(trim($webServer ?: config('saas.server_automation.web_server', 'apache')));

        return in_array($selected, ['apache', 'nginx'], true) ? $selected : 'apache';
    }

    private function scriptHealth(?string $script): array
    {
        return [
            'path' => $script,
            'exists' => filled($script) && is_file((string) $script),
            'executable' => filled($script) && is_executable((string) $script),
        ];
    }
}
