<?php

use App\Http\Middleware\McpConsentOrganizationBinder;

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Passport Route Middleware
    |--------------------------------------------------------------------------
    |
    | Passport の /oauth/* route group に付与する追加 middleware。
    | McpConsentOrganizationBinder は /oauth/authorize の approve POST body の
    | organization_id を検証し、McpAuthCodeRepository が読む request attribute に
    | 詰める (非 MCP 経路 = organization_id 欠落では no-op)。
    | 実行順は bootstrap/app.php の priority list で Authenticate の後ろに固定する
    | (StartSession / Authenticate より前に走ると $request->user() が null になるため)。
    |
    */

    'middleware' => [
        McpConsentOrganizationBinder::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    | production では **必ず env (`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`)
    | で注入** すること。`storage/oauth-*.key` のファイル経由 (php artisan
    | passport:keys) は dev / CI 専用前提で、production 環境ではキーマネジメント
    | (Secret Manager 等) からの env 注入に統一する。`.gitignore` の
    | `/storage/*.key` で鍵ファイルの commit は除外しているが、万一 commit される
    | と全 OAuth client の token が偽造可能になるため、env 注入を強制することで
    | file 設置自体を不要にする運用にする。
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

];
