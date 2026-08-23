<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * NoStoreCacheHeadersForAuthenticatedPages (P3-a baseline) の契約検証。
 *
 * 契約:
 *  - 認証済み (リクエスト時点 or 応答時点) の web 応答で、応答が `no-store` directive を
 *    持たないなら Cache-Control を `no-store, private` で置換する。
 *  - 既に `no-store` を持つ応答は untouched (既存 4 経路の完全値ピンは
 *    ExistingNoStoreContractTest)。
 *  - guest / 公開ページ / session を持たない stateless block は対象外。
 *
 * 目的はログアウト後の「戻る」で認証済み画面 (PII) が bfcache / HTTP キャッシュから
 * 再表示されるのを防ぐこと。
 */

/** Cache-Control の directive 集合 (順序非依存) */
function noStoreBaselineDirectives(TestResponse $response): array
{
    $value = (string) $response->headers->get('Cache-Control');

    if (trim($value) === '') {
        return [];
    }

    return array_map(
        static fn (string $part): string => trim($part),
        explode(',', $value),
    );
}

function noStoreBaselineHasNoStore(TestResponse $response): bool
{
    return in_array('no-store', noStoreBaselineDirectives($response), true);
}

test('認証済み Inertia 応答には no-store, private が付く', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard");

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('guest の公開ページ (LP) には付与されない', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
        'guest の公開ページは bfcache / 共有キャッシュの恩恵を維持するため対象外',
    );
});

test('guest の login 画面には付与されない', function (): void {
    $response = $this->get('/login');

    $response->assertOk();
    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
        'login 画面は guest 応答のため対象外',
    );
});

test('stateless block (SEO/robots) には付与されない', function (): void {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
        'StartSession を外した stateless 公開配信は対象外 (hasSession() が false)',
    );
});

test('logout POST の redirect 応答にも付与される (リクエスト時点の認証状態で判定)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post('/logout');

    // $next 通過後は guard 上の user が null になるため、リクエスト時点の捕捉が load-bearing
    expect(noStoreBaselineHasNoStore($response))->toBeTrue(
        'logout の redirect 応答が no-store を持たないと「戻る」で認証済み画面が復元されうる',
    );
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('login POST の応答にも付与される (応答時点の認証状態で判定)', function (): void {
    User::factory()->create(['email' => 'nostore-login@example.com']);

    $response = $this->post('/login', [
        'email' => 'nostore-login@example.com',
        'password' => 'password',
    ]);

    // リクエスト時点は guest。応答時点で認証済みになるため保護側に倒す
    expect(noStoreBaselineHasNoStore($response))->toBeTrue(
        'login 応答は応答時点で認証済みのため付与対象',
    );
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('認証済みで no-store を持たない応答は矛盾 directive ごと置換される', function (): void {
    $user = User::factory()->create();

    Route::middleware('web')->get('/__no-store-probe/cacheable', static fn () => response('ok')
        ->withHeaders(['Cache-Control' => 'public, max-age=600']));

    $response = $this->actingAs($user)->get('/__no-store-probe/cacheable');

    $response->assertOk();
    $response->assertHeader('Cache-Control', 'no-store, private');
});

test('認証済みでも既に no-store を持つ応答は書き換えない (untouched 契約)', function (): void {
    $user = User::factory()->create();

    Route::middleware('web')->get('/__no-store-probe/inner-no-store', static fn () => response('ok')
        ->withHeaders(['Cache-Control' => 'no-store, max-age=0']));

    $response = $this->actingAs($user)->get('/__no-store-probe/inner-no-store');

    $response->assertOk();
    // 置換していたら max-age=0 は消える。残存が untouched の証拠
    expect(noStoreBaselineDirectives($response))->toContain('max-age=0');
    expect(noStoreBaselineDirectives($response))->toContain('no-store');
});

test('guest は no-store を持たない応答でも置換されない', function (): void {
    Route::middleware('web')->get('/__no-store-probe/guest-cacheable', static fn () => response('ok')
        ->withHeaders(['Cache-Control' => 'public, max-age=600']));

    $response = $this->get('/__no-store-probe/guest-cacheable');

    $response->assertOk();
    expect(noStoreBaselineHasNoStore($response))->toBeFalse(
        'guest 応答は対象外 (認証状態でのみ判定する)',
    );
});
