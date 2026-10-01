<?php

namespace Modules\Category\Console;

use Illuminate\Console\Command;
use Modules\Category\Models\Category;

class FixCategoryTreeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'category:fix-tree';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild nested set tree for categories';
    
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        Category::fixTree();
    }
}
