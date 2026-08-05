<?php

declare(strict_types=1);

use App\Listeners\Auth\StampRecentAuthOnLogin;
use App\Models\Passkey;
use App\Models\SocialAccount;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Features;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Mockery\MockInterface;

/*
 * generic recent-auth (step-up 再認証) 機構:
 * RequireRecentAuth middleware / status precheck / password satisfier / SSO step-up intent /
 * fresh login stamp (StampRecentAuthOnLogin)。
 */

/** SSO step-up 用の Socialite callback mock (SocialAuthTest の helper と名前衝突させない) */
function fakeStepUpSocialiteCallback(string $providerUserId): void
{
    /** @var SocialiteUserContract&MockInterface $socialiteUser */
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
    $socialiteUser->shouldReceive('getEmail')->andReturn('step-up@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('StepUp User');

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

function linkGoogleAccount(User $user, string $providerUserId): void
{
    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => $providerUserId]);
    $account->user()->associate($user);
    $account->save();
}

/* ---------------------------------------------------------------- middleware */

test('鮮度なしの通常遷移は confirm 画面へ 302 (dropped_mutation flag 付き)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHas('recent_auth.dropped_mutation', true);
});

test('鮮度なしの XHR は 409 + recent_auth_required code (no-store)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/settings/account');

    $response->assertStatus(409)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('鮮度なしの Inertia mutation は 409 (302 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete('/settings/account');

    $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');
});

/*
 * 409 の着地契約 (T107 施策 4)。
 *
 * 409 を拾うクライアント (lib/recent-auth.ts の単一ハンドラ) は confirm 画面へ visit する。
 * 302 分岐と同じ着地情報を残さないと、confirm 成功後に dashboard へ落ち、
 * 「先ほどの操作は実行されていません」の案内も出ない = 操作のサイレント喪失になる。
 */
test('鮮度なしの Inertia mutation の 409 は url.intended と dropped_mutation を残す', function (): void {
    $user = User::factory()->create();
    $origin = config('app.url');

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'referer' => $origin.'/settings'])
        ->delete('/settings/account')
        ->assertStatus(409);

    expect(session('url.intended'))->toBe($origin.'/settings');
    expect(session('recent_auth.dropped_mutation'))->toBeTrue();
});

test('409 の intended も same-origin referer のみ採用する (open redirect 防止)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'referer' => 'https://evil.example.com/phish'])
        ->delete('/settings/account')
        ->assertStatus(409);

    expect(session('url.intended'))->toBe(route('dashboard'));
});

/*
 * **純 XHR の 409 では intended を書き換えない**。クライアントが自前で pending action を
 * 再開するため、書くと他フロー (ログイン直後の着地等) の intended を汚す。
 */
test('純 XHR の 409 は url.intended を書き換えない', function (): void {
    $user = User::factory()->create();
    $origin = config('app.url');

    $this->actingAs($user)
        ->withSession(['url.intended' => $origin.'/manuals'])
        ->withHeaders(['referer' => $origin.'/settings'])
        ->deleteJson('/settings/account')
        ->assertStatus(409);

    expect(session('url.intended'))->toBe($origin.'/manuals');
    expect(session('recent_auth.dropped_mutation'))->toBeNull();
});

test('409 経路でも confirm 成功後は元画面へ戻り操作未実行の案内が出る', function (): void {
    $user = User::factory()->create(['password' => Hash::make('current-password')]);
    $origin = config('app.url');

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'referer' => $origin.'/settings'])
        ->delete('/settings/account')
        ->assertStatus(409);

    $this->flushHeaders();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/recent-auth/password', ['password' => 'current-password'])
        ->assertRedirect($origin.'/settings')
        ->assertSessionHas('info');

    // one-shot flag は消費済み (次回 step-up に持ち越さない)
    expect(session('recent_auth.dropped_mutation'))->toBeNull();
});

