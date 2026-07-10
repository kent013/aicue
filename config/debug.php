<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Debug Login (LocalOnly middleware)
    |--------------------------------------------------------------------------
    |
    | /debug/login で使う Basic 認証の資格情報。LocalOnly middleware が参照する。
    | 防御は三層: (1) route 登録自体が local / 単体テスト実行中のみ (routes/web.php)、
    | (2) LocalOnly が local 以外・未設定時に 404 (fail-secure)、
    | (3) production では ProductionEnvGuard が DEBUG_LOGIN_* の残置を fail-fast させる。
    |
    | password は平文で保持し、比較は hash_equals による timing-safe 比較で行う。
    | /debug/login は local 限定経路で、本値は gitignore された各開発者の .env に
    | しか存在しない。「資格情報を晒さない」不変条件は local 限定ゲート +
    | .env 非コミットで既に満たされ、bcrypt 化は守る対象が無く開発者に
    | ハッシュ再生成の手間だけ増やすため採用しない。
    |
    */
    'login' => [
        'user' => env('DEBUG_LOGIN_USER'),
        'password' => env('DEBUG_LOGIN_PASSWORD'),
    ],
];
