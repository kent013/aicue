<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use App\Services\EnterpriseSso\EnterpriseCallbackAuthenticator;
use App\Support\EnterpriseSso\AttemptFingerprint;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * 企業 SSO のログイン導線 (C2)。
 *
 * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点でログインを確定させ、
 *   2 要素認証の入力画面へ転送する分岐を持たない。**主たる証明はここ (実挙動) にある**。
 */

function activeConnection(FakeIdentityProvider $idp, ?Organization $organization = null): OrganizationOidcConnection
{
    return OrganizationOidcConnection::factory()->active()->create([
        'organization_id' => ($organization ?? Organization::factory()->create())->id,
        'login_slug' => 'acme-idp',
        'issuer' => $idp->issuer,
        'client_id' => 'client-1234',
        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('very-secret-value'),
    ]);
}

/** 開始 → IdP の応答 → 戻り口までを 1 往復させる。 */
function completeEnterpriseLogin(FakeIdentityProvider $idp, string $subject = 'sub-abc'): TestResponse
{
    $start = test()->get('/enterprise/acme-idp/redirect');
    $start->assertRedirect();

    /** @var string $location */
    $location = $start->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $idp->withClaims(['nonce' => $query['nonce'], 'sub' => $subject]);

    return test()->get(route('enterprise-sso.callback', [
        'state' => $query['state'],
        'code' => 'authorization-code',
    ]));
}

test('開始で行が作られてからリダイレクトする (順序)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $response = $this->get('/enterprise/acme-idp/redirect');

    $response->assertRedirect();
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);
});

test('認可要求に必須の引数が載る', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $response = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['response_type'])->toBe('code');
    expect($query['scope'])->toContain('openid');
    expect($query['client_id'])->toBe('client-1234');
    expect($query['code_challenge_method'])->toBe('S256');
    expect($query['state'])->not->toBe('');
    expect($query['nonce'])->not->toBe('');
    expect($query['code_challenge'])->not->toBe('');
    expect($query['redirect_uri'])->toBe(route('enterprise-sso.callback'));
});

test('開始の応答が no-store である', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $this->get('/enterprise/acme-idp/redirect')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('無効な接続では行を作らず、実在しない識別名と同じ応答になる (実在オラクルを作らない)', function (OidcConnectionStatus $status): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = activeConnection($idp);
    $connection->forceFill(['status' => $status])->save();

    $disabled = $this->get('/enterprise/acme-idp/redirect');
    $missing = $this->get('/enterprise/never-registered/redirect');

    expect($disabled->getStatusCode())->toBe($missing->getStatusCode());
    expect($disabled->headers->get('Location'))->toBe($missing->headers->get('Location'));
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
})->with([
    OidcConnectionStatus::Draft,
    OidcConnectionStatus::Verified,
    OidcConnectionStatus::Disabled,
]);

test('往復でログインが確定し、利用者・身元・所属が作られる', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = activeConnection($idp);

    completeEnterpriseLogin($idp)->assertRedirect();

    expect(Auth::check())->toBeTrue();
    expect($connection->identities()->count())->toBe(1);

    /** @var User $user */
    $user = Auth::user();
    expect($user->email)->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('2 要素認証が有効な利用者もそのままログインが確定する (AG-200 の主証明 その 1)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = activeConnection($idp);

    // 1 回目のログインで利用者を作り、2 要素を有効にする
    completeEnterpriseLogin($idp);
    /** @var User $user */
    $user = Auth::user();
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    Auth::logout();

    // 2 回目: **2 要素の入力画面へ送られない**
    $response = completeEnterpriseLogin($idp);

    expect(Auth::check())->toBeTrue();
    expect(Auth::id())->toBe($user->id);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('two-factor');
});

test('組織が 2 要素を義務づけていても、確定したうえで設定ページへ導かれる (AG-200 の主証明 その 2)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $organization = Organization::factory()->create(['two_factor_required' => true]);
    activeConnection($idp, $organization);

    completeEnterpriseLogin($idp);

    // ★ログインは確定している (待機ログインを作らない)
    expect(Auth::check())->toBeTrue();

    // 義務づけの強制は**ログイン確定後**のアカウント全体のゲートであり、行き先は設定ページである
    $this->get(route('settings.security'))->assertSuccessful();
});

test('remember cookie を発行しない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $response = completeEnterpriseLogin($idp);

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toStartWith('remember_web');
    }
});

test('確定でセッション ID が変わる (セッション固定化を塞ぐ)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $this->get('/enterprise/acme-idp/redirect');
    $before = session()->getId();

    completeEnterpriseLogin($idp);

    expect(session()->getId())->not->toBe($before);
});

