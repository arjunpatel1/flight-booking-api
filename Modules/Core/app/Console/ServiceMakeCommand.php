<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Nwidart\Modules\Exceptions\FileAlreadyExistException;
use Nwidart\Modules\Generators\FileGenerator;
use Nwidart\Modules\Module;
use Nwidart\Modules\Support\Config\GenerateConfigReader;
use Nwidart\Modules\Support\Stub;
use Nwidart\Modules\Traits\ModuleCommandTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ServiceMakeCommand extends Command
{
    use ModuleCommandTrait;

    protected string $argumentName = 'name';

    /**
     * The name and signature of the console command.
     */
    protected $name = 'module:make-service';

    /**
     * The console command description.
     */
    protected $description = 'Generate new service for the specified module.';

    public function handle(): int
    {
        $stubs = ["service-interface", "service"];

        if ($this->option('controller')) {
            $stubs[] = "controller";
        }

        foreach ($stubs as $stub) {
            $explode = explode("-", $stub);
            $destination = $explode[0];

            if (!$this->generateFile(
                $this->getDestinationFilePath($destination, ucfirst($destination) . ucfirst(($explode[1] ?? ''))),
                $this->getTemplateContents($stub, $destination))
            ) {
                return E_ERROR;
            }
        }

        $this->components->task(
            description: "Generating resource {$this->argument('name')}Resource",
            task: fn() => Artisan::call("module:make-resource Api/V{$this->option('release')}/{$this->argument('name')}Resource {$this->argument('module')}")
        );

        $this->components->task(
            description: "Generating request {$this->argument('name')}Request",
            task: fn() => Artisan::call("module:make-request Api/V{$this->option('release')}/Save{$this->argument('name')}Request {$this->argument('module')}")
        );

        return 0;
    }

    /**
     * Generate File
     * @param string $path
     * @param string $contents
     * @return bool
     */
    private function generateFile(string $path, string $contents): bool
    {
        if (!$this->laravel['files']->isDirectory($dir = dirname($path))) {
            $this->laravel['files']->makeDirectory($dir, 0777, true);
        }

        try {
            $this->components->task("Generating file $path", function () use ($path, $contents) {
                $overwriteFile = $this->hasOption('force') ? $this->option('force') : false;
                (new FileGenerator($path, $contents))->withFileOverwrite($overwriteFile)->generate();
            });
        } catch (FileAlreadyExistException) {
            $this->components->error("File : $path already exists.");
            return false;
        }

        return true;
    }

    /**
     * Get a destination file path
     *
     * @param string $destination
     * @param string $suffix
     * @return string
     */
    private function getDestinationFilePath(string $destination, string $suffix): string
    {
        $path = $this->laravel['modules']->getModulePath($this->getModuleName());

        $repositoryPath = GenerateConfigReader::read($destination);
        $namespace = "$path{$repositoryPath->getPath()}";
        if ($destination == 'controller') {
            return "$namespace/Api/V{$this->option('release')}/{$this->getFileName($suffix)}.php";
        }

        return "$namespace/app/Services/{$this->getStudlyName()}/{$this->getFileName($suffix)}.php";
    }

    /**
     * Get file name
     *
     * @param string $suffix
     * @return string
     */
    private function getFileName(string $suffix): string
    {
        return Str::studly($this->argument($this->argumentName) . $suffix);
    }

    /**
     * Get str "studly" name
     *
     * @return string
     */
    private function getStudlyName(): string
    {
        return Str::studly($this->argument($this->argumentName));
    }

    /**
     * Get Template Contents
     *
     * @param string $stub
     * @param string $destination
     * @return string
     */
    private function getTemplateContents(string $stub, string $destination): string
    {
        $module = $this->laravel['modules']->findOrFail($this->getModuleName());

        $class = $this->getClass();
        $moduleName = $this->getModuleName();
        return (new Stub("/scaffold/$stub.stub", [
            'CLASS_NAMESPACE' => $this->getClassNamespace($module, $destination),
            'CLASS' => $class,
            'CLASS_LC_FIRST' => lcfirst($class),
            'CLASS_SNAKE_PLURAL' => Str::plural(Str::snake($class)),
            'CLASS_SNAKE' => Str::snake($class),
            'MODULE_SNAKE_PLURAL' => Str::plural(Str::snake($moduleName)),
            "MODULE_NAME" => $moduleName,
            "MODULE_LOWER_NAME" => $module->getLowerName(),
            "VERSION" => "V{$this->option('release')}",
        ]))->render();
    }

    /**
     * Get class name.
     *
     * @return string
     */
    private function getClass(): string
    {
        return class_basename($this->argument($this->argumentName));
    }

    /**
     * Get class namespace.
     *
     * @param Module $module
     * @param string $destination
     * @return string
     */
    private function getClassNamespace(Module $module, string $destination): string
    {
        $extra = str_replace($this->getClass(), '', $this->argument($this->argumentName));

        $extra = str_replace('/', '\\', $extra);

        $namespace = $this->laravel['modules']->config('namespace');

        $namespace .= '\\' . $module->getStudlyName();

        $namespace .= '\\' . $this->getDefaultNamespace($destination);

        $namespace .= '\\' . $this->getStudlyName();

        $namespace .= '\\' . $extra;

        $namespace = str_replace('/', '\\', $namespace);

        return trim($namespace, '\\');
    }

    /**
     * Get default namespace.
     *
     * @param string $destination
     * @return string
     */
    private function getDefaultNamespace(string $destination): string
    {
        return config("modules.paths.generator.$destination.namespace")
            ?? ltrim(config("modules.paths.generator.$destination.path", Str::plural(ucfirst($destination))), config('modules.paths.app_folder'));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of repository will be created.'],
            ['module', InputArgument::OPTIONAL, 'The name of module will be used.'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['controller', 'c', InputOption::VALUE_NEGATABLE, 'Append a controller for the desired model', true],
            ['resource', 't', InputOption::VALUE_NEGATABLE, 'Append a resource for the desired model', true],
            ['request', 'r', InputOption::VALUE_NEGATABLE, 'Append a request for the desired model', true],
            ['release', 'R', InputOption::VALUE_OPTIONAL, 'Determine the service version', 1],
        ];
    }
}
