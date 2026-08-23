<?php

declare(strict_types=1);

use App\Services\EnterpriseSso\OidcDiscoveryService;
use Illuminate\Support\Facades\Config;

/*
 * 企業 OIDC SSO の設定値の値域 (A1)。
 *
 * ★上限を置くのは「顧客の入力では広げられない」ことの担保ではなく、
 *   **設定の書き換えで安全側の前提が黙って崩れないため**である
 *   (例: 時刻ずれを 1 日に広げれば期限切れトークンが通る)。
 */

test('全整数の設定が正数である (0 と負数を弾く)', function (string $key): void {
    expect(Config::integer($key))->toBeGreaterThan(0);
})->with([
    'enterprise-sso.discovery.connect_timeout_seconds',
    'enterprise-sso.discovery.request_timeout_seconds',
    'enterprise-sso.discovery.cache_ttl_seconds',
    'enterprise-sso.discovery.jwks_refetch_min_interval_seconds',
    'enterprise-sso.discovery.max_body_bytes',
    'enterprise-sso.token.connect_timeout_seconds',
    'enterprise-sso.token.request_timeout_seconds',
    'enterprise-sso.token.max_body_bytes',
    'enterprise-sso.id_token.leeway_seconds',
    'enterprise-sso.id_token.max_subject_length',
    'enterprise-sso.login_attempt.ttl_seconds',
    'enterprise-sso.login_attempt.prune_chunk',
    'enterprise-sso.email_promotion.ttl_seconds',
]);

test('接続の待ち時間は要求全体の待ち時間を超えない', function (string $group): void {
    expect(Config::integer("enterprise-sso.{$group}.connect_timeout_seconds"))
        ->toBeLessThanOrEqual(Config::integer("enterprise-sso.{$group}.request_timeout_seconds"));
})->with(['discovery', 'token']);

test('時刻ずれの許容は 300 秒を超えない (期限切れトークンを通さない)', function (): void {
    expect(Config::integer('enterprise-sso.id_token.leeway_seconds'))->toBeLessThanOrEqual(300);
});

test('ログイン試行は 1800 秒より長生きしない', function (): void {
    expect(Config::integer('enterprise-sso.login_attempt.ttl_seconds'))->toBeLessThanOrEqual(1800);
});

test('メール昇格の確認は 1 日より長生きしない', function (): void {
    expect(Config::integer('enterprise-sso.email_promotion.ttl_seconds'))->toBeLessThanOrEqual(86400);
});

test('応答の大きさの上限が過大でない', function (): void {
    expect(Config::integer('enterprise-sso.discovery.max_body_bytes'))->toBeLessThanOrEqual(1048576);
    expect(Config::integer('enterprise-sso.token.max_body_bytes'))->toBeLessThanOrEqual(262144);
});

test('鍵の再取得の最小間隔が 1 秒以上ある (増幅を防ぐ)', function (): void {
    expect(Config::integer('enterprise-sso.discovery.jwks_refetch_min_interval_seconds'))
        ->toBeGreaterThanOrEqual(1);
});

test('subject の長さの上限が DB の列と一致する', function (): void {
    // A2 の `enterprise_identities.subject` は varchar(255) + octet_length の CHECK である。
    expect(Config::integer('enterprise-sso.id_token.max_subject_length'))->toBe(255);
});

test('鍵の再取得のロックの寿命が外向きの時間予算より長い', function (): void {
    // ★取得の途中でロックが失効すると 2 人目が取り始めてしまい、抑止そのものが成立しない。
    //   設定を変えて予算がロックの寿命を超えたら**この検査が先に赤くなる**。
    $budget = Config::integer('enterprise-sso.discovery.connect_timeout_seconds')
        + Config::integer('enterprise-sso.discovery.request_timeout_seconds');

    expect(OidcDiscoveryService::JWKS_REFETCH_LOCK_SECONDS)->toBeGreaterThan($budget);
});
