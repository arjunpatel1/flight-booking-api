<?php

namespace Modules\Setting\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Setting\Events\SettingSaved;
use Modules\Setting\Models\Setting;

class ClearSettingCache
{
    /**
     * Handle the event.
     *
     * @param SettingSaved $event
     * @return void
     */
    public function handle(SettingSaved $event): void
    {
        // The version bump is what makes the next read fresh. The tag flush
        // only tidies up old entries, and on Windows the shared tag file can be
        // briefly locked by another worker; that must not abort the save.
        Setting::bumpCacheVersion();
        try {
            Cache::tags('settings')->flush();
        } catch (\Throwable $exception) {
            report($exception);
        }
        app()->forgetInstance('setting');
    }
}
