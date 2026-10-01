<?php

return [
    /*
     * Debugbar is a development-only tool. It must never follow APP_DEBUG in
     * this production POS API unless explicitly enabled in a local/dev env.
     */
    'enabled' => env('DEBUGBAR_ENABLED', false),
    'collect_jobs' => env('DEBUGBAR_COLLECT_JOBS', false),
    'except' => [
        'api/*',
        'telescope*',
        'horizon*',
        '_boost/browser-logs',
        'livewire-*/livewire.js',
    ],
];
