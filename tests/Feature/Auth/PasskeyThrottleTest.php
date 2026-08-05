<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\User;

/*
 * passkey.destroy の throttle (audit-cycle-2 Medium-2 / T108 S8)。
 *
 * vendor (Fortify) の passkey middleware は destroy に throttle を付けないため、
 * EnsureLoginMethodRemains の DB::transaction + User 行 lockForUpdate を
 * 認証済みユーザーが無制限に叩けた。他の passkey route と同じ 10/min に揃える。
 *
 * throttle は binding より前 (pre-binding 短絡) なので、429 は全 id で同一に返る =
 * **新しい存在オラクルを作らない**ことも同時に固定する。
 */

/** passkey だけを持つユーザー (削除しても手段が残るよう複数持たせる)。 */
function passkeyThrottleUser(int $passkeys = 3): User
{
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->count($passkeys)->for($user)->create();

    return $user;
}

test('passkey.destroy は 11 回目で 429 になる (10/min)', function (): void {
    $user = passkeyThrottleUser();

    for ($i = 1; $i <= 10; $i++) {
        $response = $this->actingAs($user)
            ->withSession(freshRecentAuthSession())
            ->from(route('settings.security'))
            ->delete('/user/passkeys/999999999');
        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で早すぎる 429");
    }

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete('/user/passkeys/999999999')
        ->assertStatus(429);
});

test('429 到達回数は 不在 id と 他人の passkey id で同一 (新しい存在オラクルを作らない)', function (): void {
    $victim = passkeyThrottleUser();
    $othersPasskeyId = (string) $victim->passkeys()->firstOrFail()->getKey();

    $attacker = passkeyThrottleUser();

    // 他人の passkey id で 10 回 → 11 回目 429
    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($attacker)
            ->withSession(freshRecentAuthSession())
            ->from(route('settings.security'))
            ->delete("/user/passkeys/{$othersPasskeyId}");
    }
    $othersResult = $this->actingAs($attacker)
        ->withSession(freshRecentAuthSession())
        ->delete("/user/passkeys/{$othersPasskeyId}");

    // 別ユーザーで不在 id を 10 回 → 11 回目 429 (同じ回数で同じ status)
    $other = passkeyThrottleUser();
    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($other)
            ->withSession(freshRecentAuthSession())
            ->from(route('settings.security'))
            ->delete('/user/passkeys/999999999');
    }
    $missingResult = $this->actingAs($other)
        ->withSession(freshRecentAuthSession())
        ->delete('/user/passkeys/999999999');

    expect($othersResult->getStatusCode())->toBe(429)
        ->and($missingResult->getStatusCode())->toBe(429);
});

test('bucket は別ユーザー間で共有されない (limiter キーが user 単位であることの証明)', function (): void {
    $userA = passkeyThrottleUser();
    $userB = passkeyThrottleUser();

    for ($i = 1; $i <= 11; $i++) {
        $this->actingAs($userA)
            ->withSession(freshRecentAuthSession())
            ->from(route('settings.security'))
            ->delete('/user/passkeys/999999999');
    }

    // userA は使い切っているが userB は影響を受けない
    $this->actingAs($userB)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete('/user/passkeys/999999999')
        ->assertStatus(404);
});
