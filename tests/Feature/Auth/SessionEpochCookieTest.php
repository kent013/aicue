<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\SessionEpoch;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * セッション世代の印を運ぶ cookie (App\Http\Middleware\IssueSessionEpochCookie)。
 *
 * 契約:
 *   - 応答時点のセッション ID から導出する ($next の後) = ログイン・ログアウトでの
 *     セッション ID 再生成を同じ応答で拾う。
 *   - 未認証でも発行し、削除しない (「印が無い」状態を作らない)。
 *   - **画面側から読める** = 暗号化の除外が効いていること。ここが本 middleware で最も
 *     壊れやすい配線なので、平文値そのものを固定する (除外を外すと画面側は復号できない
 *     文字列を読み、常に不一致 = 復元のたびに読み直しになる = 静かな劣化)。
 *   - 属性は同じ応答の session cookie と同一 (HttpOnly を除く)。
 */

/** 応答から指定 cookie を取り出す (無ければ null)。 */
function cookieFromResponse(TestResponse $response, string $name): ?Cookie
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    return null;
}

/** セッション ID を固定して要求する (印の期待値を計算できるようにする)。 */
function pinnedSessionId(): string
{
    return Str::random(40);
}

test('認証済み応答の世代 cookie が平文の印そのものである (暗号化の除外が効いている)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $sessionId = pinnedSessionId();

    $response = $this->actingAs($owner)
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->get('/dashboard');

    $cookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);

    expect($cookie)->not->toBeNull('認証済み応答に世代 cookie が無い');
    expect($cookie?->getValue())->toBe(SessionEpoch::forSession($sessionId));
});

test('guest 応答にも世代 cookie が付く (「印が無い」状態を作らない)', function (): void {
    $sessionId = pinnedSessionId();

    $response = $this->withCookie((string) config('session.cookie'), $sessionId)->get('/login');

    $cookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);

    expect($cookie)->not->toBeNull('guest 応答に世代 cookie が無い');
    expect($cookie?->getValue())->toBe(SessionEpoch::forSession($sessionId));
});

test('世代 cookie は画面側から読める (HttpOnly でない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $cookie = cookieFromResponse(
        $this->actingAs($owner)->get('/dashboard'),
        SessionEpoch::COOKIE_NAME,
    );

    expect($cookie?->isHttpOnly())->toBeFalse();
});

test('世代 cookie の属性は同じ応答の session cookie と同じ (HttpOnly を除く)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->get('/dashboard');

    $epochCookie = cookieFromResponse($response, SessionEpoch::COOKIE_NAME);
    $sessionCookie = cookieFromResponse($response, (string) config('session.cookie'));

    expect($sessionCookie)->not->toBeNull('比較対象の session cookie が同じ応答に無い');
    expect($epochCookie?->getPath())->toBe($sessionCookie?->getPath())
        ->and($epochCookie?->getDomain())->toBe($sessionCookie?->getDomain())
        ->and($epochCookie?->isSecure())->toBe($sessionCookie?->isSecure())
        ->and($epochCookie?->getSameSite())->toBe($sessionCookie?->getSameSite());
});

test('ログイン応答の後の印はログイン前と異なる (セッション ID 再生成を拾う)', function (): void {
    $user = User::factory()->create(['email' => 'epoch-login@example.com']);

    $before = cookieFromResponse($this->get('/login'), SessionEpoch::COOKIE_NAME);

    $after = cookieFromResponse($this->post('/login', [
        'email' => 'epoch-login@example.com',
        'password' => 'password',
    ]), SessionEpoch::COOKIE_NAME);

    $this->assertAuthenticatedAs($user);
    expect($before?->getValue())->not->toBeNull()
        ->and($after?->getValue())->not->toBeNull()
        ->and($after?->getValue())->not->toBe($before?->getValue());
});

test('ログアウト応答の後の印はログアウト前と異なる (削除ではなく上書き)', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $sessionId = pinnedSessionId();

    $before = cookieFromResponse(
        $this->actingAs($owner)
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->get('/dashboard'),
        SessionEpoch::COOKIE_NAME,
    );

    $after = cookieFromResponse(
        $this->actingAs($owner)
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->post('/logout'),
        SessionEpoch::COOKIE_NAME,
    );

    expect($after?->getValue())->not->toBeNull('ログアウト応答で世代 cookie が消えている')
        ->and($after?->getValue())->not->toBe($before?->getValue());
});

test('session を持たない route (stateless block) には世代 cookie を付けない', function (): void {
    $response = $this->get('/robots.txt');

    expect(cookieFromResponse($response, SessionEpoch::COOKIE_NAME))->toBeNull();
});
