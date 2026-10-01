<?php

namespace Modules\Support\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Traits\ModuleCommandTrait;
use Symfony\Component\Console\Input\InputOption;

class RefreshSchemaCommand extends Command
{
    use ModuleCommandTrait, Prohibitable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'module:refresh-schema';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Refresh the database schema for modules.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        if (!app()->environment('local', 'testing')) {
            $this->output->error('This command can only be run on local environment.');
            return;
        }

        // Reset the database
        $this->components->task('Dropping all schema tables...', function () {
            Schema::dropAllTables();
        });

        // Migrate the tables
        $this->call('module:migrate', ['-a' => true, '--env' => $this->option('env')]);

        // Seed the database
        if ($this->option('seed')) {
            $this->call('module:seed', [
                '-a' => true,
                '--env' => $this->option('env'),
            ]);
        }
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions(): array
    {
        return [
            ['seed', 'S', InputOption::VALUE_NEGATABLE, 'Indicates if the seed task should be re-run.', false],
        ];
    }
}
