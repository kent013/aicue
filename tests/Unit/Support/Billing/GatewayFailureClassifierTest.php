<?php

declare(strict_types=1);

use App\Enums\Billing\GatewayFailureClass;
use App\Support\Billing\GatewayFailureClassifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Laravel\Cashier\Exceptions\CustomerAlreadyCreated;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidCoupon;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction;
use Laravel\Cashier\Exceptions\InvalidInvoice;
use Laravel\Cashier\Exceptions\InvalidPaymentMethod;
use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
use Laravel\Cashier\Payment;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\BadMethodCallException as StripeBadMethodCallException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidArgumentException as StripeInvalidArgumentException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\TemporarySessionExpiredException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Tests\Support\Billing\GatewayFailureFixtures;
use Tests\Support\Billing\UnmappedGatewayFailureForTest;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;

/*
 * 分類器の全域性・境界・context の array shape を固定する。
 *
 * ★DB を触らない (Unit レーン。RefreshDatabase はグローバル適用だがクエリを発行しない)。
 */

/**
 * ★**期待値は分類器と独立に手書きで宣言する**。
 *   `directMap()` をそのまま dataset にすると、期待値と実装が同一ソースになり
 *   **写像を間違えても常に green** になる (既存 gate の「目録と期待値 map の二重宣言」と同じ作法)。
 * ★件数は固定定数で持たない。**キー集合一致の検査が正本**である
 *   (件数を別に持つと、片方だけ直したときに嘘の安心を与える)。
 *
 * @return array<class-string<Throwable>, GatewayFailureClass>
 */
function billingTaxonomyExpectedClassification(): array
{
    return [
        // --- Stripe SDK ---
        ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable,
        RateLimitException::class => GatewayFailureClass::ProviderUnavailable,
        InvalidRequestException::class => GatewayFailureClass::ProviderRejected,
        AuthenticationException::class => GatewayFailureClass::ProviderRejected,
        CardException::class => GatewayFailureClass::ProviderRejected,
        PermissionException::class => GatewayFailureClass::ProviderRejected,
        IdempotencyException::class => GatewayFailureClass::ProviderRejected,
        TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,
        SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,
        StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
        StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
        StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,

        // --- Cashier ---
        IncompletePayment::class => GatewayFailureClass::ProviderRejected,
        CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,
        InvalidCustomer::class => GatewayFailureClass::InvariantViolation,
        InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,
        InvalidInvoice::class => GatewayFailureClass::InvariantViolation,
        InvalidCoupon::class => GatewayFailureClass::InvariantViolation,
        InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
        SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation,

        // --- 非 vendor 明示宣言 ---
        QueryException::class => GatewayFailureClass::LocalFailure,
        LockTimeoutException::class => GatewayFailureClass::LocalFailure,
        AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
    ];
}

/**
 * 期待値表のクラスを**実インスタンス**として生成する。
 *
 * ★factory / constructor が違うため match で分ける。**実インスタンスで固定する**ことに意味がある
 *   (`LockTimeoutException` は `Contracts\Cache` と `Contracts\Filesystem` に同名クラスがあり、
 *    import を取り違えても文字列比較では気づけない)。
 */
