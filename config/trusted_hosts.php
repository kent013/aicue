<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted Hosts (Host header injection 防御)
|--------------------------------------------------------------------------
|
| Laravel の `Illuminate\Http\Middleware\TrustHosts` は配列要素を **regex pattern**
| として扱うため、bootstrap/app.php 側で `preg_quote` + 明示 anchor (`^...$`) を
| 付与する。設定段階では「文字列としての host (alphanumeric / dot / hyphen)」として
| 保持し、runtime で regex 化する責務分離。
|
| - `exact_hosts`: 完全一致する host 名 (例: app.example.com)
| - `wildcard_suffixes`: 末尾一致する suffix (例: .preview.example.com、先頭 dot 必須)
|
| 不正値は silent drop ではなく `App\Support\TrustedHostsConfigValidator`
| (ProductionEnvGuard 経由で production 起動時) で fail-fast する。
| deploy pipeline 上で env ミスを起動時点で検知する。
|
*/

return [
    /*
    | 完全一致 host (string list)。各値は host のみ (alphanumeric / dot / hyphen)。
    | scheme / port / path を含む値は TrustedHostsConfigValidator で reject する。
    |
    | 候補: PRIMARY_HOST (deploy 時の primary domain) / APP_URL の host 部 /
    |       TRUSTED_HOSTS_ADDITIONAL の CSV 列 (alias / health check 等)。
    */
    'exact_hosts' => array_values(array_filter(
        array_map('trim', [
            (string) env('PRIMARY_HOST', ''),
            parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: '',
            ...explode(',', (string) env('TRUSTED_HOSTS_ADDITIONAL', '')),
        ]),
        fn (string $v): bool => $v !== ''
    )),

    /*
    | wildcard suffix (`.preview.example.com` 形式、先頭 dot 必須)。
    | alphanumeric / dot / hyphen のみ許容。不正書式は config 段階で除外し、
    | 起動時に TrustedHostsConfigValidator から再 verify される。
    | 先頭 dot + 2 ラベル以上 (`.com` 単体は過大許可のため除外)。
    */
    'wildcard_suffixes' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_HOSTS_WILDCARD_SUFFIXES', ''))),
        fn (string $v): bool => $v !== ''
            && preg_match('/^\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $v) === 1
    )),

    /*
    | 生 env 値 (空白 trim 前) を保持。TrustedHostsConfigValidator が
    | 「config 段階で silent drop された不正値」を起動時 fail-fast で検知するために使う。
    | Guard 側で `env()` を直接読むと config:cache 後に null 化するため config 経由で expose。
    */
    'raw_wildcard_suffixes' => explode(',', (string) env('TRUSTED_HOSTS_WILDCARD_SUFFIXES', '')),
];