test('stale な recent_auth_at (timeout 超過) はブロックされる', function (): void {
    $user = User::factory()->create();
    $timeout = config()->integer('auth.recent_auth_timeout');

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time() - $timeout - 1])
        ->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('外部 origin の referer は intended に採用されない (open redirect 防止)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['referer' => 'https://evil.example.com/phish'])
        ->delete('/settings/account')
        ->assertRedirect(route('recent-auth.confirm'));

    expect(session('url.intended'))->toBe(route('dashboard'));
});

/* ------------------------------------------- fortify password.confirm 救済 redirect */

test('GET /user/confirm-password 直アクセスは recent-auth confirm へ 302 (500 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'));
});

test('GET /user/confirm-password は追従すると 200 で ConfirmRecentAuth フォームが出る', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/user/confirm-password');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true));
});

test('GET /user/confirm-password は未ログインなら login へ redirect (既存 auth ガード)', function (): void {
    $this->get('/user/confirm-password')->assertRedirect(route('login'));
});

test('GET /user/confirm-password の救済 redirect は再認証の stamp をしない', function (): void {
    // 誤用防止の回帰ガード: この redirect は「画面への誘導」であり、password.confirm
    // middleware 互換 (auth.password_confirmed_at) も recent-auth 鮮度 (recent_auth_at) も
    // 付与しない (Codex 詳細レビュー Round 1 Warning 対応)。
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'))
        ->assertSessionMissing('auth.password_confirmed_at')
        ->assertSessionMissing('recent_auth_at');
});

test('GET /user/confirm-password の救済 redirect はクエリや url.intended に依らず固定先へ向かう', function (): void {
    // open redirect 否定の回帰ガード: この redirect は常に recent-auth.confirm への
    // 内部固定リダイレクトであり、リクエストのクエリパラメータや session の url.intended を
    // 参照しない (Codex 最終実装レビュー Round 1 Suggestion 対応)。
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['url.intended' => 'https://evil.example/phish'])
        ->get('/user/confirm-password?redirect=https://evil.example/phish&next=/admin');

    $response->assertRedirect(route('recent-auth.confirm'));
});

/* ---------------------------------------------------------------- confirm 画面 / status */

test('confirm 画面は passwordSet / availableProviders / canSatisfy を返す', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');

    $response = $this->actingAs($user)->get('/recent-auth/confirm');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true)
            ->where('availableProviders.0.provider', 'google')
            ->where('availableProviders.0.reauthUrl', route('social.redirect', ['provider' => 'google', 'intent' => 'step-up'])));
});

test('password 未設定かつ利用可能な再認証 provider が無いユーザーは canSatisfy=false', function (): void {
    // 「SSO 専用ユーザー」ではなく「password 未設定 かつ 利用可能な再認証 provider なし」という
    // **状態**。provider が生きている通常の SSO ユーザー (canSatisfy=true) と混同しない。
    // この状態の confirm 画面が案内する回復手順は RecentAuthPasswordRecoveryTest が端まで固定する。
    $user = User::factory()->ssoOnly()->create();

    $this->actingAs($user)->get('/recent-auth/confirm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', false)
            ->where('availableProviders', [])
            ->where('canSatisfy', false));
});

test('ログイン済みユーザーは GET /forgot-password のフォームに到達できない (guest ゲート)', function (): void {
    // Fortify は /forgot-password を `guest` middleware 付きで登録している。認証済み画面
    // (ConfirmRecentAuth 等) から /forgot-password へリンクすると RedirectIfAuthenticated に
    // 弾かれてフォームに到達しない = 踏破不能 CTA になる、という根拠を仕様として固定する。
    // redirect 先は RedirectIfAuthenticated::defaultRedirectUri() 依存のため pin しない。
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/forgot-password');

    expect($response->isRedirect())->toBeTrue();
    $response->assertStatus(302);
});

test('status は鮮度と satisfier 情報を返す (no-store)', function (): void {
    $user = User::factory()->create();

    // 鮮度なし
    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'recent' => false,
            'passwordSet' => true,
            'canSatisfy' => true,
            'confirmedAt' => null,
        ]);

    // 鮮度あり (confirmedAt は epoch を露出)
    $at = time() - 10;
    $this->actingAs($user)
        ->withSession(['recent_auth_at' => $at])
        ->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJson(['recent' => true, 'confirmedAt' => $at]);
});

