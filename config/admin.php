<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin (Filament 管理画面) Configuration
|--------------------------------------------------------------------------
|
| 管理画面 (admin guard / AdminUser) に関する設定。
|
*/

return [

    /*
    | 初期 AdminUser は env / seeder では投入しない。
    | 本番は `php artisan admin:create` コマンドで発行する
    | (local 開発は AdminUserSeeder の固定値でよい)。
    | 2 人目以降は管理画面の AdminUserResource から作成すること。
    */

    /*
    | 管理画面の MFA (TOTP) 必須化。
    | production は true を既定とする。local / CI では ADMIN_MFA_REQUIRED=false で
    | 強制セットアップフローをスキップして開発イテレーションできる。
    */
    'mfa_required' => filter_var(env('ADMIN_MFA_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),

];
