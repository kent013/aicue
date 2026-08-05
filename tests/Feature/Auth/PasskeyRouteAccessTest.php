<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\User;

/*
 * passkey route の到達制御 (未認証 / step-up / 他人の credential / キャッシュ / throttle)。
 *
 * WebAuthn ceremony 自体はブラウザ API を要するため自動化しない。
 * ここで固定するのは **ceremony に到達する前の関門**。
 */

test('未認証は passkey 登録 options に到達できない', function (): void {
    $this->get('/user/passkeys/options')->assertRedirect('/login');
});

test('未認証は passkey 削除に到達できない', function (): void {
    $this->delete('/user/passkeys/1')->assertRedirect('/login');
});

test('recent-auth 鮮度切れの Inertia mutation は 409 (step-up 要求)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/user/passkeys', ['name' => 'テスト'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');
});

test('recent-auth 鮮度切れの登録 options 取得は confirm 画面へ誘導される', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/user/passkeys/options')
        ->assertRedirect(route('recent-auth.confirm'));
});

/*
 * **他人の passkey と不在 id が同じ 404 になること** (AGENTS.md セキュリティ不変条件 2)。
 * vendor 実装のままだと controller の `abort_unless(..., 403)` に到達し、
 * 403 と 404 の差で他人の passkey の存在が漏れる。
 */
test('他人の passkey の削除は 404 (403 にしない)', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $passkey = Passkey::factory()->for($other)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertNotFound();

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
});

test('不在 id の削除も同じ 404 (存在を漏らさない)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete('/user/passkeys/999999')
        ->assertNotFound();
});

test('非数値 id の削除は 500 ではなく 404 (pgsql 22P02 の回避)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete('/user/passkeys/abc')
        ->assertNotFound();
});

test('bigint 範囲外の id の削除も 404 (pgsql 22003 の回避)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete('/user/passkeys/99999999999999999999999999')
        ->assertNotFound();
});

test('guest の login options 応答は no-store (challenge をキャッシュさせない)', function (): void {
    $response = $this->get('/passkeys/login/options');

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('passkeys limiter が未認証の challenge 発行を絞る', function (): void {
    // limiter は 10/min。11 回目で 429 になる
    for ($i = 0; $i < 10; $i++) {
        $this->get('/passkeys/login/options')->assertOk();
    }

    $this->get('/passkeys/login/options')->assertStatus(429);
});

/*
 * **登録 POST の request shape を vendor 契約に固定する**。
 *
 * vendor の PasskeyRegistrationRequest は
 * `name` + `credential.{id,rawId,type,response}` の **nested** 形を要求する。
 * client (PasskeySection.svelte) が `{ name, credential }` で送ることと対になっており、
 * ここがズレると **登録が全面的に失敗する** (WebAuthn ceremony は自動化できないため、
 * shape の食い違いは validation 段でしか検出できない)。
 */
test('登録 POST は nested な credential を要求する (flat 展開は validation で落ちる)', function (): void {
    $user = User::factory()->create();

    // 設計書の初稿にあった flat 形 ({ name, ...credential }) は credential 必須で落ちる
    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->postJson('/user/passkeys', [
            'name' => 'テスト',
            'id' => 'abc',
            'rawId' => 'abc',
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'x'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('credential');
});

test('登録 POST の nested credential は rules を通過し ceremony 検証まで進む', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->postJson('/user/passkeys', [
            'name' => 'テスト',
            'credential' => [
                'id' => 'abc',
                'rawId' => 'abc',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'x'],
            ],
        ]);

    // rules() は通過し、**passedValidation() の ceremony デシリアライズ**で落ちる。
    // 「必須項目が足りない」ではなく「中身が不正」という別の 422 であることを
    // メッセージの完全一致で固定する (`not->toBe(...)` だとキー不在でも通り空振りする)。
    $response->assertStatus(422);
    $response->assertJsonPath('errors.credential.0', 'Invalid credential format.');
    // name も rules を通過している (nested 形が rules 段で拒否されていない証明)
    $response->assertJsonMissingValidationErrors(['name', 'credential.id', 'credential.rawId', 'credential.type', 'credential.response']);
});

/*
 * ログイン route は **guest session に鮮度を残さない**。
 * (VerifyPasskey は allowsLogin より前に PasskeyVerified を dispatch するため、
 *  StampRecentAuthOnPasskeyVerified の本人性バインドが唯一の防御線になる)
 */
test('passkey login の失敗は guest session に recent_auth を残さない', function (): void {
    $response = $this->postJson('/passkeys/login', [
        'credential' => [
            'id' => 'abc',
            'rawId' => 'abc',
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'x'],
        ],
    ]);

    $response->assertStatus(422);
    expect(session()->has('recent_auth_at'))->toBeFalse();
    $this->assertGuest();
});
