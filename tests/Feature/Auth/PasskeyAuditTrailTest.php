<?php

declare(strict_types=1);

use App\Enums\SecurityEventType;
use App\Models\Passkey;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/*
 * パスキー増減の監査記録 (audit-cycle-2 Medium / T108 S7)。
 *
 * パスキーは **単独でログインできる強い資格**のため、増減は監査上最重要事象として
 * security_audit_events に残す (セッション乗っ取り後の永続化を事後追跡できるようにする)。
 * 記録経路の網羅性は tests/Architecture/SecurityEventCoverageTest が deny-by-default で固定する。
 *
 * WebAuthn の登録は実ブラウザの authenticator が要るため、ここでは vendor が発火する
 * イベント (Laravel\Passkeys\Events\PasskeyRegistered / PasskeyDeleted) を境界として扱う。
 * 「そのイベントが実際に発火するか」は vendor 側の責務で、削除経路は下の HTTP テストが実走する。
 */

/** password / social を持たず passkey だけでログインするユーザー */
function passkeyAuditUser(int $passkeys = 1): User
{
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->count($passkeys)->for($user)->create();

    return $user;
}

/** 指定 event_type の行数。 */
function passkeyAuditCount(SecurityEventType $type): int
{
    return SecurityAuditEvent::query()->where('event_type', $type->value)->count();
}

test('passkey 登録で passkey_registered が 1 行増える', function (): void {
    $user = passkeyAuditUser();
    $passkey = $user->passkeys()->firstOrFail();

    expect(passkeyAuditCount(SecurityEventType::PasskeyRegistered))->toBe(0);

    PasskeyRegistered::dispatch($user, $passkey);

    expect(passkeyAuditCount(SecurityEventType::PasskeyRegistered))->toBe(1);

    $event = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::PasskeyRegistered->value)
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        // credential 本体 (公開鍵 / signature counter) は載せない
        ->and($event->metadata)->toBe(['passkey_id' => $passkey->getKey()]);
});

test('passkey 削除で passkey_deleted が 1 行増える (HTTP 経路の実走)', function (): void {
    // 手段が残る状態 (passkey 2 本) にして EnsureLoginMethodRemains を通す
    $user = passkeyAuditUser(passkeys: 2);
    $passkey = $user->passkeys()->firstOrFail();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertRedirect();

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);

    $event = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::PasskeyDeleted->value)
        ->firstOrFail();
    expect($event->user_id)->toBe($user->id)
        ->and($event->metadata)->toBe(['passkey_id' => $passkey->getKey()]);
});

test('EnsureLoginMethodRemains に弾かれた削除では監査行が増えない', function (): void {
    // 唯一の passkey = 削除するとログイン手段が 0 になるため guard が transaction ごと巻き戻す。
    // 削除自体が消えるので監査行も消えるのが整合 (意図した挙動として固定する)。
    $user = passkeyAuditUser(passkeys: 1);
    $passkey = $user->passkeys()->firstOrFail();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertRedirect(route('settings.security'));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(0);
});

test('PasskeyDeleted イベント自体からも記録される (listener の直接検証)', function (): void {
    $user = passkeyAuditUser();
    $passkey = $user->passkeys()->firstOrFail();

    PasskeyDeleted::dispatch($user, $passkey);

    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);
});
