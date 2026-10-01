<?php

namespace Modules\Setting\Services\SystemBackup;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Setting\Models\SystemBackup;
use Modules\Setting\Models\SystemRestore;

interface SystemBackupServiceInterface
{
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    public function createDatabaseBackup(): SystemBackup;

    public function getRestores(array $filters = [], array $sorts = []): LengthAwarePaginator;

    public function restoreDatabaseBackup(SystemBackup $backup, ?string $reason = null): SystemRestore;
}
