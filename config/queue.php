<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        // sync は「テストレーン (phpunit.xml / phpunit.browser.xml が force) と local dev」専用。
        // **after_commit => true が必須**: これが無いと業務 tx の内側からの dispatch が
        // その場でインライン実行され、RunManualAnalysis / RunManualRender が trigger の tx の
        // 内側で走って AnalysisPipeline / RenderPipeline の startJob (lockForUpdate + status===queued)
        // が自分自身のロック下で成立してしまう (= 共有ロック規約の意味論が壊れる)。
        //
        // SyncQueue::push() は shouldDispatchAfterCommit() を尊重し db.transactions へ倒す。
        // テストレーンでは RefreshDatabase が Illuminate\Foundation\Testing\DatabaseTransactionsManager
        // を差し込み、ラッパ tx を skip したうえで level 1 を root 扱いするため、
        // 「業務 tx の commit 直後・テスト tx の内側でインライン実行」= 本番の
        // 「commit 後に worker が拾う」と同じ順序意味論になる。
        //
        // この 1 行は QueueDispatchAtomicityGuard の規則 R4 が起動時に機械固定する。
        'sync' => [
            'driver' => 'sync',
            'after_commit' => true,
        ],

        // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
        // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
        // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
        // 360s の根拠 (T126 で SDK 既定依存を解消):
        //   外部予算 200s (= Stripe 20s × 呼び出し予算 10 回。App\Support\ExternalClientTimeouts)
        //   + 局所予算 90s = 290s < ワーカー --timeout 300s < retry_after 360s。
        //   序列は ExternalClientTimeoutInventoryTest が厳密不等号で固定する
        //   (docs/architecture.md §キューのリース期間とワーカー制限時間の規約 /
        //    §外部 SDK の待ち上限の規約)。
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 360,
            'after_commit' => false,
        ],

        // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
        // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-analysis` を必須登録
        // (docs/architecture.md。滞留は work:recover-stuck --stream=analysis_job が回収)
        'database-analysis' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'analysis',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // レンダ専用 (RunManualRender)。retry_after は job timeout (1,500s) より長く
        // 予約 TTL (1,800s) より短い (RenderTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-render` を必須登録
        // (docs/architecture.md。滞留は work:recover-stuck --stream=render_job が回収)
        'database-render' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'render',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // メディア掃除専用 (DeleteTakeObjectsJob)。運用契約: worker は
        // `php artisan queue:work database-media` を必須登録 (docs/architecture.md)
        'database-media' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'media',
            'retry_after' => 300,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
