<?php

namespace Modules\Translation\Providers;

use App\NexDine;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
use Modules\Translation\Support\TranslationLoader;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot(): void
    {
        Carbon::setLocale(locale());

        if (NexDine::installed()) {
            $this->registerLoader();
            $this->registerTranslator();
        }
    }

    /**
     * Register loader
     *
     * @return void
     */
    private function registerLoader(): void
    {
        $this->app->singleton('translation.loader', function ($app) {
            return new TranslationLoader(
                $app['files'],
                [base_path("vendor/laravel/framework/src/Illuminate/Translation/lang"), $app['path.lang']]
            );
        });
    }

    /**
     * Register translator
     *
     * @return void
     */
    private function registerTranslator(): void
    {
        $this->app->singleton('translator', function ($app) {
            $loader = $app['translation.loader'];
            $locale = $app['config']['app.locale'];

            $trans = new Translator($loader, $locale);

            $trans->setFallback($app['config']['app.fallback_locale']);

            return $trans;
        });
    }
}
