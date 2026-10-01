<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class UpgradeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'nexdine:upgrade';

    /**
     * The console command description.
     */
    protected $description = 'Command for upgrading NexDine.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Artisan::call('module:migrate', ['-a' => true, '--force' => true]);
        Artisan::call('permission:sync-permissions');
        Artisan::call('permission:sync-default-roles');
        Artisan::call('category:fix-tree');
        Artisan::call('media:fix-tree');
        Artisan::call('storage:link');
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
    }
}
