<?php

namespace Modules\Setting\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Setting\Events\SettingSaved;

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
        Cache::tags('settings')->flush();
        app()->forgetInstance('setting');
    }
}
