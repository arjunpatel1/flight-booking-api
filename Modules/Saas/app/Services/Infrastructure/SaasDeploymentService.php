<?php

namespace Modules\Saas\Services\Infrastructure;

use Modules\Saas\Models\SaasDeploymentRun;
use Symfony\Component\Process\Process;

class SaasDeploymentService
{
    public function configuration(): array
    {
        $repositories = collect(config('saas.deployment.repositories', []))->map(fn (array $repository, string $key) => [
            'key' => $key,
            'label' => $repository['label'] ?? ucfirst($key),
            'configured' => filled($repository['root'] ?? null) && is_dir(($repository['root'] ?? '').'/.git'),
        ])->values()->all();

        return [
            'enabled' => (bool) config('saas.deployment.enabled'),
            'allow_apply' => (bool) config('saas.deployment.allow_apply'),
            'repository_configured' => collect($repositories)->contains('configured', true),
            'repositories' => $repositories,
            'allowed_branches' => config('saas.deployment.allowed_branches', []),
            'key_fingerprint' => config('saas.deployment.key_fingerprint'),
        ];
    }

    public function execute(SaasDeploymentRun $run): array
    {
        abort_unless(config('saas.deployment.enabled'), 403, 'Release controls are disabled on this server.');
        abort_if($run->apply && ! config('saas.deployment.allow_apply'), 403, 'Release apply mode is disabled.');

        $repository = config('saas.deployment.repositories.'.$run->target);
        abort_unless(is_array($repository), 422, 'Unknown release repository target.');
        $root = (string) ($repository['root'] ?? '');
        $script = (string) config('saas.deployment.script');
        abort_unless($root !== '' && is_dir($root.'/.git'), 500, 'The release repository is not configured.');
        abort_unless(is_file($script) && is_executable($script), 500, 'The release control script is unavailable.');

        $command = [$script, '--repo-root', $root, '--branch', $run->branch];
        if ($run->apply) $command[] = '--apply';

        $environment = [];
        if (filled($repository['post_deploy_script'] ?? null)) {
            $environment['SAAS_POST_DEPLOY_SCRIPT'] = (string) $repository['post_deploy_script'];
        }
        $process = new Process($command, $root, $environment, timeout: (int) config('saas.deployment.timeout', 900));
        $process->run();
        $output = $this->redact(trim($process->getOutput()));
        $error = $this->redact(trim($process->getErrorOutput()));

        return [
            'successful' => $process->isSuccessful(),
            'output' => $output,
            'error' => $error,
            'previous_revision' => $this->value($output, 'current_revision'),
            'new_revision' => $this->value($output, 'new_revision'),
        ];
    }

    private function value(string $output, string $key): ?string
    {
        return preg_match('/^'.preg_quote($key, '/').'=([a-f0-9]{40,64})$/m', $output, $matches)
            ? $matches[1]
            : null;
    }

    private function redact(string $output): string
    {
        return preg_replace('#(https?://)[^/@\s]+@#i', '$1[redacted]@', $output) ?? $output;
    }
}
