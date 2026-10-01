<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;

class InitializeAppLocaleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $requestedLocale = (string) $request->get(
            'locale',
            $request->header(
                'X-NexDine-Locale',
                $request->getPreferredLanguage(supportedLocaleKeys())
                    ?: setting('default_locale', config('app.locale', 'en'))
            )
        );

        // Browsers and native clients may send regional values such as en-IN or
        // en_US. Reports use the application's base locale directories.
        $locale = strtolower(str_replace('_', '-', trim($requestedLocale)));
        $locale = explode('-', $locale, 2)[0];
        $supportedLocales = supportedLocaleKeys();

        if (!in_array($locale, $supportedLocales, true)) {
            $locale = in_array('en', $supportedLocales, true)
                ? 'en'
                : (string) (setting('default_locale', config('app.locale', 'en')));
        }

        if (in_array($locale, $supportedLocales, true)) {
            $oldLocale = locale();

            app()->setLocale($locale);

            if ($locale != $oldLocale) {
                app()->forgetInstance('setting');
                app()->singleton('setting', fn () => new SettingRepository(Setting::allCached()));
            }
        }

        return $next($request);
    }
}
