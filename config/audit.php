<?php

use App\Auth\ResolveAuditCauser;
use App\Models\ModelAudit;
use OwenIt\Auditing\Resolvers\IpAddressResolver;
use OwenIt\Auditing\Resolvers\UrlResolver;
use OwenIt\Auditing\Resolvers\UserAgentResolver;

return [

    'enabled' => env('AUDITING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Implementation
    |--------------------------------------------------------------------------
    |
    | Audit モデルの実装。パッケージ既定の Audit ではなく自前 ModelAudit を使う
    | (table 名固定 + tags/values の表示 accessor を持つ)。
    |
    */

    'implementation' => ModelAudit::class,

    /*
    |--------------------------------------------------------------------------
    | User Morph prefix & Guards
    |--------------------------------------------------------------------------
    |
    | 実行者 (causer) の解決。admin (Filament 運営) を web より優先する多 guard
    | 解決は ResolveAuditCauser が行う。
    |
    */

    'user' => [
        'morph_prefix' => 'user',
        'guards' => [
            'admin',
            'web',
        ],
        'resolver' => ResolveAuditCauser::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Resolvers
    |--------------------------------------------------------------------------
    |
    | IP アドレス / User Agent / URL の resolver 実装。
    |
    */
    'resolvers' => [
        'ip_address' => IpAddressResolver::class,
        'user_agent' => UserAgentResolver::class,
        'url' => UrlResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Events
    |--------------------------------------------------------------------------
    |
    | Audit を発火させる Eloquent イベント。
    |
    */

    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | strict mode を有効にするか。
    |
    */

    'strict' => false,

    /*
    |--------------------------------------------------------------------------
    | Global exclude
    |--------------------------------------------------------------------------
    |
    | 全モデル共通で audit から除外する属性 (モデル側 exclude で上書きされる)。
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Empty Values
    |--------------------------------------------------------------------------
    |
    | old_values / new_values が両方空でも Audit を保存するか。
    |
    */

    'empty_values' => true,
    'allowed_empty_values' => [
        'retrieved',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Array Values
    |--------------------------------------------------------------------------
    |
    | array 属性値を audit に保存するか (大きな payload の性能劣化防止のため既定 false)。
    |
    */
    'allowed_array_values' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Timestamps
    |--------------------------------------------------------------------------
    |
    | created_at / updated_at / deleted_at を audit 対象にするか。
    |
    */

    'timestamps' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Threshold
    |--------------------------------------------------------------------------
    |
    | 1 モデルあたりの Audit 保持上限 (0 = 無制限)。
    |
    */

    'threshold' => 0,

    /*
    |--------------------------------------------------------------------------
    | Audit Driver
    |--------------------------------------------------------------------------
    */

    'driver' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Audit Driver Configurations
    |--------------------------------------------------------------------------
    */

    'drivers' => [
        'database' => [
            'table' => 'model_audits',
            'connection' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Queue Configurations
    |--------------------------------------------------------------------------
    |
    | queue 経由の audit 書き込みは使わない (CriticalActionContext が request
    | scope のため、queue worker では context を参照できない)。
    |
    */

    'queue' => [
        'enable' => false,
        'connection' => 'sync',
        'queue' => 'default',
        'delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Console
    |--------------------------------------------------------------------------
    |
    | console (artisan) 実行時のイベントも audit するか。
    |
    */

    'console' => true,
];