function billingTaxonomyInstantiate(string $class): Throwable
{
    $throwable = match ($class) {
        ApiConnectionException::class => ApiConnectionException::factory('connection'),
        RateLimitException::class => RateLimitException::factory('rate limit', 429),
        InvalidRequestException::class => InvalidRequestException::factory('invalid request', 400),
        AuthenticationException::class => AuthenticationException::factory('auth', 401),
        CardException::class => CardException::factory('card', 402),
        PermissionException::class => PermissionException::factory('permission', 403),
        IdempotencyException::class => IdempotencyException::factory('idempotency', 400),
        TemporarySessionExpiredException::class => TemporarySessionExpiredException::factory('expired', 400),
        SignatureVerificationException::class => SignatureVerificationException::factory('signature'),
        StripeBadMethodCallException::class => new StripeBadMethodCallException('bad method call'),
        StripeInvalidArgumentException::class => new StripeInvalidArgumentException('invalid argument'),
        StripeUnexpectedValueException::class => new StripeUnexpectedValueException('unexpected value'),

        IncompletePayment::class => new IncompletePayment(new Payment(new PaymentIntent('pi_test')), 'incomplete'),
        CustomerAlreadyCreated::class => new CustomerAlreadyCreated('already created'),
        InvalidCustomer::class => new InvalidCustomer('invalid customer'),
        InvalidPaymentMethod::class => new InvalidPaymentMethod('invalid payment method'),
        InvalidInvoice::class => new InvalidInvoice('invalid invoice'),
        InvalidCoupon::class => new InvalidCoupon('invalid coupon'),
        InvalidCustomerBalanceTransaction::class => new InvalidCustomerBalanceTransaction('invalid transaction'),
        SubscriptionUpdateFailure::class => new SubscriptionUpdateFailure('update failure'),

        QueryException::class => new QueryException('pgsql', 'select 1', [], new PDOException('db')),
        LockTimeoutException::class => new LockTimeoutException('lock timeout'),
        AssertInvalidArgumentException::class => new AssertInvalidArgumentException('assert'),

        default => throw new LogicException("生成方法が未定義のクラスです: {$class}"),
    };

    // 生成物が宣言どおりのクラスであること (import 取り違えの検出)
    Assert::same($throwable::class, $class, "生成したインスタンスのクラスが宣言と一致しません: {$class}");

    return $throwable;
}

dataset('分類の期待値 (独立宣言)', function (): Generator {
    foreach (billingTaxonomyExpectedClassification() as $class => $expected) {
        yield $class => [$class, $expected];
    }
});

test('各クラスが期待どおりに分類される', function (string $class, GatewayFailureClass $expected): void {
    expect(GatewayFailureClassifier::classify(billingTaxonomyInstantiate($class)))->toBe($expected);
})->with('分類の期待値 (独立宣言)');

test('期待値表と directMap のキー集合が一致する (書き忘れ / 余剰の検出)', function (): void {
    $expected = array_keys(billingTaxonomyExpectedClassification());
    $actual = array_keys(GatewayFailureClassifier::directMap());
    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
});

test('UnknownApiErrorException は HTTP status で分岐する', function (?int $status, GatewayFailureClass $expected): void {
    expect(GatewayFailureClassifier::classify(UnknownApiErrorException::factory('boundary', $status)))
        ->toBe($expected);
})->with([
    'null (status 不明)' => [null, GatewayFailureClass::ProviderRejected],
    '400' => [400, GatewayFailureClass::ProviderRejected],
    '499 (境界の下)' => [499, GatewayFailureClass::ProviderRejected],
    '500 (境界)' => [500, GatewayFailureClass::ProviderUnavailable],
    '503' => [503, GatewayFailureClass::ProviderUnavailable],
]);

test('写像表に無い例外は unknown へ落ちる', function (): void {
    expect(GatewayFailureClassifier::classify(new UnmappedGatewayFailureForTest('x')))
        ->toBe(GatewayFailureClass::Unknown);
});

test('親クラス連鎖で分類される (将来のサブクラスを取りこぼさない)', function (): void {
    $subclass = new class('sub') extends ApiConnectionException {};

    expect(GatewayFailureClassifier::classify($subclass))->toBe(GatewayFailureClass::ProviderUnavailable);
});

test('context はキー集合と値が完全一致する (message は入り得ない)', function (): void {
    $context = GatewayFailureClassifier::context(
        ApiConnectionException::factory(GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER),
    );

    // ★キー集合と各値を**完全一致**で固定する。
    //   これ以外の値が入り得ないので、マーカー非含有は自明になる
    //   (json_encode して部分文字列を否定する形は array shape の検査として過剰)。
    expect($context)->toBe([
        'failure_class' => 'provider_unavailable',
        'error_class' => ApiConnectionException::class,
    ]);
});

test('LockTimeoutException は Contracts\Cache の具象クラスである (同名別クラスの取り違え検出)', function (): void {
    $throwable = new LockTimeoutException('lock timeout');

    expect($throwable::class)->toBe('Illuminate\Contracts\Cache\LockTimeoutException');
    expect(GatewayFailureClassifier::classify($throwable))->toBe(GatewayFailureClass::LocalFailure);
});