test('status: 連携 provider は capability に応じて再SSO 候補になる', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('availableProviders.0.provider', 'google')
        ->assertJsonPath('availableProviders.0.capability', 'fresh_auth_prompt_only');

    // capability 未宣言 (identity_only 扱い) の provider は候補から除外される (fail-closed)
    config(['template.social_providers.google.capability' => null]);
    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('availableProviders', []);
});

/* ---------------------------------------------------------------- password satisfier */

test('password satisfier: XHR は 204 で鮮度が stamp される', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/recent-auth/password', [
        'password' => 'password',
    ]);

    $response->assertNoContent();
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('password');
});

test('password satisfier: 誤ったパスワードは 422 で stamp されない', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/recent-auth/password', [
        'password' => 'wrong-password',
    ])->assertStatus(422);

    expect(session('recent_auth_at'))->toBeNull();
});

test('password satisfier: Inertia は intended へ redirect し dropped_mutation を消費する', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession([
            'url.intended' => '/settings',
            'recent_auth.dropped_mutation' => true,
        ])
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/recent-auth/password', ['password' => 'password']);

    $response->assertRedirect('/settings');
    $response->assertSessionHas('info');
    $response->assertSessionMissing('recent_auth.dropped_mutation');
    expect(session('recent_auth_at'))->toBeInt();
});

test('password 未設定 (SSO-only) ユーザーは password 経路で step-up できない (fail-closed)', function (): void {
    $user = User::factory()->create();
    // hashed cast を迂回して password 未設定状態を作る (SSO-only 相当)
    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    $this->actingAs($user->fresh())->postJson('/recent-auth/password', [
        'password' => 'password',
    ])->assertStatus(422);

    expect(session('recent_auth_at'))->toBeNull();
});

/* ---------------------------------------------------------------- 鮮度あり通過 */

test('鮮度ありなら機微操作を通過する (オーナー移譲は既存テスト参照)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account')
        ->assertRedirect('/');

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

/* ---------------------------------------------------------------- fresh login stamp */

test('fresh login (password) で recent-auth が stamp される (二重壁の防止)', function (): void {
    User::factory()->create(['email' => 'stamp@example.com']);

    $this->post('/login', [
        'email' => 'stamp@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('login');
});

test('case 4: viaRemember の web login は recent-auth を stamp しない (remember-me 復元 = stale)', function (): void {
    // remember-me cookie による自動ログイン復元 (SessionGuard::viaRemember()===true) は
    // fresh 認証扱いしない契約 (StampRecentAuthOnLogin docblock) を listener 単位で固定する。
    $user = User::factory()->create();

    /** @var SessionGuard&MockInterface $guard */
    $guard = Mockery::mock(SessionGuard::class);
    $guard->shouldReceive('viaRemember')->andReturn(true);

    /** @var AuthFactory&MockInterface $authFactory */
    $authFactory = Mockery::mock(AuthFactory::class);
    $authFactory->shouldReceive('guard')->with('web')->andReturn($guard);

    $listener = new StampRecentAuthOnLogin(app(RecentAuthState::class), $authFactory);
    $listener->handle(new Login('web', $user, true));

    expect(session('recent_auth_at'))->toBeNull();
    expect(session('recent_auth_method'))->toBeNull();
});

test('case 4 対照: viaRemember でない web login は recent-auth を stamp する', function (): void {
    // 通常 credential login (viaRemember()===false) では fresh 扱いで stamp される契約を
    // 両側から固定する。
    $user = User::factory()->create();

    /** @var SessionGuard&MockInterface $guard */
    $guard = Mockery::mock(SessionGuard::class);
    $guard->shouldReceive('viaRemember')->andReturn(false);

    /** @var AuthFactory&MockInterface $authFactory */
    $authFactory = Mockery::mock(AuthFactory::class);
    $authFactory->shouldReceive('guard')->with('web')->andReturn($guard);

    $listener = new StampRecentAuthOnLogin(app(RecentAuthState::class), $authFactory);
    $listener->handle(new Login('web', $user, false));

    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('login');
});

/* ---------------------------------------------------------------- SSO step-up satisfier */

test('step-up intent の開始は未ログインなら 403', function (): void {
    $this->get('/auth/google/redirect/step-up')->assertForbidden();
});

test('SSO step-up: 本人の連携アカウントなら鮮度が stamp され intended へ戻る', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');
    fakeStepUpSocialiteCallback('g-1');

    $response = $this->actingAs($user)
        ->withSession([
            'social_auth_intent' => 'step-up',
            'url.intended' => '/settings',
        ])
        ->get('/auth/google/callback');

    $response->assertRedirect('/settings');
    expect(session('recent_auth_at'))->toBeInt();
    expect(session('recent_auth_method'))->toBe('sso');
    expect(session('recent_auth_provider'))->toBe('google');
});

test('SSO step-up: 他人のアカウントでの round-trip は成立しない (本人性バインド)', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');
    fakeStepUpSocialiteCallback('g-other');

    $response = $this->actingAs($user)
        ->withSession(['social_auth_intent' => 'step-up'])
        ->get('/auth/google/callback');

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHasErrors('password');
    expect(session('recent_auth_at'))->toBeNull();
});

