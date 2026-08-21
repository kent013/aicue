<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
 * profile 更新 (PUT /user/profile-information) の email 変更時 step-up ゲート
 * (RequireRecentAuthOnEmailChange)。氏名のみ変更は素通し、email 変更は recent-auth
 * を要求する条件付きゲートの分岐を固定する (詳細設計 T031 テストマトリクス 1a/1b/2/3/5)。
 *
 * 委譲先 RequireRecentAuth の 409/302 生成ロジックは RecentAuthTest が担保するため、
 * ここでは「email 変更で gate される / 氏名のみ・欠落・非 string は gate されない」の
 * 条件付き委譲契約と、旧アドレス通知 + email_verified_at null 化の回帰を検証する。
 */

test('1a: stale + email 変更 (Inertia mutation) は 409 で反映されない', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->put('/user/profile-information', [
            'name' => '新しい名前',
            'email' => 'new@example.com',
        ]);

    $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');

    $user->refresh();
    expect($user->email)->toBe('old@example.com');
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('1b: stale + email 変更 (通常) は confirm へ redirect で反映されない', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $response = $this->actingAs($user)->put('/user/profile-information', [
        'name' => '新しい名前',
        'email' => 'new@example.com',
    ]);

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHas('url.intended');

    $user->refresh();
    expect($user->email)->toBe('old@example.com');
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('2: stale + 氏名のみ変更は gate されず成功し email 不変', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'me@example.com']);

    $response = $this->actingAs($user)->put('/user/profile-information', [
        'name' => '新しい名前',
        'email' => 'me@example.com',
    ]);

    // recent-auth confirm への redirect ではない (= gate されていない)
    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));
    $response->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('新しい名前');
    expect($user->email)->toBe('me@example.com');
    Notification::assertNothingSent();
});

test('3: fresh + email 変更は成功し旧アドレス通知 + 再検証要求', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ]);

    // gate を通過して action が実行される (confirm への redirect ではない)
    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));
    $response->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChangedSecurityNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'old@example.com',
    );
});

test('F-4-01: stale → 再認証完了 → 元操作再送で verification.notice + success flash', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    // (1) stale セッションで email 変更 PUT (Inertia mutation) → 409 で反映されない (1a と同契約)
    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required');

    // (2) 同一セッションで再認証 (正しいパスワード) → 鮮度が stamp される。
    // 直前の 409 (Inertia mutation) が dropped_mutation を stash するため、confirmPassword は
    // 204 ではなく intended への redirect を返す (詳細は RecentAuthTest が担保)。ここでは
    // 「再認証で鮮度が stamp される」ことだけ固定し、次段で元操作の再送を通す。
    $this->actingAs($user)
        ->postJson('/recent-auth/password', ['password' => 'password']);
    expect(session('recent_auth_at'))->toBeInt();

    // (3) 元の email 変更 PUT を再送 → gate 通過し verification.notice + success へ着地
    $response = $this->actingAs($user)
        ->from('/settings')
        ->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ]);

    $response->assertRedirect(route('verification.notice'));
    $response->assertSessionHas(
        'success',
        'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。',
    );

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('5: stale + email 欠落/非string は recent-auth で gate されず email 不変', function (array $payload): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'me@example.com']);

    $response = $this->actingAs($user)->putJson('/user/profile-information', $payload);

    // 検証する不変条件は「recent-auth ゲート応答 (409 / recent-auth.confirm への redirect)
    // ではないこと」= middleware が非 string email を email 変更とみなして委譲しない (fail-safe)。
    // 非 string email は email 変更を起こせないため後続へ素通しする:
    //   - 欠落: action の Validator が 422 (required)
    //   - 配列: Fortify ProfileInformationController が Str::lower(array) で 500
    //     (本タスク以前からの Fortify 既存挙動。recent-auth gate ではない点は同じ)
    // いずれも email は変更されず通知も飛ばない。ここでは gate されない契約を固定する。
    expect($response->status())->not->toBe(409);
    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));

    expect($user->refresh()->email)->toBe('me@example.com');
    Notification::assertNothingSent();
})->with([
    'email 欠落' => [['name' => '新しい名前']],
    'email 非string (配列)' => [['name' => '新しい名前', 'email' => ['x@example.com']]],
]);
