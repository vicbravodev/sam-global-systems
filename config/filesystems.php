<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'rustfs' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY', 'sail'),
            'secret' => env('RUSTFS_SECRET_KEY', 'password'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => env('RUSTFS_BUCKET', 'sam'),
            'url' => env('RUSTFS_URL'),
            'endpoint' => env('RUSTFS_ENDPOINT', 'http://rustfs:9000'),
            // Endpoint alcanzable desde el navegador para URLs prefirmadas
            // (el endpoint interno de Docker no resuelve fuera del cluster).
            'public_endpoint' => env('RUSTFS_PUBLIC_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
            // Sin esto el SDK de AWS espera sin límite a un RustFS/S3 que no
            // responde y el job que lo llama se cuelga hasta su timeout. Con
            // límites cortos el fallo llega rápido y los jobs de media lo
            // degradan (reintento con backoff) en lugar de bloquearse.
            'http' => [
                'connect_timeout' => (float) env('RUSTFS_CONNECT_TIMEOUT', 5),
                'timeout' => (float) env('RUSTFS_TIMEOUT', 60),
            ],
            'retries' => (int) env('RUSTFS_RETRIES', 2),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
