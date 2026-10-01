<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Image Optimization Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for image optimization system
    |
    */

    'disk' => env('IMAGE_DISK', 'public'),
    
    'quality' => env('IMAGE_QUALITY', 85),
    
    'sizes' => [
        'thumbnail' => [
            'width' => 150,
            'height' => 150,
            'fit' => 'cover',
        ],
        'medium' => [
            'width' => 400,
            'height' => 400,
            'fit' => 'cover',
        ],
        'original' => [
            'width' => null,
            'height' => null,
            'fit' => null,
        ],
    ],
    
    'formats' => [
        'output' => 'webp',
        'fallback' => 'jpg',
    ],
    
    'validation' => [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    ],
    
    'queue' => [
        'enabled' => env('IMAGE_QUEUE_ENABLED', true),
        'connection' => env('IMAGE_QUEUE_CONNECTION', 'redis'),
        'queue' => env('IMAGE_QUEUE_NAME', 'image-optimization'),
    ],
    
    'optimizers' => [
        'jpegoptim' => [
            'binary' => env('JPEGOPTIM_BINARY', '/usr/bin/jpegoptim'),
            'enabled' => env('JPEGOPTIM_ENABLED', true),
        ],
        'pngquant' => [
            'binary' => env('PNGQUANT_BINARY', '/usr/bin/pngquant'),
            'enabled' => env('PNGQUANT_ENABLED', true),
        ],
        'optipng' => [
            'binary' => env('OPTIPNG_BINARY', '/usr/bin/optipng'),
            'enabled' => env('OPTIPNG_ENABLED', true),
        ],
    ],
];
