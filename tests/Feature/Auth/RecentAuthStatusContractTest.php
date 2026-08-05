<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\SocialAccount;
use App\Models\User;

/*
 * `/recent-auth/status` の **JSON 契約**を過不足なく固定する (T107 施策 3)。
 *
 * クライアント (resources/js/lib/recent-auth.ts) は strict parse に変えた:
 * field が欠けた応答を既定値で補完すると「サーバは手段があると言っているのに UI に出ない」
 * = 監査 F-1 と同じ詰みが通信境界で再演するため、契約不成立は null (delegated) に倒す。
 *
 * したがって **キー集合の一致**がクライアント側の前提そのものになる。
 * サーバが field を増やす/減らす/名前を変えると本テストが落ち、TS 側の parse を
 * 同じ PR で更新する判断が強制される。
 */

/** status を JSON で取得する (Cache 制御ヘッダも含めて検査する) */
function fetchStatusJson(User $user): array
{
    $response = test()->actingAs($user)->getJson('/recent-auth/status');
    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $decoded = $response->json();
    expect($decoded)->toBeArray();

    return $decoded;
}

test('top-level のキー集合が契約と一致する (過不足を許さない)', function (): void {
    $user = User::factory()->create();

    $body = fetchStatusJson($user);

    expect(array_keys($body))->toEqualCanonicalizing([
        'recent',
        'passwordSet',
        'availableProviders',
        'passkeyAvailable',
        'canSatisfy',
        'confirmedAt',
    ]);
});

/*
 * `data` ラップが入ると TS の strict parse は即 null になり、**全画面が delegated へ落ちる**
 * (再認証モーダルが一切出なくなる)。RecentAuthStatusResource::$wrap = null の維持を固定する。
 */
test('top-level に data ラップが無い', function (): void {
    $user = User::factory()->create();

    $body = fetchStatusJson($user);

    expect($body)->not->toHaveKey('data');
    expect($body)->toHaveKey('recent');
});

test('各値の型が契約どおり (bool / array / int|null)', function (): void {
    $user = User::factory()->create();

    $body = fetchStatusJson($user);

    expect($body['recent'])->toBeBool();
    expect($body['passwordSet'])->toBeBool();
    expect($body['passkeyAvailable'])->toBeBool();
    expect($body['canSatisfy'])->toBeBool();
    expect($body['availableProviders'])->toBeArray();
    expect($body['confirmedAt'])->toBeNull();
});

test('鮮度成立時は confirmedAt が int になる', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->getJson('/recent-auth/status');

    $response->assertOk();
    expect($response->json('recent'))->toBeTrue();
    expect($response->json('confirmedAt'))->toBeInt();
});

test('SSO 連携ありユーザーの provider 要素キーが契約と一致する', function (): void {
    $user = User::factory()->ssoOnly()->create();
    SocialAccount::factory()->for($user)->create(['provider' => 'google']);

    $body = fetchStatusJson($user);

    expect($body['availableProviders'])->toHaveCount(1);
    $provider = $body['availableProviders'][0];
    expect(array_keys($provider))->toEqualCanonicalizing(['provider', 'capability', 'reauthUrl']);
    expect($provider['provider'])->toBeString();
    expect($provider['capability'])->toBeString();
    expect($provider['reauthUrl'])->toBeString();
});

test('passkey 登録済みユーザーは passkeyAvailable=true になる (passkey-only でも canSatisfy)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->for($user)->create();

    $body = fetchStatusJson($user);

    expect($body['passwordSet'])->toBeFalse();
    expect($body['passkeyAvailable'])->toBeTrue();
    expect($body['canSatisfy'])->toBeTrue();
});
