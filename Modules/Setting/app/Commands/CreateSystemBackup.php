<?php

namespace Modules\Setting\Commands;

use Illuminate\Console\Command;
use Modules\Setting\Services\SystemBackup\SystemBackupServiceInterface;

class CreateSystemBackup extends Command
{
    protected $signature = 'system:backup';

    protected $description = 'Create a database backup snapshot.';

    public function handle(SystemBackupServiceInterface $service): int
    {
        $backup = $service->createDatabaseBackup();

        if ($backup->status === 'success') {
            $this->info(__('setting::settings.backups.created', ['path' => $backup->path]));

            return self::SUCCESS;
        }

        $this->error($backup->error_message ?: __('setting::settings.backups.failed'));

        return self::FAILURE;
    }
}
