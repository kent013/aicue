<?php

declare(strict_types=1);

/*
 * SSRF 安全境界 pin の不変条件 (WP21)。
 *
 * kent013/laravel-ssrf-pin は VCS 依存のため、パッケージ側の既定値変更で
 * 外向き許可面が広がらないよう、config/ssrf-pin.php で保守既定を app 側に pin する。
 * ここでは pin 値そのものを固定する (緩める場合はこのテストごと意図的に変更する)。
 * DB 不要のため Architecture スイート (TestCase のみ) に置く。
 */

use Kent013\SsrfPin\UrlSafetyInspector;

test('SSRF 安全境界は保守既定に pin されている', function (): void {
    expect(config('ssrf-pin.allowed_schemes'))->toBe(['http', 'https'])
        ->and(config('ssrf-pin.allowed_ports'))->toBe([80, 443])
        ->and(config('ssrf-pin.max_redirect_hops'))->toBe(5)
        ->and(config('ssrf-pin.additional_deny_cidrs'))->toBe([])
        // パッケージ既定 (false) より厳しい raw-IP URL 一律拒否をテンプレート既定とする
        ->and(config('ssrf-pin.deny_ip_literals'))->toBeTrue();
});

test('UrlSafetyInspector は pin された境界で外向き URL を拒否できる', function (): void {
    $inspector = app(UrlSafetyInspector::class);

    // 以下はすべて DNS 解決前に判定されるケースのみ (テストは外部ネットワーク非依存)
    // deny_ip_literals=true が反映され、IP literal URL は private/public を問わず拒否される
    expect($inspector->inspect('http://127.0.0.1/')->allowed)->toBeFalse()
        ->and($inspector->inspect('http://93.184.216.34/')->allowed)->toBeFalse()
        // 許可外スキーム / ポートも拒否される
        ->and($inspector->inspect('ftp://example.com/')->allowed)->toBeFalse()
        ->and($inspector->inspect('https://example.com:8443/')->allowed)->toBeFalse();
});
