<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO
|--------------------------------------------------------------------------
| ★外部 URL の安全境界は **ここに書かない**。SSRF の境界の正本は
|   config/ssrf-pin.php であり、本設計はそれを変更しない (同じ事実を 2 か所に置かない)。
| ★環境変数を足さない。すべて固定値である (テンプレートの固定値方式に合わせる)。
|
| ★`max_body_bytes` は **アプリ側の後段検査の上限**である。
|   kent013/laravel-ssrf-pin ^0.4 の `PinnedRequest` は要求ごとの本文上限を受け取らず、
|   上限は transport の構築時 (config/ssrf-pin.php の `max_body_bytes`) に決まる。
|   したがって用途ごとのより厳しい上限は **応答を受け取った後にアプリが測って拒否する**。
|   transport 側の上限は「読み切らせない」ための防壁で、こちらは
|   「期待と違う大きさの応答を DTO へ固定しない」ための検査である (層が違う)。
*/

return [
    'discovery' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 5,
        'cache_ttl_seconds' => 300,
        // 未知 kid での鍵の再取得の最小間隔 (増幅を防ぐ)
        'jwks_refetch_min_interval_seconds' => 60,
        'max_body_bytes' => 262144,
    ],

    'token' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 8,
        'max_body_bytes' => 65536,
    ],

    'id_token' => [
        // 許容する時刻ずれ。**顧客の入力では広げられない** (接続の登録項目にしない)。
        'leeway_seconds' => 60,
        'max_subject_length' => 255,
    ],

    'login_attempt' => [
        'ttl_seconds' => 600,
        // 掃除の 1 回あたりの上限 (長いトランザクションを作らない)
        'prune_chunk' => 1000,
    ],

    // メールアドレスの昇格 (E1)。Auth 名前空間の機能だが、設定は本ファイルに集約する
    // (企業 SSO でしか入れない利用者のための機構であり、単独では意味を持たない)。
    'email_promotion' => [
        'ttl_seconds' => 3600,
    ],
];
