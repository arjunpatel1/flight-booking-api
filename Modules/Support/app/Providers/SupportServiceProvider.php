<?php

namespace Modules\Support\Providers;

use App\NexDine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

class SupportServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole() || !NexDine::installed()) {
            $this->registerBlueprintMacros();
        }
    }

    /**
     * Register Blueprint Macro
     *
     * @return void
     */
    private function registerBlueprintMacros(): void
    {
        Blueprint::macro('createdBy', function (bool $withConstrained = true) {
            /** @var Blueprint $this */
            $column = $this->foreignIdFor(User::class, "created_by")->nullable();
            if ($withConstrained) {
                $column->constrained((new User)->getTable())->nullOnDelete();
            }

            return $column;
        });

        Blueprint::macro('branch', function () {
            /** @var Blueprint $this */
            return $this->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
        });

        Blueprint::macro('active', function (bool $value = false, bool $index = true, ?string $after = null) {
            /** @var Blueprint $this */
            $column = $this->boolean('is_active')->default($value);
            if ($after) {
                $column->after($after);
            }

            if ($index) {
                $column->index();
            }

            return $column;
        });

        Blueprint::macro('meta', function (bool $nullable = true) {
            /** @var Blueprint $this */
            if ($nullable) {
                return $this->json('meta')->nullable();
            } else {
                return $this->json('meta');
            }
        });

        Blueprint::macro('order', function () {
            /** @var Blueprint $this */
            return $this->integer('order')->unsigned()->default(0);
        });
    }
}
