<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted Proxies (client IP / X-Forwarded-Proto の信頼境界)
|--------------------------------------------------------------------------
|
| ⚠ **ファイル名はフレームワークが参照する固定名**。
| Illuminate\Http\Middleware\TrustProxies は `$this->proxies() ?: config('trustedproxy.proxies')`
| の順で解決するため、env 由来の allowlist を渡す唯一の道がこの config キーである。
| 本リポジトリの命名慣行 (`trusted_hosts.php` = snake_case) とは異なるが、
| framework の fallback 経路に乗せるため変更しない。
|
| TRUSTED_PROXIES に **すべての hop** の IP / CIDR を CSV で列挙する。
| hop を 1 つでも取りこぼすと client IP がその hop に固定され、全利用者が
| 1 つの rate limit バケットに落ちる (自己 DoS)。
| CloudFront → ALB のような多段構成では両方の range を列挙すること。
|
| 特別な値:
|   - `none`       : 「プロキシは無い」の明示宣言 (空 list に写す)
|   - `REMOTE_ADDR`: 直接の接続元を信頼 (ローカル開発の Valet TLS 用。production では禁止)
|
| `*` / `**` は **禁止** (全アドレス信頼 = XFF 偽装が通る)。
| 不正値は silent drop ではなく App\Support\TrustedProxiesConfigValidator
| (ProductionEnvGuard 経由) が production 起動時に fail-fast する。
| 運用契約は docs/trusted-proxies-runbook.md。
|
*/

use App\Support\TrustedProxyToken;

$rawProxies = array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')));

return [
    /*
    | framework (Illuminate\Http\Middleware\TrustProxies) が読む正本。
    | **検証を通過した値のみ**。空配列 = 何も信頼しない (= REMOTE_ADDR が client IP)。
    |
    | 判定は App\Support\TrustedProxyToken に一本化する (config 段と validator 段で
    | 同じ関数を使い、判定のズレによる silent drop / 誤 reject を作らない)。
    | config:cache は評価結果 (plain array) を保存するため関数呼び出しでも問題ない。
    */
    'proxies' => array_values(array_filter(
        $rawProxies,
        static fn (string $v): bool => TrustedProxyToken::isTrustableAddress($v),
    )),

    /*
    | 生 token (空要素・空白のみ要素も保持)。config 段で silent drop された値を
    | 起動時 fail-fast で表面化させるために TrustedProxiesConfigValidator が読む。
    | Guard 側で env() を直接読むと config:cache 後に null 化するため config 経由で expose。
    */
    'raw_proxies' => $rawProxies,
];
