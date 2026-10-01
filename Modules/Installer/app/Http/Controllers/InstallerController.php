<?php

namespace Modules\Installer\Http\Controllers;

use Artisan;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Jackiedo\DotenvEditor\Facades\DotenvEditor;
use Modules\Core\Http\Controllers\Controller;
use Modules\User\Enums\DefaultRole;
use Modules\User\Enums\GenderType;
use Modules\User\Models\User;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class InstallerController extends Controller
{
    private string $tracker;

    private array $commandEnvironment = [];

    public function __construct()
    {
        $this->tracker = storage_path('framework/install.json');
    }

    /**
     * Welcome step
     *
     * @throws FileNotFoundException
     */
    public function welcome(): View
    {
        File::delete($this->tracker);
        session()->forget('installer');

        $this->markStep('welcome');

        return view('installer.welcome');
    }

    /**
     * @throws FileNotFoundException
     */
    private function markStep(string $step): void
    {
        $data = $this->getSteps();
        $data[$step] = true;

        File::put($this->tracker, json_encode($data));
        session()->put("installer.$step", true);
        session()->save();
    }

    /**
     * @throws FileNotFoundException
     */
    private function getSteps(): array
    {
        if (File::exists($this->tracker)) {
            return json_decode(File::get($this->tracker), true) ?? [];
        }

        return [];
    }

    /**
     * System requirements check
     *
     * @throws FileNotFoundException
     */
    public function requirements(): View|RedirectResponse
    {
        if (! $this->stepDone('welcome')) {
            return redirect()->route('installer.welcome');
        }

        $nodeCheck = $this->detectNodeInstallation();

        $requirements = [
            'PHP >= 8.3' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'ctype' => extension_loaded('ctype'),
            'curl' => extension_loaded('curl'),
            'dom' => extension_loaded('dom'),
            'fileinfo' => extension_loaded('fileinfo'),
            'filter' => extension_loaded('filter'),
            'hash' => extension_loaded('hash'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
            'pcre' => extension_loaded('pcre'),
            'pdo' => extension_loaded('pdo'),
            'session' => extension_loaded('session'),
            'tokenizer' => extension_loaded('tokenizer'),
            'xml' => extension_loaded('xml'),
            'intl' => extension_loaded('intl'),
            'gd' => extension_loaded('gd'),

            'Node.js >= 18' => $nodeCheck['node'] !== 'unknown',
            'npm installed' => $nodeCheck['npm'] !== 'unknown',
            'Puppeteer installed' => $nodeCheck['puppeteer'] !== 'unknown',
        ];

        if (! function_exists('shell_exec')) {
            session()->flash(
                'error',
                'This server does not allow command execution (shell_exec disabled). Node.js and Puppeteer cannot run here.'
            );
        }

        $this->markStep('requirements');

        $listNotExtensions = [
            'PHP >= 8.3',
            'Node.js >= 18',
            'npm installed',
            'Puppeteer installed',
        ];

        return view('installer.requirements', compact(
            'requirements',
            'listNotExtensions'
        ));
    }

    /**
     * @throws FileNotFoundException
     */
    private function stepDone(string $step): bool
    {
        return $this->getSteps()[$step] ?? false;
    }

    private function detectNodeInstallation(): array
    {
        $result = [
            'node' => 'unknown',
            'npm' => 'unknown',
            'puppeteer' => 'unknown',
        ];

        if (getenv('NODE_VERSION')) {
            $result['node'] = 'likely';
        }

        $paths = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
            ]
            : [
                '/usr/bin/node',
                '/usr/local/bin/node',
                '/opt/homebrew/bin/node',
                '/snap/bin/node',
            ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $result['node'] = 'installed';
                break;
            }
        }

        $npmPaths = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\nodejs\\npm.cmd',
            ]
            : [
                '/usr/bin/npm',
                '/usr/local/bin/npm',
                '/opt/homebrew/bin/npm',
            ];

        foreach ($npmPaths as $path) {
            if (is_file($path)) {
                $result['npm'] = 'installed';
                break;
            }
        }

        $possibleNodeModules = [
            base_path('node_modules/puppeteer'),
            base_path('node_modules/puppeteer-core'),
        ];

        foreach ($possibleNodeModules as $dir) {
            if (is_dir($dir)) {
                $result['puppeteer'] = 'installed';
                break;
            }
        }

        return $result;
    }

    /**
     * Permissions check
     *
     * @throws FileNotFoundException
     */
    public function permissions(): View|RedirectResponse
    {
        if (! $this->stepDone('requirements')) {
            return redirect()->route('installer.requirements');
        }

        $paths = [
            '.env' => base_path('.env'),
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $permissions = array_map(function ($path) {
            return is_writable($path);
        }, $paths);

        $this->markStep('permissions');

        return view('installer.permissions', compact('permissions'));
    }

    /**
     * Database setup
     *
     * @throws FileNotFoundException
     */
    public function database(Request $request): View|RedirectResponse
    {
        if (! $this->stepDone('permissions')) {
            return redirect()->route('installer.permissions');
        }

        $connections = [
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
        ];

        if ($request->isMethod('post')) {
            $this->prepareLongRunningInstallRequest();

            $data = $request->validate([
                'db_connection' => ['required', Rule::in(array_keys($connections))],
                'db_host' => 'required|string|max:255',
                'db_port' => 'required|numeric|min:1|max:65535',
                'db_name' => 'required|string|max:255',
                'db_user' => 'required|string|max:255',
                'db_pass' => 'nullable|string|max:255',
            ]);

            if (! File::exists(base_path('.env'))) {
                File::copy(base_path('.env.example'), base_path('.env'));
            }

            $this->storeDatabaseConfig($data, $request->boolean('with_demo'));

            config([
                'database.default' => $data['db_connection'],
                "database.connections.{$data['db_connection']}.host" => $data['db_host'],
                "database.connections.{$data['db_connection']}.port" => $data['db_port'],
                "database.connections.{$data['db_connection']}.database" => $data['db_name'],
                "database.connections.{$data['db_connection']}.username" => $data['db_user'],
                "database.connections.{$data['db_connection']}.password" => $data['db_pass'],
                'app.seed_demo_data' => $request->boolean('with_demo'),
            ]);

            DB::purge();
            DB::reconnect();

            try {
                DB::connection()->getPdo();
            } catch (Exception $e) {
                return back()->withErrors([
                    'db_error' => 'Database connection failed: '.$e->getMessage(),
                ]);
            }

            try {
                $this->runInstallerCommand('module:migrate', ['-a' => true, '--force' => true]);
                $this->runInstallerCommand('module:seed', ['-a' => true, '--force' => true]);
            } catch (Throwable $e) {
                Log::error('Installer database setup failed.', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                return back()->with('db_error', 'Database setup failed: '.$e->getMessage());
            }

            $this->markStep('database');

            return redirect()->route('installer.admin');
        }

        return view('installer.database', compact('connections'));
    }

    private function storeDatabaseConfig(array $data, bool $withDemo): void
    {
        $steps = $this->getSteps();
        $steps['database_config'] = [
            'DB_CONNECTION' => $data['db_connection'],
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => $data['db_port'],
            'DB_DATABASE' => $data['db_name'],
            'DB_USERNAME' => $data['db_user'],
            'DB_PASSWORD' => $data['db_pass'] ?? '',
            'APP_SEED_DEMO_DATA' => $withDemo ? 'true' : 'false',
        ];

        File::put($this->tracker, json_encode($steps));
        session()->put('installer.database_config', $steps['database_config']);
        session()->save();

        $this->commandEnvironment = $steps['database_config'];
    }

    private function getDatabaseConfig(): array
    {
        return $this->getSteps()['database_config']
            ?? session('installer.database_config')
            ?? [];
    }

    private function applyStoredDatabaseConfig(): void
    {
        $databaseConfig = $this->getDatabaseConfig();

        if ($databaseConfig === []) {
            return;
        }

        $connection = $databaseConfig['DB_CONNECTION'];

        config([
            'database.default' => $connection,
            "database.connections.$connection.host" => $databaseConfig['DB_HOST'],
            "database.connections.$connection.port" => $databaseConfig['DB_PORT'],
            "database.connections.$connection.database" => $databaseConfig['DB_DATABASE'],
            "database.connections.$connection.username" => $databaseConfig['DB_USERNAME'],
            "database.connections.$connection.password" => $databaseConfig['DB_PASSWORD'],
            'app.seed_demo_data' => $databaseConfig['APP_SEED_DEMO_DATA'] === 'true',
        ]);

        DB::purge();
        DB::reconnect();

        $this->commandEnvironment = $databaseConfig;
    }

    private function prepareLongRunningInstallRequest(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');
        @ignore_user_abort(true);
    }

    /**
     * @throws RuntimeException
     */
    private function runInstallerCommand(string $command, array $parameters = []): void
    {
        $phpBinary = $this->resolvePhpBinary();

        // If we cannot find a PHP CLI binary, fall back to in-process Artisan call.
        // This is slower and blocks the request, but works on shared hosting.
        if ($phpBinary === null) {
            $exitCode = Artisan::call($command, $parameters);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    "Command [$command] failed with exit code [$exitCode].\n"
                    .Artisan::output()
                );
            }

            return;
        }

        $process = new Process(
            array_merge([
                $phpBinary,
                base_path('artisan'),
                $command,
            ], $this->normalizeCommandParameters($parameters)),
            base_path(),
            $this->commandEnvironment ?: null
        );

        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                trim($process->getErrorOutput() ?: $process->getOutput())
                    ?: "Command [$command] failed with exit code [{$process->getExitCode()}]."
            );
        }
    }

    private function resolvePhpBinary(): ?string
    {
        if (! empty(PHP_BINARY) && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // Try common paths
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['php.exe', 'C:\\php\\php.exe', 'C:\\xampp\\php\\php.exe']
            : ['/usr/bin/php', '/usr/local/bin/php', '/opt/alt/php83/usr/bin/php', '/opt/php83/bin/php', 'php'];

        foreach ($candidates as $candidate) {
            if (shell_exec("$candidate -v >/dev/null 2>&1; echo \$?") === '0') {
                return $candidate;
            }
        }

        // Last resort: try `which php`
        if (function_exists('shell_exec')) {
            $which = trim((string) shell_exec('which php 2>/dev/null'));
            if ($which !== '') {
                return $which;
            }
        }

        return null;
    }

    private function normalizeCommandParameters(array $parameters): array
    {
        $arguments = [];

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $arguments[] = (string) $value;

                continue;
            }

            if ($value === true) {
                $arguments[] = (string) $key;

                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            $arguments[] = str_starts_with((string) $key, '--')
                ? $key.'='.$value
                : (string) $key;
        }

        return $arguments;
    }

    /**
     * Admin user setup
     *
     * @throws FileNotFoundException
     */
    public function admin(Request $request): View|RedirectResponse
    {
        if (! $this->stepDone('database')) {
            return redirect()->route('installer.database');
        }

        $this->applyStoredDatabaseConfig();

        if ($request->isMethod('post')) {
            $admin = User::query()
                ->withTrashed()
                ->whereKey(1)
                ->first();

            $data = $request->validate([
                'name' => 'required|string',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->ignore($admin?->id),
                ],
                'password' => [
                    'required',
                    'string',
                    Password::min(8)->max(20)->mixedCase()->numbers()->symbols(),
                    'confirmed',
                ],
            ]);

            try {
                Artisan::call('permission:sync-permissions');
                Artisan::call('permission:sync-default-roles', ['--force' => true]);

                DB::transaction(function () use ($admin, $data) {
                    $admin ??= new User;

                    if (! $admin->exists) {
                        $admin->id = 1;
                    }

                    if (method_exists($admin, 'restore') && $admin->trashed()) {
                        $admin->restore();
                    }

                    $admin->forceFill([
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'username' => explode('@', $data['email'])[0],
                        'password' => bcrypt($data['password']),
                        'gender' => GenderType::Male,
                        'is_active' => true,
                    ])->save();

                    $admin->syncRoles([DefaultRole::SuperAdmin->value]);
                });
            } catch (Throwable $e) {
                Log::error('Installer admin setup failed.', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                return back()
                    ->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors([
                        'admin_error' => 'Admin setup failed: '.$e->getMessage(),
                    ]);
            }

            if (! $this->superAdminExists()) {
                return back()
                    ->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors([
                        'admin_error' => 'Admin setup failed: super admin user was not created.',
                    ]);
            }

            $this->markStep('admin');

            return redirect()->route('installer.finish');
        }

        return view('installer.admin');
    }

    /**
     * Finish installer
     *
     * @throws FileNotFoundException
     */
    public function finish(): View|RedirectResponse
    {
        if (! $this->stepDone('admin')) {
            return redirect()->route('installer.admin');
        }

        $this->applyStoredDatabaseConfig();

        if (! $this->superAdminExists()) {
            return redirect()
                ->route('installer.admin')
                ->withErrors([
                    'admin_error' => 'Please create the super admin account before completing installation.',
                ]);
        }

        $databaseConfig = $this->getDatabaseConfig();

        app()->terminating(function () use ($databaseConfig) {
            DotenvEditor::setKeys(array_merge($databaseConfig, [
                'APP_INSTALLED' => 'true',
                'APP_URL' => request()->getSchemeAndHttpHost(),
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
            ]))->save();

            Artisan::call('key:generate', ['--force' => true]);
            Artisan::call('storage:link');
            Artisan::call('cache:clear');
            Artisan::call('config:clear');

            File::delete($this->tracker);
            session()->forget('installer');
        });

        $frontendUrl = rtrim((string) (env('FRONTEND_URL') ?: url('/')), '/');

        return view('installer.finish', [
            'loginUrl' => "{$frontendUrl}/auth/login",
        ]);
    }

    private function superAdminExists(): bool
    {
        return User::query()
            ->whereKey(1)
            ->whereHas(
                'roles',
                fn ($query) => $query->where('name', DefaultRole::SuperAdmin->value)
            )
            ->exists();
    }
}
