<?php

declare(strict_types=1);

use App\Models\SecurityAuditEvent;
use App\Models\SocialAccount;
use App\Models\User;

test('再認証 (step-up) なしではアカウント削除できない', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    // recent-auth が確認画面へ redirect する
    $response->assertRedirect();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('step-up 済みならアカウントを削除でき、関連データが掃除される', function (): void {
    $user = User::factory()->create();
    $social = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-123']);
    $social->user()->associate($user);
    $social->save();

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    $this->assertGuest();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    expect(SocialAccount::query()->whereKey($social->id)->exists())->toBeFalse();

    // 削除イベントは user_id が null 化されて残る (nullOnDelete)
    expect(
        SecurityAuditEvent::query()->where('event_type', 'account_deleted')->exists(),
    )->toBeTrue();
});
