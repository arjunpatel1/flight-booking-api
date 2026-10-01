<?php

use Modules\Core\Http\ResponseCache\PosResponseCacheProfile;
use Spatie\ResponseCache\Hasher\DefaultHasher;
use Spatie\ResponseCache\Replacers\CsrfTokenReplacer;
use Spatie\ResponseCache\Serializers\JsonSerializer;

return [
    /*
     * Response caching is intentionally disabled by default for the POS API.
     * Enable only after adding the cache middleware to a measured, read-only route.
     */
    'enabled' => env('RESPONSE_CACHE_ENABLED', false),

    'cache' => [
        'store' => env('RESPONSE_CACHE_DRIVER', env('CACHE_STORE', 'file')),
        'lifetime_in_seconds' => (int) env('RESPONSE_CACHE_LIFETIME', 60),
        'tag' => env('RESPONSE_CACHE_TAG', 'pos-response-cache'),
    ],

    'bypass' => [
        'header_name' => env('CACHE_BYPASS_HEADER_NAME'),
        'header_value' => env('CACHE_BYPASS_HEADER_VALUE'),
    ],

    'debug' => [
        'enabled' => env('RESPONSE_CACHE_DEBUG', false),
        'cache_time_header_name' => 'X-Cache-Time',
        'cache_status_header_name' => 'X-Cache-Status',
        'cache_age_header_name' => 'X-Cache-Age',
        'cache_key_header_name' => 'X-Cache-Key',
    ],

    'ignored_query_parameters' => [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
    ],

    'cache_profile' => PosResponseCacheProfile::class,
    'hasher' => DefaultHasher::class,
    'serializer' => JsonSerializer::class,

    'replacers' => [
        CsrfTokenReplacer::class,
    ],

    'pos' => [
        'lifetime_in_seconds' => (int) env('POS_RESPONSE_CACHE_LIFETIME', 60),
        'cacheable_paths' => array_filter(array_map(
            'trim',
            explode(',', env('POS_RESPONSE_CACHE_PATHS', ''))
        )),
    ],
];
