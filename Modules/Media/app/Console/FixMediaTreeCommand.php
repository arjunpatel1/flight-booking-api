<?php

namespace Modules\Media\Console;

use Illuminate\Console\Command;
use Modules\Media\Models\Media;

class FixMediaTreeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:fix-tree';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild nested set tree for media';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Media::fixTree();
    }
}
