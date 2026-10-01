<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'v1/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        env('FRONTEND_URL', 'https://myteknoland.in,https://www.myteknoland.in,https://nexdine.myteknoland.in,https://demo-nexdine.myteknoland.com,http://localhost:3101,http://127.0.0.1:3101,http://chirag.nexdine.test,http://happy.nexdine.test,http://pooripool.nexdine.test,http://api.nexdine.test,https://krishna-mayuri.myteknoland.com,https://ghee-dosa.myteknoland.com')
    ))))),

    'allowed_origins_patterns' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGIN_PATTERNS',
        '#^https?:\/\/([a-z0-9-]+\.)?nexdine\.test(:\d+)?$#,#^https:\/\/([a-z0-9-]+\.)?nexdine\.myteknoland\.in$#,#^https:\/\/([a-z0-9-]+\.)?nexdine\.myteknoland\.net$#,#^https:\/\/(www\.)?myteknoland\.in$#'
    ))))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
