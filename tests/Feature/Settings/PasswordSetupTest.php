<?php

declare(strict_types=1);

use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/*
 * パスワード**初回設定** (POST /settings/password。T107 施策 6)。
 *
 * これが無いと「パスワードを設定してください」と案内する CTA (ログイン手段保持 guard の
 * 拒否 Alert / 再認証モーダルの回復導線) がどこにも着地せず、踏破不能 CTA になる (監査 F-2)。
 *
 * 設計上の非交渉点:
 *  - **recent-auth (step-up) 必須**: 認証手段を永続的に増やす操作であり、
 *    セッション奪取からの乗っ取り永続化を防ぐ。付与漏れの機械的検出点は
 *    tests/Architecture/RecentAuthRouteTest.php の allowlist。
 *  - **password 設定済みは fail-closed で拒否**: current_password 必須の変更経路
 *    (Fortify PUT /user/password) を骨抜きにしない。
 *  - **EnsureLoginMethodRemains は付けない**: あれは手段を「減らす」操作の関門であり方向が逆。
 */

const STRONG_PASSWORD = 'Str0ngPassphrase99';

/** password 未設定 (SSO-only) ユーザー */
function passwordlessUser(): User
{
    return User::factory()->ssoOnly()->create();
}

/* ------------------------------------------------------------ 正常系 */

test('password 未設定 + recent-auth fresh なら設定できる', function (): void {
    $user = passwordlessUser();
    expect($user->hasPassword())->toBeFalse();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => STRONG_PASSWORD]);

    $response->assertRedirect(route('settings'));
    $response->assertSessionHas('success');

    $user->refresh();
    expect($user->hasPassword())->toBeTrue();
    expect(Hash::check(STRONG_PASSWORD, (string) $user->password))->toBeTrue();
});

test('設定成功で password_set の監査イベントが 1 件記録される', function (): void {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertSessionHasNoErrors();

    $events = SecurityAuditEvent::query()
        ->where('event_type', 'password_set')
        ->where('user_id', $user->getKey())
        ->get();

    expect($events)->toHaveCount(1);
});

/*
 * 他デバイス失効 (PasswordCredentialService::afterPersist)。
 * password material の確定は変更時と同じ意味を持つため、初回設定でも他デバイスを切る。
 */
test('設定成功で他デバイスの session 行が削除される (現在の session は残る)', function (): void {
    config()->set('session.driver', 'database');
    $user = passwordlessUser();

    $this->actingAs($user)->withSession(freshRecentAuthSession())->get(route('settings'));
    $currentSessionId = session()->getId();

    // 他デバイスの session 行を模す
    DB::table('sessions')->insert([
        'id' => 'other-device-session-id',
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.1',
        'user_agent' => 'other',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-device-session-id')->exists())->toBeFalse();
    expect($currentSessionId)->not->toBe('other-device-session-id');
});

/* ------------------------------------------------------------ fail-closed */

test('password 設定済みユーザーは 422 で拒否され hash が変わらない', function (): void {
    $user = User::factory()->create(['password' => Hash::make('existing-password')]);
    $before = (string) $user->password;

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertSessionHasErrors('password');

    $user->refresh();
    expect((string) $user->password)->toBe($before);
    expect(Hash::check('existing-password', (string) $user->password))->toBeTrue();
});

test('password 設定済みの純 XHR は 422 (JSON)', function (): void {
    $user = User::factory()->create(['password' => Hash::make('existing-password')]);

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->postJson('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

/* ------------------------------------------------------------ step-up 必須 */

test('recent-auth 無しの Inertia POST は 409 recent_auth_required', function (): void {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertStatus(409)
        ->assertJsonPath('code', 'recent_auth_required')
        ->assertJsonPath('redirect', route('recent-auth.confirm'));

    expect($user->refresh()->hasPassword())->toBeFalse();
});

test('recent-auth 無しの通常 POST は confirm 画面へ 302 (intended 保持)', function (): void {
    $user = passwordlessUser();
    $origin = config('app.url');

    $this->actingAs($user)
        ->withHeaders(['referer' => $origin.'/settings'])
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertRedirect(route('recent-auth.confirm'));

    expect(session('url.intended'))->toBe($origin.'/settings');
    expect($user->refresh()->hasPassword())->toBeFalse();
});

test('未認証は login へ redirect', function (): void {
    $this->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertRedirect(route('login'));
});

/* ------------------------------------------------------------ 入力検証 / throttle */

test('弱いパスワードは 422 (PasswordPolicy 経由)', function (): void {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => 'short'])
        ->assertSessionHasErrors('password');

    expect($user->refresh()->hasPassword())->toBeFalse();
});

test('throttle 超過で 429 (6/分)', function (): void {
    $user = passwordlessUser();

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($user)
            ->withSession(freshRecentAuthSession())
            ->from(route('settings'))
            ->post('/settings/password', ['password' => 'short']);
    }

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => 'short'])
        ->assertStatus(429);
});

/* ------------------------------------------------------------ 画面側の出し分け根拠 */

test('/settings の Inertia prop に hasPassword が載る (カードの出し分け根拠)', function (): void {
    $withPassword = User::factory()->create();
    $this->actingAs($withPassword)
        ->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Index')
            ->where('hasPassword', true));

    $withoutPassword = passwordlessUser();
    $this->actingAs($withoutPassword)
        ->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Index')
            ->where('hasPassword', false));
});

test('設定後に再訪すると hasPassword が true になる (状態と UI が一致する)', function (): void {
    $user = passwordlessUser();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings'))
        ->post('/settings/password', ['password' => STRONG_PASSWORD])
        ->assertSessionHasNoErrors();

    $this->actingAs($user->refresh())
        ->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPassword', true));
});
