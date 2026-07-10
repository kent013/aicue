<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSRF 安全境界 pin (kent013/laravel-ssrf-pin)
|--------------------------------------------------------------------------
|
| 外部 URL 取得の SSRF 検査は `Kent013\SsrfPin\UrlSafetyInspector` が SSOT。
| パッケージは VCS 依存のため、package 側の既定値変更で外向き許可面が
| 広がらないよう、安全境界は必ず本 config で app 側に pin する
| (SsrfPinBoundaryTest が pin 値を固定)。
|
| 外部 URL (特にユーザ入力由来) を取得する機能を追加する場合は、
| 必ず UrlSafetyInspector / PinnedHttpClient を通すこと (AGENTS.md 参照)。
|
*/

return [
    // 許可するスキーム。
    'allowed_schemes' => ['http', 'https'],

    // 許可するポート。非標準ポート (内部サービス等) への到達を防ぐ。
    'allowed_ports' => [80, 443],

    // redirect 追従の最大 hop 数。
    'max_redirect_hops' => 5,

    // アプリ拡張用の追加 deny CIDR (例: 自社内部レンジ)。
    'additional_deny_cidrs' => [],

    // host が IP literal (例: http://93.184.216.34) の URL を一律拒否する。
    // パッケージ既定 (false) より厳しい保守既定。raw-IP URL を許可したい
    // アプリのみ意図的に false へ変更する (public IP のみ許可される)。
    'deny_ip_literals' => true,
];
