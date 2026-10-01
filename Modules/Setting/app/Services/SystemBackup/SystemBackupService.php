<?php

namespace Modules\Setting\Services\SystemBackup;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\Setting\Models\SystemBackup;
use Modules\Setting\Models\SystemRestore;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SystemBackupService implements SystemBackupServiceInterface
{
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        return SystemBackup::query()
            ->filters($filters)
            ->sortBy($sorts)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function createDatabaseBackup(): SystemBackup
    {
        $backup = SystemBackup::query()->create([
            'type' => 'database',
            'status' => 'processing',
            'disk' => 'local',
            'started_at' => now(),
            'meta' => [
                'connection' => config('database.default'),
                'database' => config('database.connections.' . config('database.default') . '.database'),
            ],
        ]);

        try {
            $path = sprintf(
                'backups/database/%s-%s.sql',
                now()->format('YmdHis'),
                str()->lower(str()->random(8))
            );

            $absolutePath = Storage::disk('local')->path($path);
            File::ensureDirectoryExists(dirname($absolutePath));

            $this->dumpDatabase($absolutePath);

            $backup->update([
                'status' => 'success',
                'path' => $path,
                'size' => File::size($absolutePath),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            report($exception);
        }

        return $backup->refresh();
    }

    public function getRestores(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        return SystemRestore::query()
            ->with(['backup', 'safetyBackup'])
            ->filters($filters)
            ->sortBy($sorts)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function restoreDatabaseBackup(SystemBackup $backup, ?string $reason = null): SystemRestore
    {
        $restore = SystemRestore::query()->create([
            'system_backup_id' => $backup->id,
            'status' => 'processing',
            'reason' => $reason,
            'started_at' => now(),
            'meta' => [
                'source_disk' => $backup->disk,
                'source_path' => $backup->path,
            ],
        ]);

        try {
            $this->assertRestorable($backup);

            // A safety snapshot gives operators a direct rollback point before destructive restore work starts.
            $safetyBackup = $this->createDatabaseBackup();

            if ($safetyBackup->status !== 'success') {
                throw new RuntimeException(__('setting::settings.restores.safety_backup_failed'));
            }

            $restore->update(['safety_backup_id' => $safetyBackup->id]);

            $this->importDatabase(Storage::disk($backup->disk)->path($backup->path));

            DB::purge(config('database.default'));
            DB::reconnect(config('database.default'));

            $restore->update([
                'status' => 'success',
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $restore->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            report($exception);
        }

        return $restore->fresh(['backup', 'safetyBackup']);
    }

    private function dumpDatabase(string $outputPath): void
    {
        $connection = config('database.connections.' . config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException(__('setting::settings.backups.unsupported_driver'));
        }

        $password = (string) ($connection['password'] ?? '');
        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . ($connection['port'] ?? 3306),
            '--user=' . ($connection['username'] ?? ''),
        ];

        if ($password !== '') {
            $command[] = '--password=' . $password;
        }

        $command[] = $connection['database'];

        $process = new Process($command);
        $process->setTimeout(600);

        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException(__('setting::settings.backups.file_open_failed'));
        }

        try {
            $process->run(function ($type, $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (!$process->isSuccessful()) {
            @unlink($outputPath);
            throw new RuntimeException(trim($process->getErrorOutput()) ?: __('setting::settings.backups.dump_failed'));
        }
    }

    private function assertRestorable(SystemBackup $backup): void
    {
        if ($backup->status !== 'success' || $backup->type !== 'database') {
            throw new RuntimeException(__('setting::settings.restores.invalid_backup'));
        }

        if (!$backup->path || !Storage::disk($backup->disk)->exists($backup->path)) {
            throw new RuntimeException(__('setting::settings.restores.file_missing'));
        }
    }

    private function importDatabase(string $inputPath): void
    {
        $connection = config('database.connections.' . config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException(__('setting::settings.backups.unsupported_driver'));
        }

        $password = (string) ($connection['password'] ?? '');
        $command = [
            'mysql',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . ($connection['port'] ?? 3306),
            '--user=' . ($connection['username'] ?? ''),
        ];

        if ($password !== '') {
            $command[] = '--password=' . $password;
        }

        $command[] = $connection['database'];

        $handle = fopen($inputPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException(__('setting::settings.restores.file_open_failed'));
        }

        try {
            $process = new Process($command);
            $process->setInput($handle);
            $process->setTimeout(600);
            $process->run();
        } finally {
            fclose($handle);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: __('setting::settings.restores.import_failed'));
        }
    }
}
