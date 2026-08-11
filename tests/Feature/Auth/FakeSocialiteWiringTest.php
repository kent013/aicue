<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Providers\FakeExternalsServiceProvider;
use App\Services\Auth\Fakes\FakeSocialiteProvider;
use App\Services\Auth\SocialiteDriverResolver;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;

/*
 * SSO (Socialite) fake 配線の behavioral gate (T153)。
 *
 * bug-hunt / 自動テストレーンのブラウザが SSO ボタンから**実 IdP へ出ない**ことを、
 * 「アプリが返すリダイレクト先」の水準で固定する。
 *
 * ★負のコントロール (#1) を必ず一緒に緑に保つこと。#1 が落ちると #2 以降の green は
 *   「もともと外に出ていなかった」を見ているだけになり、検査が空振りする。
 */

/**
 * このテスト内でだけ SSO fake を配線する (レーン既定は flag off のまま)。
 *
 * ★global function にしない。Pest のファイル直下 function は**グローバル空間**に出るため、
 *   将来別ファイルに同名 helper が足されると fatal になる
 *   (現に RecentAuthTest は「SocialAuthTest の helper と名前衝突させない」と人手で回避している)。
 *   closure なら構造的に起きない。
 */
$enableSsoFake = function (): void {
    config(['testing.fake_externals' => true]);
    (new FakeExternalsServiceProvider(app()))->register();
};

/** リダイレクト先 URL の host 部を取り出す (Location ヘッダ不在は null) */
$locationHost = function (TestResponse $response): ?string {
    $location = $response->headers->get('Location');
    if (! is_string($location)) {
        return null;
    }

    $host = parse_url($location, PHP_URL_HOST);

    return is_string($host) ? $host : null;
};

test('負のコントロール: fake 無効 (レーン既定) では social.redirect が実 IdP ホストへ出る',
    function () use ($locationHost): void {
        // 前提を明示する: google が config から外れたら「host が違う」ではなく
        // 「前提が崩れた」と読めるようにする。
        expect(config()->array('template.social_providers'))->toHaveKey('google');
        expect(config('testing.fake_externals'))->toBeFalse();

        $response = $this->get('/auth/google/redirect/login');

        $host = $locationHost($response);

        expect($host)->toBe('accounts.google.com')
            ->and($host)->not->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
    });

test('fake 有効: 宣言済み全 provider の social.redirect が自アプリ host に閉じる',
    function () use ($enableSsoFake, $locationHost): void {
        $enableSsoFake();

        $providers = array_keys(config()->array('template.social_providers'));

        // 母集団 0 件で緑にならないことの保証 (provider が増えれば検査も自動で増える)
        expect($providers)->not->toBeEmpty();

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($providers as $provider) {
            $response = $this->get("/auth/{$provider}/redirect/login");

            expect($locationHost($response))->toBe($appHost, "provider={$provider} が自アプリ host に閉じていません")
                ->and((string) $response->headers->get('Location'))->toBe(
                    route('social.callback', ['provider' => $provider]),
                    "provider={$provider} の戻り先が social.callback ではありません",
                );
        }
    });

test('fake 有効: register intent の round-trip で User と SocialAccount と個人組織が作られる',
    function () use ($enableSsoFake): void {
        $enableSsoFake();

        $this->get('/auth/google/redirect/register?terms_accepted=1')
            ->assertRedirect(route('social.callback', ['provider' => 'google']));

        $this->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $user = User::whereBlind('email', 'email_index', 'fake-google-sso@example.com')->firstOrFail();
        expect($user->socialAccounts()->where('provider', 'google')
            ->where('provider_user_id', 'fake-google-user')->exists())->toBeTrue()
            ->and($user->organizations()->where('is_personal', true)->count())->toBe(1);
    });

test('fake 有効: login intent の round-trip で連携済みユーザーとしてログインする',
    function () use ($enableSsoFake): void {
        $enableSsoFake();

        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create([
            'provider' => 'google',
            'provider_user_id' => 'fake-google-user',
        ]);

        $this->get('/auth/google/redirect/login')
            ->assertRedirect(route('social.callback', ['provider' => 'google']));

        $this->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    });

test('fake 有効: link intent の round-trip でログイン中ユーザーに連携が付く',
    function () use ($enableSsoFake): void {
        $enableSsoFake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/auth/google/redirect/link')
            ->assertRedirect(route('social.callback', ['provider' => 'google']));

        $this->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect(route('settings.security'))
            ->assertSessionHas('success');

        expect($user->socialAccounts()->where('provider', 'google')
            ->where('provider_user_id', 'fake-google-user')->count())->toBe(1);
    });

test('fake 有効: step-up intent の round-trip で recent-auth の鮮度が stamp される',
    function () use ($enableSsoFake): void {
        $enableSsoFake();

        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create([
            'provider' => 'google',
            'provider_user_id' => 'fake-google-user',
        ]);

        $this->actingAs($user)->withSession(['url.intended' => '/settings']);

        $this->get('/auth/google/redirect/step-up')
            ->assertRedirect(route('social.callback', ['provider' => 'google']));

        $this->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect('/settings');

        expect(session('recent_auth_method'))->toBe('sso')
            ->and(session('recent_auth_provider'))->toBe('google');
    });

test('fake の identity は provider ごとに決定論的で、一目で fake と分かる', function (): void {
    $user = (new FakeSocialiteProvider('google'))->user();

    expect($user->getId())->toBe('fake-google-user')
        ->and($user->getEmail())->toBe('fake-google-sso@example.com')
        ->and($user->getName())->toBe('SSO Fake User (google)')
        ->and($user->getId())->toStartWith('fake-');
});

test('fake は local 環境では配線されない (実 IdP 連携の確認手段を残す)', function (): void {
    $originalEnvironment = $this->app['env'];
    $originalFlag = config('testing.fake_externals');

    try {
        $this->app['env'] = 'local';
        config(['testing.fake_externals' => true]);

        (new FakeExternalsServiceProvider($this->app))->register();

        // ★厳密一致 (fake は real のサブクラスなので instanceof では対照が無意味になる)
        expect(app(SocialiteDriverResolver::class)::class)->toBe(SocialiteDriverResolver::class);
    } finally {
        config(['testing.fake_externals' => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('fake 有効でも social.callback は intent 不在なら Socialite に触れずログインへ戻す',
    function () use ($enableSsoFake): void {
        $enableSsoFake();

        // ★「login へ戻る」だけでは**触れていないこと**を実証できない (driver を呼んでから
        //   login へ戻る実装に壊れても緑になる)。呼ばれたら必ず落ちる resolver を後勝ちで
        //   bind し、到達の有無そのものを検出する。
        $this->app->bind(SocialiteDriverResolver::class, fn (): SocialiteDriverResolver => new class extends SocialiteDriverResolver
        {
            public function driver(string $provider): Provider
            {
                throw new RuntimeException("intent 不在の callback が Socialite driver を解決しました: {$provider}");
            }
        });

        $this->withSession([])
            ->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });
