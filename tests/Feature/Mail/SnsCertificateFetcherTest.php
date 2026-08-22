<?php

declare(strict_types=1);

use App\Services\Mail\Sns\SnsCertificateFetcher;
use App\Services\Mail\Sns\SnsCertificateUrl;
use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kent013\SsrfPin\UrlSafetyInspector;
use Tests\Support\SnsTestData;

/*
 * SNS 証明書取得口 (App\Services\Mail\Sns\SnsCertificateFetcher) の振る舞い。
 *
 * ★リクエストオプション (接続 / 全体 timeout / TLS 検証) は Laravel の `Http::fake()` からは
 *   観測できないため、その配線は tests/Architecture/SnsCertificateFetchContractTest.php の
 *   C10 / C5 が字句で固定する。ここでは扱わない。
 * ★キャッシュ / ロックの障害は **本物の保管方式を実際に壊して**再現する
 *   (`useBrokenSnsCertificateCacheStore()` の docblock 参照)。
 *   - `Illuminate\Contracts\Cache\Store` を自前実装しない — 受け手型・保管先型の実装宣言は
 *     CachePayloadPlainDataGateTest の L4d が実行時層の 2 本だけに固定しているためである
 *   - `Cache::swap()` で manager ごと差し替えることもしない — 実行時層
 *     (`Tests\Support\Cache\PlainDataCacheGuard`) の受け皿を経由しなくなり、
 *     キャッシュ素データ規約 (セキュリティ不変条件 11) の被覆がこのテストだけ消えるためである
 */

beforeEach(function (): void {
    // ★テスト専用の array store へ既定を切り替える (前のテストの実体は捨てる)。
    //   `Cache::flush()` は使わない — store 全体を消すので rate limiter・lock・
    //   他テストの値まで巻き添えにする。
    useFreshSnsCertificateCacheStore();
    bindSnsDnsResolver(snsPublicCertHostIps());
});

function snsCertUrl(string $url = SnsTestData::CERT_URL): SnsCertificateUrl
{
    return SnsCertificateUrl::fromString($url);
}

function snsCertFetcher(): SnsCertificateFetcher
{
    return app(SnsCertificateFetcher::class);
}

function snsCertCacheKey(string $url = SnsTestData::CERT_URL): string
{
    return SnsCertificateFetcher::CACHE_PREFIX.hash('sha256', $url);
}

/**
 * **本物の保管方式を実際に壊して**既定に据える (guard 付き受け皿はそのまま維持する)。
 *
 * 値の表 / ロックの表を作るか作らないかで、キャッシュ読み書きの失敗とロック取得の失敗を
 * **別々に**再現できる (database driver は値とロックで別の表を使うため)。
 *
 * ★接続は**テスト専用の sqlite in-memory** にする。本番のテスト DB (pgsql) 上で
 *   存在しない表を引くと、その瞬間に外側の transaction が abort し (`RefreshDatabase`)、
 *   後続の DB 操作がすべて別の理由で失敗して検証にならないためである。
 * ★`Cache` facade の差し替えは行わないので、実行時層の受け皿 (`PlainDataGuardedRepository`) が
 *   この store の書き込みも従来どおり検査する。
 */
function useBrokenSnsCertificateCacheStore(bool $valueTableExists, bool $lockTableExists): void
{
    config(['database.connections.sns_cert_broken' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('sns_cert_broken');

    $connection = DB::connection('sns_cert_broken');
    if ($valueTableExists) {
        $connection->statement('create table cache (key varchar primary key, value text, expiration integer)');
    }
    if ($lockTableExists) {
        $connection->statement('create table cache_locks (key varchar primary key, owner varchar, expiration integer)');
    }

    config(['cache.stores.sns_cert_broken' => [
        'driver' => 'database',
        'connection' => 'sns_cert_broken',
        'lock_connection' => 'sns_cert_broken',
        'table' => 'cache',
        'lock_table' => 'cache_locks',
    ]]);
    config(['cache.default' => 'sns_cert_broken']);
}

test('F0 (正のコントロール): 正常系 fixture は SSRF 検査を通る', function (): void {
    // 境界 (config/ssrf-pin.php + vendor の deny CIDR) が変わったらここが最初に赤くなる。
    expect(app(UrlSafetyInspector::class)->inspect(SnsTestData::CERT_URL)->allowed)->toBeTrue();
});

test('F1: キャッシュに載っていれば取りに行かない', function (): void {
    Http::fake();
    Cache::put(snsCertCacheKey(), SnsTestData::certificatePem(), 3600);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem());

    Http::assertNothingSent();
});

test('F2: 昇格しなければキャッシュに載らない (要件 6 の負例)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);

    $first = snsCertFetcher()->fetchSerialized(snsCertUrl());
    $second = snsCertFetcher()->fetchSerialized(snsCertUrl());

    expect($first->fromCache)->toBeFalse()
        ->and($second->fromCache)->toBeFalse();

    Http::assertSentCount(2);
});

test('F3: private IP に解決される host は恒久拒否 (403 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver(['10.0.0.5']);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertNothingSent();
});

test('F4: DNS 解決失敗は一時障害 (503 系) で取りに行かない', function (): void {
    Http::fake();
    bindSnsDnsResolver([]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertNothingSent();
});

test('F5: PEM として読めない応答は恒久拒否でキャッシュに固定しない', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('not a pem', 200)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);
    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    Http::assertSentCount(2);
    expect(Cache::get(snsCertCacheKey()))->toBeNull();
});

test('F6: 応答サイズ超過は恒久拒否でキャッシュに固定しない', function (): void {
    config(['services.sns_certificate.max_bytes' => 16]);
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsSignatureInvalidException::class);

    expect(Cache::get(snsCertCacheKey()))->toBeNull();
});

test('F7: HTTP エラー応答は一時障害 (503 系)', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response('', 500)]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('F8: 接続失敗は一時障害 (503 系)', function (): void {
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);
});

test('F9: プログラム不具合は写像せず伝播する (要件 7 の核)', function (): void {
    Http::fake(fn () => throw new LogicException('boom'));

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(LogicException::class);
});

test('F10: キャッシュ読みの例外は miss 扱いにする', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    // 値の表だけ無い = 読み書きは失敗するがロックは生きている状態。
    useBrokenSnsCertificateCacheStore(valueTableExists: false, lockTableExists: true);

    // 正のコントロール: 読みが本当に例外になる store であること (miss と区別する)
    expect(fn () => Cache::get(snsCertCacheKey()))->toThrow(QueryException::class);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
    expect(snsCertFetcher()->fetchSerialized(snsCertUrl())->pem)->toBe(SnsTestData::certificatePem());
});

test('F11 (正のコントロール): 壊れた保管方式では書きが実際に例外になる', function (): void {
    // ★これが無いと、次の F11 は「そもそも書きが失敗していない」場合と区別できない。
    useBrokenSnsCertificateCacheStore(valueTableExists: false, lockTableExists: true);

    expect(fn () => Cache::put(snsCertCacheKey(), 'probe', 60))->toThrow(QueryException::class);
});

test('F11: キャッシュ書きの例外は握る (署名検証を止めない)', function (): void {
    useBrokenSnsCertificateCacheStore(valueTableExists: false, lockTableExists: true);

    // 昇格は best-effort。書けなくても署名検証は済んでいるので、**何も投げない**ことが契約である
    // (`throwsNoExceptions()` が「1 つも例外を投げない」を固定する)。
    snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem());
})->throwsNoExceptions();

test('F12: 読み戻せない値は forget して miss 扱いにする', function (): void {
    Http::fake([SnsTestData::CERT_URL => Http::response(SnsTestData::certificatePem(), 200)]);
    Cache::put(snsCertCacheKey(), 'not a pem', 3600);

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
    expect(Cache::get(snsCertCacheKey()))->toBeNull();

    expect(snsCertFetcher()->fetchSerialized(snsCertUrl())->pem)->toBe(SnsTestData::certificatePem());
    Http::assertSentCount(1);
});

test('F13: ロック保持中の別要求は 503 系で自分では取りに行かない', function (): void {
    Http::fake();
    $held = Cache::lock('sns:cert:fetch', 10);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
            ->toThrow(SnsVerificationUnavailableException::class);
        Http::assertNothingSent();
    } finally {
        $held->release();
    }
});

