<?php

namespace Modules\Saas\Services\Infrastructure;

use Symfony\Component\Process\Process;

class SaasTerminalService
{
    public function configuration(): array
    {
        return [
            'enabled' => (bool) config('saas.deployment.terminal_enabled'),
            'commands' => collect($this->commands())->map(fn (array $command, string $key) => [
                'key' => $key,
                'label' => $command['label'],
                'description' => $command['description'],
                'target' => $command['target'],
                'mutating' => $command['mutating'],
            ])->values()->all(),
        ];
    }

    public function execute(string $key): array
    {
        abort_unless(config('saas.deployment.terminal_enabled'), 403, 'The audited terminal is disabled on this server.');
        $command = $this->commands()[$key] ?? null;
        abort_unless($command, 422, 'This terminal command is not allowed.');

        $root = (string) config('saas.deployment.repositories.'.$command['target'].'.root');
        abort_unless($root !== '' && is_dir($root.'/.git'), 500, ucfirst($command['target']).' repository is not configured.');

        $process = new Process($command['argv'], $root, timeout: max(5, min(120, (int) config('saas.deployment.terminal_timeout', 30))));
        $process->setOutputDisabled(false);
        $process->run();

        return [
            'command' => $key,
            'successful' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'output' => $this->bounded($process->getOutput()),
            'error' => $this->bounded($process->getErrorOutput()),
        ];
    }

    private function commands(): array
    {
        return [
            'backend_status' => ['label' => 'Backend · Git status', 'description' => 'Branch, revision and working-tree status.', 'target' => 'backend', 'mutating' => false, 'argv' => ['git', 'status', '--short', '--branch']],
            'backend_log' => ['label' => 'Backend · Recent commits', 'description' => 'Latest ten backend commits.', 'target' => 'backend', 'mutating' => false, 'argv' => ['git', 'log', '-10', '--oneline', '--decorate']],
            'backend_migrations' => ['label' => 'Backend · Migration status', 'description' => 'Shows applied and pending database migrations.', 'target' => 'backend', 'mutating' => false, 'argv' => ['php', 'artisan', 'migrate:status', '--no-interaction']],
            'backend_routes' => ['label' => 'Backend · Route summary', 'description' => 'Lists registered API routes.', 'target' => 'backend', 'mutating' => false, 'argv' => ['php', 'artisan', 'route:list', '--path=api', '--except-vendor']],
            'backend_optimize_clear' => ['label' => 'Backend · Clear caches', 'description' => 'Clears Laravel application caches.', 'target' => 'backend', 'mutating' => true, 'argv' => ['php', 'artisan', 'optimize:clear', '--no-interaction']],
            'frontend_status' => ['label' => 'Frontend · Git status', 'description' => 'Branch, revision and working-tree status.', 'target' => 'frontend', 'mutating' => false, 'argv' => ['git', 'status', '--short', '--branch']],
            'frontend_log' => ['label' => 'Frontend · Recent commits', 'description' => 'Latest ten frontend commits.', 'target' => 'frontend', 'mutating' => false, 'argv' => ['git', 'log', '-10', '--oneline', '--decorate']],
        ];
    }

    private function bounded(string $output): string
    {
        $output = preg_replace('#(https?://)[^/@\s]+@#i', '$1[redacted]@', trim($output)) ?? '';
        return mb_substr($output, 0, 50000);
    }
}
