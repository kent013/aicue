<?php

use App\Support\ExternalClientTimeouts;

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
            // AWS SDK は http / retries を無指定にすると「無制限 × 3 attempts」になる。
            // データ系 (本文 read/write) の値。metadata 操作は per-command で制御系へ絞る
            // (App\Services\Capture\TakeObjectStorage::headObject)。
            // FilesystemManager::createS3Driver() がこの配列を素通しで S3Client へ渡す。
            ...ExternalClientTimeouts::awsS3ClientOptions(),
        ],

        // bughunt / testing の storage fake 用ローカル disk (実 S3 非依存の emulation)。
        // FakeTakeObjectStorage / FakeRenderObjectStorage が共有し S3 key namespace を再現する。
        // 本番では誰も解決しない (FakeStorageGate 成立時のみ fake が bind される限り inert)。
        // throw=true で失敗を握り潰さない。FILESYSTEM_DISK の default (local) は不変。
        's3_fake' => [
            'driver' => 'local',
            'root' => storage_path('app/s3-fake'),
            'throw' => true,
            'report' => false,
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