/*
 * T106 施策 2: SSO 登録ユーザーの passwordSet が実挙動と一致する。
 * phantom password 是正前は password 経路が使えないのに passwordSet=true になっていた
 * (= 確認モーダルがパスワード入力欄を出して詰む)。
 */
test('T106: SSO 登録直後のユーザーは passwordSet=false / canSatisfy=true (再SSO が satisfier)', function (): void {
    $this->withSession(['social_auth_intent' => 'register']);
    fakeStepUpSocialiteCallback('g-t106-status');

    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

    $user = User::whereBlind('email', 'email_index', 'step-up@example.com')->firstOrFail();

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('passwordSet', false)
        ->assertJsonPath('canSatisfy', true);
});

/*
 * T106 施策 5/6: パスキーは recent-auth の satisfier であり、status 契約に載る。
 *
 * **passkey しか持たないユーザーを confirm 画面で詰ませない**ことが目的。
 * 画面側が独自に判定すると特定画面でだけ詰むため、サーバの status を単一の源にする。
 */
test('T106: パスキー登録済みなら status の passkeyAvailable が true', function (): void {
    $user = User::factory()->create();
    Passkey::factory()->for($user)->create();

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('passkeyAvailable', true)
        ->assertJsonPath('canSatisfy', true);
});

test('T106: passkey しか持たないユーザーでも canSatisfy=true (詰ませない)', function (): void {
    $user = User::factory()->ssoOnly()->create();   // password なし / SSO 連携なし
    Passkey::factory()->for($user)->create();

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertOk()
        ->assertJsonPath('passwordSet', false)
        ->assertJsonPath('availableProviders', [])
        ->assertJsonPath('passkeyAvailable', true)
        ->assertJsonPath('canSatisfy', true);
});

test('T106: TOTP 有効でも passkey は再認証手段として数える (ログイン可否とは別)', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    Passkey::factory()->for($user)->create();

    // ログインには使えない
    expect(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user))->toBeFalse();

    // 再認証には使える
    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertJsonPath('passkeyAvailable', true);
});

test('T106: passkeys feature off では passkeyAvailable が false (route ごと消えるため)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->for($user)->create();

    config()->set(
        'fortify.features',
        array_values(array_filter(
            config()->array('fortify.features'),
            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
        )),
    );

    $this->actingAs($user)->getJson('/recent-auth/status')
        ->assertJsonPath('passkeyAvailable', false)
        ->assertJsonPath('canSatisfy', false);
});

test('T106: confirm 画面 (Inertia) にも passkeyAvailable が渡る', function (): void {
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->for($user)->create();

    $this->actingAs($user)->get(route('recent-auth.confirm'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passkeyAvailable', true)
            ->where('canSatisfy', true));
});