test('F14: ロック取得後の再確認で hit したら fromCache で返し解放する', function (): void {
    Http::fake();
    Cache::put(snsCertCacheKey(), SnsTestData::certificatePem(), 3600);

    $certificate = snsCertFetcher()->fetchSerialized(snsCertUrl());

    expect($certificate->fromCache)->toBeTrue()
        ->and($certificate->pem)->toBe(SnsTestData::certificatePem());
    Http::assertNothingSent();

    // 解放されている (確認のために取った lock は必ず戻す)
    $probe = Cache::lock('sns:cert:fetch', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
});

test('F15: 排他非対応の保管方式は fail-fast (503 に化けさせない)', function (): void {
    Http::fake();
    // storage driver の StorageStore は LockProvider を実装しないため、
    // Repository::__call の素通しで「未定義メソッド呼び出し」の Error になる。
    config(['cache.stores.sns_cert_no_lock' => ['driver' => 'storage', 'disk' => 'local', 'path' => 'sns-cert-test']]);
    config(['cache.default' => 'sns_cert_no_lock']);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))->toThrow(Error::class);

    Http::assertNothingSent();
});

test('F16: ロック基盤の例外は 503 系 (排他なしの取得へ退避しない)', function (): void {
    Http::fake();
    // ロックの表だけ無い = 値の読み書きは生きているがロック取得が失敗する状態。
    useBrokenSnsCertificateCacheStore(valueTableExists: true, lockTableExists: false);

    // 正のコントロール: ロック取得が本当に例外になる store であること (競合と区別する)
    expect(fn () => Cache::lock('sns:cert:probe', 5)->get())->toThrow(QueryException::class);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertNothingSent();
});

test('F17: ロックは成功時も失敗時も解放される', function (array $fake, string $exception): void {
    Http::fake([SnsTestData::CERT_URL => $fake['response']]);

    try {
        snsCertFetcher()->fetchSerialized(snsCertUrl());
        expect($exception)->toBe('');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
    }

    $probe = Cache::lock('sns:cert:fetch', 10);
    expect($probe->get())->toBeTrue();
    $probe->release();
})->with([
    '成功' => [fn (): array => ['response' => Http::response(SnsTestData::certificatePem(), 200)], ''],
    'HTTP 失敗' => [fn (): array => ['response' => Http::response('', 500)], SnsVerificationUnavailableException::class],
    'PEM 不正' => [fn (): array => ['response' => Http::response('not a pem', 200)], SnsSignatureInvalidException::class],
]);

test('F18: 3xx を受理せず Location へ追従しない', function (): void {
    Http::fake([
        SnsTestData::CERT_URL => Http::response('', 302, ['Location' => 'https://evil.example/x.pem']),
    ]);

    expect(fn () => snsCertFetcher()->fetchSerialized(snsCertUrl()))
        ->toThrow(SnsVerificationUnavailableException::class);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === SnsTestData::CERT_URL);
});

test('F19: キャッシュ寿命は設定値どおり', function (): void {
    snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem());

    // 移動前に載っていることを確かめる (別理由の null と区別する)
    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem());

    $this->travel(Config::integer('services.sns_certificate.cache_ttl_seconds') + 1)->seconds();

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBeNull();
});

test('F20: URL が違えばキャッシュキーも違う', function (): void {
    $other = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-other99.pem';

    snsCertFetcher()->rememberVerified(snsCertUrl(), SnsTestData::certificatePem());

    expect(snsCertFetcher()->cached(snsCertUrl()))->toBe(SnsTestData::certificatePem())
        ->and(snsCertFetcher()->cached(snsCertUrl($other)))->toBeNull()
        ->and(snsCertCacheKey())->not->toBe(snsCertCacheKey($other));
});

test('F21: 共用ヘルパは専用 store だけを作り直す', function (): void {
    // ★共通の beforeEach が既にヘルパを呼んでいるので、「既定 store の目印が残る」形では
    //   検査にならない。**別名の store に目印を置いて**それが維持されることを確かめる。
    config(['cache.stores.sns_cert_sentinel' => ['driver' => 'array', 'serialize' => false]]);
    Cache::store('sns_cert_sentinel')->put('sentinel', 'kept', 60);
    Cache::put('discarded', 'value', 60);

    useFreshSnsCertificateCacheStore();

    expect(Cache::store('sns_cert_sentinel')->get('sentinel'))->toBe('kept')
        ->and(Cache::get('discarded'))->toBeNull();

    // 2 回目も専用 store だけが作り直される
    Cache::put('discarded', 'value', 60);
    useFreshSnsCertificateCacheStore();

    expect(Cache::store('sns_cert_sentinel')->get('sentinel'))->toBe('kept')
        ->and(Cache::get('discarded'))->toBeNull();
});
