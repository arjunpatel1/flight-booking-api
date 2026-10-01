<?php

namespace App\Providers;

use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Utilities\QueryListener;
use Modules\Support\Console\RefreshSchemaCommand;
use Modules\User\Models\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->usePersonalAccessTokenModel();
        $this->strictModels();

        QueryListener::listen();

        Gate::before(fn($user) => $user->isSuperAdmin());
        Gate::define('viewPulse', fn($user = null) => $user?->isSuperAdmin() === true);
        
        // Pulse activation is controlled by config/pulse.php and PULSE_ENABLED.

        try {
            Schema::defaultStringLength(191);
            DB::statement("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        } catch (Exception) {
        }


        if ($this->app->environment(['local', 'testing'])) {
            QueryListener::listen();
        } elseif ($this->app->isProduction()) {
            $this->prohibitDestructiveCommands();
        }

    }

    /**
     * Register the personal access tokens' model
     *
     * @return void
     */
    private function usePersonalAccessTokenModel(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * The model should be strict to get optimal performance.
     *
     * @return void
     */
    private function strictModels(): void
    {
        Model::shouldBeStrict();
    }

    /**
     * Prohibit Destructive Commands
     *
     * @return void
     */
    private function prohibitDestructiveCommands(): void
    {
        if ($this->app->isProduction()) {
            // Disable refresh schema command in production environment.
            RefreshSchemaCommand::prohibit();
            // Prohibits: db:wipe, migrate:fresh, migrate:refresh, and migrate:reset.
            DB::prohibitDestructiveCommands();
        }
    }
}
