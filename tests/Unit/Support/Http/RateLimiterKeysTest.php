<?php

declare(strict_types=1);

use App\Support\Http\RateLimiterKeys;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/*
 * named limiter のキー組み立て helper (`{レーン}:{種別}:{値}`)。
 *
 * DB に触れない純粋な文字列生成のため Unit レーンで固定する
 * (RefreshDatabase はグローバル適用のまま。個別 DatabaseTransactions は使わない)。
 */

/** 指定 identifier を返す Authenticatable の匿名実装 (契約外の型も返せるようにする)。 */
function rateLimiterKeysUserWithIdentifier(mixed $identifier): Authenticatable
{
    return new class($identifier) implements Authenticatable
    {
        public function __construct(private mixed $identifier) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): mixed
        {
            return $this->identifier;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

/**
 * 指定 identifier の user を返す Request (identifier が null なら guest)。
 *
 * `$ip = null` は「REMOTE_ADDR ごと無い」= `Request::ip()` が null を返す状態、
 * `$ip = ''` は「REMOTE_ADDR が空文字」を作る (どちらも実運用では
 * client IP を解決できなかった状態で、キーの終端を空にしてはならない)。
 */
function rateLimiterKeysRequest(mixed $identifier, ?string $ip = '203.0.113.7'): Request
{
    $request = Request::create('/probe', 'POST');
    if ($ip === null) {
        $request->server->remove('REMOTE_ADDR');
    } else {
        $request->server->set('REMOTE_ADDR', $ip);
    }
    $request->setUserResolver(
        static fn (): ?Authenticatable => $identifier === null ? null : rateLimiterKeysUserWithIdentifier($identifier),
    );

    return $request;
}

test('actorOrIp() は認証済みユーザーに {lane}:user:{id} を返す', function (): void {
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest(4242), 'password-verify'))
        ->toBe('password-verify:user:4242');

    // ULID / UUID など string 主キーでも user 分岐に入る
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest('01JABCDEF'), 'plan-activate'))
        ->toBe('plan-activate:user:01JABCDEF');
});

test('actorOrIp() は未認証に {lane}:ip:{ip} を返す', function (): void {
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest(null), 'password-set'))
        ->toBe('password-set:ip:203.0.113.7');
});

test('actorOrIp() は IP が取れないとき {lane}:ip:unknown を返す (キーを空にしない)', function (?string $ip): void {
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest(null, ip: $ip), 'email-verification'))
        ->toBe('email-verification:ip:unknown');
})->with([
    'REMOTE_ADDR 無し (ip() = null)' => [null],
    'REMOTE_ADDR 空文字' => [''],
]);

test('actorOrIp() は identifier が空文字のとき user 分岐へ落ちない (キーの終端を空にしない)', function (): void {
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest(''), 'two-factor-manage'))
        ->toBe('two-factor-manage:ip:203.0.113.7');
});

test('actorOrIp() は identifier が bool / float のとき user 分岐へ落ちない (is_scalar 相当の誤受理の負のコントロール)', function (mixed $identifier): void {
    // ★is_scalar() だと true が `:user:1` へ、1.5 が `:user:1.5` へ潰れる。
    //   getAuthIdentifier() の契約は int|string|null であり、契約外の値は
    //   「actor を特定できていない」ので IP 分岐へ倒すのが正しい。
    expect(RateLimiterKeys::actorOrIp(rateLimiterKeysRequest($identifier), 'invitation-accept-submit'))
        ->toBe('invitation-accept-submit:ip:203.0.113.7');
})->with([
    'bool true' => [true],
    'bool false' => [false],
    'float' => [1.5],
]);