test('不正な入力では外向き取得を一切開始しない', function (array $query): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $this->get(route('enterprise-sso.callback', $query));

    expect($idp->requests)->toBe([]);
    expect(Auth::check())->toBeFalse();
})->with([
    'state が無い' => [['code' => 'c']],
    'code も error も無い' => [['state' => 's']],
    'code と error の同時' => [['state' => 's', 'code' => 'c', 'error' => 'access_denied']],
    'state が配列' => [['state' => ['a'], 'code' => 'c']],
    'code が長すぎる' => [['state' => 's', 'code' => str_repeat('c', 5000)]],
]);

test('IdP の error 応答は一様な失敗になる', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $this->get(route('enterprise-sso.callback', ['state' => 'anything', 'error' => 'access_denied']))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
});

test('別のブラウザで戻り口を開いてもログインできない (login CSRF)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $start = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $idp->withClaims(['nonce' => $query['nonce']]);

    // 攻撃者のセッション (結合の秘密を持たない) から戻り口を開く
    $this->flushSession();

    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
    // ★行は残る (攻撃者が被害者の試行を消せない)
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);
});

test('開始後に接続を無効化するとログインできない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = activeConnection($idp);

    $start = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $idp->withClaims(['nonce' => $query['nonce']]);

    $connection->forceFill(['status' => OidcConnectionStatus::Disabled])->save();

    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
    // ★JIT も起きていない (副作用が 1 バイトも残らない)
    expect($connection->identities()->count())->toBe(0);
});

test('失敗の応答が一様である (接続や利用者の存在を読み取れない)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $unknownState = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'code' => 'c']));
    $providerError = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'error' => 'x']));

    expect($unknownState->getStatusCode())->toBe($providerError->getStatusCode());
    expect($unknownState->headers->get('Location'))->toBe($providerError->headers->get('Location'));
});

test('使用済みの state では 2 回目にログインできない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $start = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $idp->withClaims(['nonce' => $query['nonce']]);

    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']));
    expect(Auth::check())->toBeTrue();
    Auth::logout();

    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
        ->assertRedirect(route('login'));
    expect(Auth::check())->toBeFalse();
});

test('結合の秘密は state の指紋ごとに分かれる (複数タブが共存できる)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $first = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $first->headers->get('Location'), PHP_URL_QUERY), $firstQuery);

    $second = $this->get('/enterprise/acme-idp/redirect');
    parse_str((string) parse_url((string) $second->headers->get('Location'), PHP_URL_QUERY), $secondQuery);

    foreach ([$firstQuery['state'], $secondQuery['state']] as $state) {
        $key = EnterpriseCallbackAuthenticator::bindingSessionKey(
            AttemptFingerprint::of(FingerprintPurpose::State, $state),
        );
        expect(session()->get($key))->toBeString();
    }
});

test('入口の画面は外向き通信をせず DB も変えない', function (): void {
    $idp = (new FakeIdentityProvider)->install();

    $this->get(route('enterprise-sso.login'))->assertSuccessful();

    expect($idp->requests)->toBe([]);
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
});

test('validation の失敗でも code / state が old input に残らない', function (array $query): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    // ★Laravel は validation の失敗時、controller へ到達する**前に**入力を `_old_input` へ
    //   退避する。controller で withInput() を呼ばないだけでは塞げない経路である。
    $this->get(route('enterprise-sso.callback', $query))->assertRedirect(route('login'));

    /** @var array<string, mixed> $old */
    $old = session()->get('_old_input', []);

    expect($old)->not->toHaveKey('code');
    expect($old)->not->toHaveKey('state');
    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain('super-secret-code');
})->with([
    'code と error の同時' => [[
        'state' => 'super-secret-state',
        'code' => 'super-secret-code',
        'error' => 'access_denied',
    ]],
    'state が無い' => [['code' => 'super-secret-code']],
    'code も error も無い' => [['state' => 'super-secret-state']],
]);

test('validation の失敗も他の失敗と同じ応答である (どこで落ちたか読み取れない)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    activeConnection($idp);

    $invalidInput = $this->get(route('enterprise-sso.callback', ['state' => 's', 'code' => 'c', 'error' => 'x']));
    $unknownState = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'code' => 'c']));

    expect($invalidInput->getStatusCode())->toBe($unknownState->getStatusCode());
    expect($invalidInput->headers->get('Location'))->toBe($unknownState->headers->get('Location'));
});
