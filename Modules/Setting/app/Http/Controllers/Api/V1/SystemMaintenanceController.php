<?php

namespace Modules\Setting\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrinterAssignment;
use Modules\Printer\Models\PrintJob;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Support\ApiResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemMaintenanceController extends Controller
{
    public function __construct(private readonly SettingServiceInterface $settings) {}

    public function health(): JsonResponse
    {
        $checks = [
            $this->databaseHealth(),
            $this->storageHealth(),
            $this->cacheHealth(),
            $this->queueHealth(),
            $this->reverbHealth(),
            $this->printJobsHealth(),
            $this->printAgentsHealth(),
            $this->printerRoutingHealth(),
            $this->laravelLogHealth(),
        ];

        $status = collect($checks)->contains(fn(array $check) => $check['status'] === 'error')
            ? 'error'
            : (collect($checks)->contains(fn(array $check) => $check['status'] === 'warning') ? 'warning' : 'ok');

        return ApiResponse::success([
            'status' => $status,
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->integer('limit', 200), 20), 1000);
        $level = strtolower((string) $request->query('level', ''));
        $query = trim((string) $request->query('query', ''));
        $date = $this->validatedLogDate($request);
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            return ApiResponse::success([
                'path' => $path,
                'size' => 0,
                'lines' => [],
                'message' => 'Laravel log file was not found.',
            ]);
        }

        $lines = $this->readRecentLogLines($path, $limit, $level, $query, $date);

        return ApiResponse::success([
            'path' => $path,
            'size' => File::size($path),
            'updated_at' => date('Y-m-d H:i:s', File::lastModified($path)),
            'lines' => $lines,
            'summary' => $this->logSummary($lines),
            'filters' => compact('level', 'query', 'date', 'limit'),
        ]);
    }

    public function downloadLogs(Request $request): StreamedResponse
    {
        $limit = min(max((int) $request->integer('limit', 1000), 20), 5000);
        $level = strtolower((string) $request->query('level', ''));
        $query = trim((string) $request->query('query', ''));
        $date = $this->validatedLogDate($request);
        $path = storage_path('logs/laravel.log');
        $lines = File::exists($path)
            ? $this->readRecentLogLines($path, $limit, $level, $query, $date)
            : [];
        $fileDate = $date ?: now()->toDateString();

        return response()->streamDownload(
            static function () use ($lines): void {
                echo implode(PHP_EOL, $lines);
                if ($lines !== []) {
                    echo PHP_EOL;
                }
            },
            "nexdine-laravel-{$fileDate}.log",
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function clearLogs(): JsonResponse
    {
        $path = storage_path('logs/laravel.log');
        if (! File::exists(dirname($path))) {
            File::makeDirectory(dirname($path), 0775, true);
        }

        File::put($path, '');

        return ApiResponse::success([
            'path' => $path,
            'cleared_at' => now()->toDateTimeString(),
        ], 'Laravel log cleared.');
    }

    public function run(string $action): JsonResponse
    {
        $result = match ($action) {
            'clear_cache' => $this->clearCache(),
            'repair_permissions' => $this->repairPermissions(),
            'restart_workers' => $this->restartWorkers(),
            'printer_recovery' => $this->printerRecovery(),
            'full_recovery' => $this->fullRecovery(),
            default => abort(404),
        };

        return ApiResponse::success($result, __('setting::settings.maintenance.completed'));
    }

    private function clearCache(): array
    {
        $commands = [
            'optimize:clear',
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'event:clear',
        ];

        foreach ($commands as $command) {
            Artisan::call($command);
        }

        $versionKey = makeCacheKey(['app', 'boot_data_version'], false);
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
        $this->settings->refreshSettingBinding();

        return [
            'actions' => $commands,
            'warnings' => [],
        ];
    }

    private function repairPermissions(): array
    {
        $paths = [
            storage_path(),
            base_path('bootstrap/cache'),
        ];

        $warnings = [];
        foreach ($paths as $path) {
            if (! File::exists($path)) {
                File::makeDirectory($path, 0775, true);
            }

            $this->chmodRecursive($path, 0775, $warnings);
        }

        $configuredCommand = trim((string) config('setting.maintenance.permission_command'));
        if ($configuredCommand !== '') {
            $this->runConfiguredCommand($configuredCommand, $warnings, 'permission_command');
        } else {
            $warnings[] = 'Owner change skipped. Configure SETTING_MAINTENANCE_PERMISSION_COMMAND for sudo chown/chmod on production.';
        }

        return [
            'actions' => [
                'chmod -R 775 storage bootstrap/cache',
                'ensure storage and bootstrap/cache directories',
            ],
            'warnings' => $warnings,
        ];
    }

    private function restartWorkers(): array
    {
        Artisan::call('queue:restart');

        $warnings = [];
        $configuredCommand = trim((string) config('setting.maintenance.supervisor_restart_command'));
        if ($configuredCommand !== '') {
            $this->runConfiguredCommand($configuredCommand, $warnings, 'supervisor_restart_command');
        } else {
            $warnings[] = 'Supervisor restart command skipped. Configure SETTING_MAINTENANCE_SUPERVISOR_RESTART_COMMAND if this server should restart supervisor from the app.';
        }

        return [
            'actions' => ['queue:restart'],
            'warnings' => $warnings,
        ];
    }

    private function printerRecovery(): array
    {
        $cacheResult = $this->clearCache();
        $workerResult = $this->restartWorkers();
        $warnings = [...$cacheResult['warnings'], ...$workerResult['warnings']];

        foreach ([
            storage_path('framework/cache/laravel-excel'),
            storage_path('framework/views'),
        ] as $path) {
            if (File::isDirectory($path)) {
                File::cleanDirectory($path);
            }
        }

        $configuredCommand = trim((string) config('setting.maintenance.printer_restart_command'));
        if ($configuredCommand !== '') {
            $this->runConfiguredCommand($configuredCommand, $warnings, 'printer_restart_command');
        } else {
            $warnings[] = 'Printer service restart skipped. Configure SETTING_MAINTENANCE_PRINTER_RESTART_COMMAND for the local/server print agent restart command.';
        }

        return [
            'actions' => [
                'clear Laravel/browser print render cache',
                'queue:restart',
                'clear framework temp/view files',
            ],
            'warnings' => $warnings,
        ];
    }

    private function fullRecovery(): array
    {
        $cache = $this->clearCache();
        $permissions = $this->repairPermissions();
        $printer = $this->printerRecovery();

        return [
            'actions' => [
                ...$cache['actions'],
                ...$permissions['actions'],
                ...$printer['actions'],
            ],
            'warnings' => [
                ...$cache['warnings'],
                ...$permissions['warnings'],
                ...$printer['warnings'],
            ],
        ];
    }

    private function chmodRecursive(string $path, int $mode, array &$warnings): void
    {
        try {
            @chmod($path, $mode);
            foreach (File::allFiles($path) as $file) {
                @chmod($file->getPathname(), $mode);
            }
            foreach (File::directories($path) as $directory) {
                $this->chmodRecursive($directory, $mode, $warnings);
            }
        } catch (\Throwable $exception) {
            $warnings[] = "Permission update skipped for {$path}: {$exception->getMessage()}";
        }
    }

    private function runConfiguredCommand(string $command, array &$warnings, string $label): void
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $warnings[] = "{$label} failed: ".trim($process->getErrorOutput() ?: $process->getOutput());
        }
    }

    private function databaseHealth(): array
    {
        try {
            DB::select('select 1');

            return $this->check('database', 'ok', 'Database connection is working.');
        } catch (\Throwable $exception) {
            return $this->check('database', 'error', 'Database connection failed.', [
                'error' => $exception->getMessage(),
                'action' => 'Check DB credentials and database service.',
            ]);
        }
    }

    private function storageHealth(): array
    {
        $paths = [
            'storage' => storage_path(),
            'bootstrap_cache' => base_path('bootstrap/cache'),
            'logs' => storage_path('logs'),
        ];

        $blocked = [];
        foreach ($paths as $label => $path) {
            if (! File::isDirectory($path) || ! is_writable($path)) {
                $blocked[$label] = $path;
            }
        }

        return empty($blocked)
            ? $this->check('storage', 'ok', 'Storage, logs, and bootstrap/cache are writable.')
            : $this->check('storage', 'error', 'Some writable paths are blocked.', [
                'paths' => $blocked,
                'action' => 'Run Repair permissions.',
            ]);
    }

    private function cacheHealth(): array
    {
        try {
            $key = 'system-health:'.uniqid();
            Cache::put($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $this->check('cache', $ok ? 'ok' : 'warning', $ok ? 'Cache read/write is working.' : 'Cache write did not return expected value.', [
                'action' => $ok ? null : 'Run Clear cache.',
            ]);
        } catch (\Throwable $exception) {
            return $this->check('cache', 'warning', 'Cache check failed.', [
                'error' => $exception->getMessage(),
                'action' => 'Run Clear cache and verify cache driver.',
            ]);
        }
    }

    private function queueHealth(): array
    {
        $details = [
            'connection' => config('queue.default'),
        ];

        foreach (['jobs', 'failed_jobs'] as $table) {
            $details[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        $status = (($details['failed_jobs'] ?? 0) > 0 || ($details['jobs'] ?? 0) > 100) ? 'warning' : 'ok';

        return $this->check('queue', $status, $status === 'ok' ? 'Queue tables look healthy.' : 'Queue needs attention.', [
            ...$details,
            'action' => $status === 'ok' ? null : 'Run Restart workers and review failed jobs.',
        ]);
    }

    private function reverbHealth(): array
    {
        $key = config('broadcasting.connections.reverb.key') ?: env('REVERB_APP_KEY');
        $secret = config('broadcasting.connections.reverb.secret') ?: env('REVERB_APP_SECRET');
        $host = config('broadcasting.connections.reverb.options.host') ?: env('REVERB_HOST');

        $configured = filled($key) && filled($secret) && filled($host);

        return $this->check('reverb', $configured ? 'ok' : 'warning', $configured ? 'Reverb configuration is present.' : 'Reverb is not fully configured.', [
            'broadcast_driver' => config('broadcasting.default'),
            'host' => $host,
            'app_key_present' => filled($key),
            'secret_present' => filled($secret),
            'action' => $configured ? null : 'Check Reverb env keys and restart Reverb service.',
        ]);
    }

    private function printJobsHealth(): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return $this->check('print_jobs', 'warning', 'print_jobs table was not found.');
        }

        $pending = PrintJob::query()->where('status', PrintJobStatus::Pending)->count();
        $failed = PrintJob::query()->where('status', PrintJobStatus::Failed)->count();
        $successToday = PrintJob::query()
            ->where('status', PrintJobStatus::Success)
            ->whereDate('completed_at', today())
            ->count();

        $status = ($failed > 0 || $pending > 20) ? 'warning' : 'ok';

        return $this->check('print_jobs', $status, $status === 'ok' ? 'Print jobs are moving normally.' : 'Print queue needs review.', [
            'pending' => $pending,
            'failed' => $failed,
            'success_today' => $successToday,
            'action' => $status === 'ok' ? null : 'Open Print Jobs, retry failed jobs, or run Printer recovery.',
        ]);
    }

    private function printAgentsHealth(): array
    {
        if (! Schema::hasTable('print_agents')) {
            return $this->check('print_agents', 'warning', 'print_agents table was not found.');
        }

        $total = PrintAgent::query()->count();
        $online = PrintAgent::query()
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->count();

        return $this->check('print_agents', $total > 0 && $online === 0 ? 'warning' : 'ok', $online > 0 ? 'At least one print agent is online.' : 'No print agent heartbeat in the last 2 minutes.', [
            'total' => $total,
            'online' => $online,
            'action' => $online > 0 ? null : 'Check Windows/Ubuntu agent service, socket/poll mode, and agent secret.',
        ]);
    }

    private function printerRoutingHealth(): array
    {
        if (! Schema::hasTable('printers') || ! Schema::hasTable('printer_assignments')) {
            return $this->check('printer_routing', 'warning', 'Printer routing tables are missing.');
        }

        $activePrinters = Printer::query()->where('is_active', true)->count();
        $assignments = PrinterAssignment::query()->with('printer')->get();
        $missingPrinter = $assignments->filter(fn(PrinterAssignment $assignment) => ! $assignment->printer)->count();
        $withoutAgent = $assignments->filter(function (PrinterAssignment $assignment) {
            $options = $assignment->printer?->options ?? [];

            return blank($options['agent_id'] ?? null);
        })->count();

        $status = ($missingPrinter > 0 || $withoutAgent > 0 || $activePrinters === 0) ? 'warning' : 'ok';

        return $this->check('printer_routing', $status, $status === 'ok' ? 'Printer routing has active printers and assigned agents.' : 'Printer routing needs attention.', [
            'active_printers' => $activePrinters,
            'assignments' => $assignments->count(),
            'assignments_missing_printer' => $missingPrinter,
            'assignments_without_agent' => $withoutAgent,
            'action' => $status === 'ok' ? null : 'Open printer assignments and verify each printer has options.agent_id.',
        ]);
    }

    private function laravelLogHealth(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! File::exists($path)) {
            return $this->check('laravel_log', 'ok', 'Laravel log file does not exist yet.');
        }

        $errors = $this->readRecentLogLines($path, 20, 'error', '');
        $status = count($errors) > 0 ? 'warning' : 'ok';

        return $this->check('laravel_log', $status, $status === 'ok' ? 'No recent error lines found in Laravel log tail.' : 'Recent error lines found in Laravel log.', [
            'size' => File::size($path),
            'updated_at' => date('Y-m-d H:i:s', File::lastModified($path)),
            'recent_errors' => count($errors),
            'action' => $status === 'ok' ? null : 'Use Laravel Logs filter below and clear only after issue is fixed.',
        ]);
    }

    private function readRecentLogLines(
        string $path,
        int $limit,
        string $level,
        string $query,
        string $date = '',
    ): array
    {
        $size = File::size($path);
        $chunkSize = min($size, 2 * 1024 * 1024);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        if ($chunkSize > 0) {
            fseek($handle, -$chunkSize, SEEK_END);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, fn(string $line) => trim($line) !== ''));

        $filtered = array_filter($lines, function (string $line) use ($level, $query, $date) {
            if ($date !== '' && ! str_contains($line, "[{$date} ")) {
                return false;
            }
            if ($level !== '' && ! str_contains(strtolower($line), ".{$level}:")) {
                return false;
            }

            return $query === '' || str_contains(strtolower($line), strtolower($query));
        });

        return array_values(array_slice($filtered, -$limit));
    }

    private function validatedLogDate(Request $request): string
    {
        $date = trim((string) $request->query('date', ''));
        if ($date === '') {
            return '';
        }

        $validated = $request->validate(['date' => ['date_format:Y-m-d']]);

        return (string) ($validated['date'] ?? '');
    }

    private function logSummary(array $lines): array
    {
        $summary = ['error' => 0, 'warning' => 0, 'info' => 0, 'debug' => 0];
        foreach ($lines as $line) {
            $normalised = strtolower((string) $line);
            foreach (array_keys($summary) as $level) {
                if (str_contains($normalised, ".{$level}:")) {
                    $summary[$level]++;
                    break;
                }
            }
        }

        return $summary;
    }

    private function check(string $key, string $status, string $message, array $details = []): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'message' => $message,
            'details' => array_filter($details, fn($value) => ! is_null($value)),
        ];
    }
}
