<?php

declare(strict_types=1);

use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Models\User;

/*
 * bfcache 秘匿・再検証 (詳細設計 施策 6) の軽量プローブ endpoint。
 *
 * 契約:
 *   - auth グループの外。guest でも 200 + { "authenticated": false } (top-level / $wrap = null)。
 *     ステータスコードではなく明示 boolean を見せることで、クライアント guard が
 *     「セッション無効」と「endpoint 不在 / エラー」を取り違えないようにする。
 *   - 応答は `{ "authenticated": bool }` のみ = PII を一切含まない。
 *   - `no-store, private` を Resource 側 (withResponse) で付与する (guest 応答も対象のため
 *     認証済み限定の baseline middleware には委ねない)。
 *   - 2FA 強制中 / recent-auth 期限切れ / 組織未選択でも必ず 200 + boolean。
 *     ここが崩れると guard は「プローブ失敗」に倒れ、秘匿解除されないまま reload ループになる。
 */

test('guest でも 200 で authenticated:false を返す', function (): void {
    $this->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => false]);
});

test('認証済みは 200 で authenticated:true を返す (top-level / data ラップなし)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true]);
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
        ->assertExactJson(['authenticated' => true]);
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
        ->assertExactJson(['authenticated' => true]);
});

test('組織未選択 (current_organization_id が null) でも 200 + boolean を返す', function (): void {
    $user = User::factory()->create();
    expect($user->current_organization_id)->toBeNull();

    $this->actingAs($user)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true]);
});

test('メール未検証ユーザーでも 200 + boolean を返す (verified ゲート外)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/session/status')
        ->assertOk()
        ->assertExactJson(['authenticated' => true]);
});
