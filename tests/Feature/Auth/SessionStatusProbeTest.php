<?php

declare(strict_types=1);

use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Models\User;
use App\Support\Auth\SessionEpoch;
use Illuminate\Support\Str;

/*
 * bfcache 秘匿・再検証 (詳細設計 施策 6) の軽量プローブ endpoint。
 *
 * 契約:
 *   - auth グループの外。guest でも 200 + { "authenticated": false, "sessionEpochMatches": false }
 *     (top-level / $wrap = null)。ステータスコードではなく明示 boolean を見せることで、
 *     クライアント guard が「セッション無効」と「endpoint 不在 / エラー」を取り違えないようにする。
 *   - 応答は `{ "authenticated": bool, "sessionEpochMatches": bool }` のみ = PII を一切含まない。
 *   - 世代の照合に使うのは **要求ヘッダ X-Session-Epoch だけ**。要求の Cookie ヘッダに載る
 *     世代 cookie は画面側から書き換えられる値なので一致判定に使わない。
 *   - 印を運ばない要求は一致にしない (既定は開示しない側)。
 *   - `no-store, private` を Resource 側 (withResponse) で付与する (guest 応答も対象のため
 *     認証済み限定の baseline middleware には委ねない)。
 *   - 2FA 強制中 / recent-auth 期限切れ / 組織未選択でも必ず 200 + boolean。
 *     ここが崩れると guard は「プローブ失敗」に倒れ、秘匿解除されないまま reload ループになる。
 */

test('guest でも 200 で authenticated:false を返す', function (): void {
    $this->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => false, 'sessionEpochMatches' => false]);
});

test('認証済み・印を運ばない要求は authenticated:true / sessionEpochMatches:false (既定は開示しない側)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('応答に no-store と private が付く', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/session/status');

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($response->headers->hasCacheControlDirective('no-store'))
        ->toBeTrue("認証済みプローブ応答に no-store が無い (実際: {$cacheControl})");
    expect($response->headers->hasCacheControlDirective('private'))
        ->toBeTrue("認証済みプローブ応答に private が無い (実際: {$cacheControl})");
});

test('guest 応答にも no-store と private が付く (baseline middleware は認証済み限定のため)', function (): void {
    $response = $this->get('/session/status');

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($response->headers->hasCacheControlDirective('no-store'))
        ->toBeTrue("guest プローブ応答に no-store が無い (実際: {$cacheControl})");
    expect($response->headers->hasCacheControlDirective('private'))
        ->toBeTrue("guest プローブ応答に private が無い (実際: {$cacheControl})");
});

test('応答に PII (email / name) を含まない', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $body = $this->actingAs($owner)->get('/session/status')->getContent();

    expect($body)->toBeString()
        ->and($body)->not->toContain($owner->email)
        ->and($body)->not->toContain($owner->name);
});

test('2FA 強制中の未準拠ユーザーでも 200 + boolean を返す (ゲートで遮断されない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    // owner は 2FA 未設定 (= 未準拠) のまま

    $this->actingAs($owner)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('プローブ route は 2FA ゲートの allowlist に理由付きで登録されている', function (): void {
    expect(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES)
        ->toHaveKey('session.status');
    expect(trim(RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES['session.status']))
        ->not->toBe('');
});

test('recent-auth の鮮度が切れていても 200 + boolean を返す', function (): void {
    [, $owner] = createOrganizationWithOwner();

    // recent_auth_at 未設定 (= step-up 未実施 / 期限切れ相当) の session
    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => now()->subDay()->timestamp])
        ->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('所属組織が無くても 200 + boolean を返す', function (): void {
    $user = User::factory()->create();
    expect($user->organizations()->count())->toBe(0);

    $this->actingAs($user)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('メール未検証ユーザーでも 200 + boolean を返す (verified ゲート外)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('正しい印をヘッダで運ぶと sessionEpochMatches:true になる', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $sessionId = Str::random(40);

    $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->withHeader(SessionEpoch::HEADER_NAME, SessionEpoch::forSession($sessionId))
        ->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => true]);
});

test('別の印・書式違い・空文字・長すぎる値は sessionEpochMatches:false', function (string $submitted): void {
    [, $owner] = createOrganizationWithOwner();
    $sessionId = Str::random(40);

    $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->withHeader(SessionEpoch::HEADER_NAME, $submitted)
        ->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
})->with([
    '別の印' => '0123456789abcdef0123456789abcdef',
    '空文字' => '',
    '大文字' => '0123456789ABCDEF0123456789ABCDEF',
    '長すぎる' => '0123456789abcdef0123456789abcdef0',
]);

test('世代 cookie に正しい印を積んでもヘッダが無ければ sessionEpochMatches:false', function (): void {
    // Cookie ヘッダを照合に使っていないことの behavioral な固定
    // (画面側から書き換えられる値を開示の根拠に混ぜない)。
    [, $owner] = createOrganizationWithOwner();
    $sessionId = Str::random(40);

    $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->withUnencryptedCookie(SessionEpoch::COOKIE_NAME, SessionEpoch::forSession($sessionId))
        ->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true, 'sessionEpochMatches' => false]);
});

test('guest が正しい印を運んでも authenticated は false のまま', function (): void {
    $sessionId = Str::random(40);

    $this->withCookie((string) config('session.cookie'), $sessionId)
        ->withHeader(SessionEpoch::HEADER_NAME, SessionEpoch::forSession($sessionId))
        ->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => false, 'sessionEpochMatches' => true]);
});

test('応答本文に印そのものが現れない (値を返していないことの固定)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $sessionId = Str::random(40);
    $epoch = SessionEpoch::forSession($sessionId);

    $body = $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->withHeader(SessionEpoch::HEADER_NAME, $epoch)
        ->get('/session/status')
        ->getContent();

    expect($body)->toBeString()
        ->and($body)->not->toContain($epoch);
});
