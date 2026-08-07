<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\Billing\GatewayFailureClass;
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
use Throwable;
use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;

/**
 * 決済 gateway 消費経路で捕まえた Throwable を、有界な分類 (GatewayFailureClass) へ写す純関数。
 *
 * ★**Stripe / Cashier の例外型を知る唯一の非 gateway コンポーネント**である。
 *   ここに集約することで「外部語彙が観測点へ散らばる」ことを防ぐ
 *   (集約点が 2 つになったら語彙が割れる。gate が import の allowlist を固定する)。
 * ★制御フローに使わない。分類は観測 (構造化ログ / 例外報告の文言) 専用である。
 * ★`unknown` は「写像の不在」であり、`directMap()` の値には現れない
 *   (`BillingGatewayFailureTaxonomyInventoryTest` が機械で禁止する)。
 */
final class GatewayFailureClassifier
{
    public static function classify(Throwable $throwable): GatewayFailureClass
    {
        // ★条件付き規則を先に判定する (唯一の特別扱い)。
        //   UnknownApiErrorException は ApiRequestor::_specificV1APIError() の status switch の
        //   `default:` 分岐であり、**Stripe の 5xx はすべてここに来る**。
        //   「未知」なのは error type であって status ではないため、status で細分する。
        if ($throwable instanceof UnknownApiErrorException) {
            // ★vendor の PHPDoc は @return null|int だが**戻り型宣言は無い**。
            //   `!== null` ではなく `is_int()` で narrowing して、PHPDoc の揺れに耐えさせる。
            $status = $throwable->getHttpStatus();

            if (is_int($status) && $status >= 500) {
                return GatewayFailureClass::ProviderUnavailable;
            }

            // 4xx / その他 / null / 非 int。**運用上の保守的分類**であり、
            // 再送可能性の完全な意味判定ではない。status 不明で ProviderUnavailable
            // (= 待てば直る) と言うと**無行動を示唆する誤誘導**になるため「調べる」側へ倒す。
            // 実際には factory が必ず status を受け取るため、null / 非 int は防御的分岐である。
            return GatewayFailureClass::ProviderRejected;
        }

        $map = self::directMap();

        // ★実クラス → 親クラス連鎖の順に最初の一致を採る (将来のサブクラスを取りこぼさない)。
        //   グローバル SPL クラス (\RuntimeException 等) は表に入れないため、
        //   Stripe\Exception\InvalidArgumentException と Webmozart\Assert\InvalidArgumentException が
        //   共通祖先 \InvalidArgumentException で衝突することはない。
        $class = $throwable::class;

        do {
            if (array_key_exists($class, $map)) {
                return $map[$class];
            }

            // get_parent_class() は最上位クラスで false を返す (= 連鎖の終端)。
            $class = get_parent_class($class);
        } while ($class !== false);

        return GatewayFailureClass::Unknown;
    }

    /**
     * 構造化ログ / 例外報告に載せる 2 キー。
     *
     * ★観測点が**同じ綴りの同じ 2 キー**を出すことをコードの構造で担保する
     *   (gate が「宣言した catch 箇所の数 == `context(` の出現回数」を exact fit で検査する)。
     * ★`error_class` は外部サービスが生成する文字列ではない (値域はコードベース + vendor の
     *   クラス名に閉じる)。**例外 message は載せない**。
     *
     * @return array{failure_class: string, error_class: class-string<Throwable>}
     */
    public static function context(Throwable $throwable): array
    {
        return [
            'failure_class' => self::classify($throwable)->value,
            'error_class' => $throwable::class,
        ];
    }

    /**
     * 直接写像 (class => case) の正本。
     *
     * ★根拠は推測ではなく **vendor の throw site**。Stripe 側は
     *   `vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` の
     *   HTTP status switch が正本 (400 => InvalidRequest / 400+idempotency_error => Idempotency /
     *   400+rate_limit => RateLimit / 401 => Authentication / 402 => Card / 403 => Permission /
     *   404 => InvalidRequest / 429 => RateLimit / default => UnknownApiError)。
     *   `_specificV2APIError()` は temporary_session_expired のみ振り分けて V1 へ委譲する。
     * ★**値に GatewayFailureClass::Unknown を置かない** (unknown は写像の不在専用)。
     * ★**vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外
     *   (SignatureVerificationException = webhook 署名検証用 など) も観測語彙上は分類する。**
     *   分類は「もし来たら何と呼ぶか」の宣言であって「来る」という主張ではない
     *   (母集団に穴を空けると、SDK 更新で増えた例外が無音で unknown へ落ちる)。
     *
     * @return array<class-string<Throwable>, GatewayFailureClass>
     */
    public static function directMap(): array
    {
        return [
            // --- Stripe SDK: 決済事業者側の一時的な不能 ---
            ApiConnectionException::class => GatewayFailureClass::ProviderUnavailable, // HTTP 到達前の接続断
            RateLimitException::class => GatewayFailureClass::ProviderUnavailable,     // 429 / 400+rate_limit

            // --- Stripe SDK: 要求が受理されなかった ---
            InvalidRequestException::class => GatewayFailureClass::ProviderRejected,           // 400 / 404
            AuthenticationException::class => GatewayFailureClass::ProviderRejected,           // 401
            CardException::class => GatewayFailureClass::ProviderRejected,                     // 402 (通常は typed 結果へ変換される)
            PermissionException::class => GatewayFailureClass::ProviderRejected,               // 403
            IdempotencyException::class => GatewayFailureClass::ProviderRejected,              // 400 + idempotency_error
            TemporarySessionExpiredException::class => GatewayFailureClass::ProviderRejected,  // V2: temporary_session_expired
            SignatureVerificationException::class => GatewayFailureClass::ProviderRejected,    // webhook 署名不一致 (gateway 経路では発生しない)

            // --- Stripe SDK: SDK の誤用 = 自コードの欠陥 ---
            StripeBadMethodCallException::class => GatewayFailureClass::InvariantViolation,
            StripeInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
            StripeUnexpectedValueException::class => GatewayFailureClass::InvariantViolation,

            // --- Cashier ---
            IncompletePayment::class => GatewayFailureClass::ProviderRejected,          // 追加認証 (SCA) が要る
            CustomerAlreadyCreated::class => GatewayFailureClass::InvariantViolation,   // ManagesCustomer::createAsStripeCustomer
            InvalidCustomer::class => GatewayFailureClass::InvariantViolation,          // ManagesCustomer::assertCustomerExists
            InvalidPaymentMethod::class => GatewayFailureClass::InvariantViolation,     // PaymentMethod::__construct (invalidOwner)
            InvalidInvoice::class => GatewayFailureClass::InvariantViolation,           // Invoice::__construct (invalidOwner)
            InvalidCoupon::class => GatewayFailureClass::InvariantViolation,            // 本アプリは coupon を使わない
            InvalidCustomerBalanceTransaction::class => GatewayFailureClass::InvariantViolation,
            SubscriptionUpdateFailure::class => GatewayFailureClass::InvariantViolation, // Subscription::guardAgainst*

            // --- 非 vendor 明示宣言 (reconcile の catch(Throwable) が実際に受けうるもの) ---
            QueryException::class => GatewayFailureClass::LocalFailure,
            LockTimeoutException::class => GatewayFailureClass::LocalFailure,
            AssertInvalidArgumentException::class => GatewayFailureClass::InvariantViolation,
        ];
    }

    /**
     * 条件付き規則を持つクラス (直接写像に入れられないもの)。
     *
     * ★`directMap()` に入れると値がダミーになり「正本」が嘘をつくため分けている。
     * ★gate が `=== [UnknownApiErrorException::class]` を**クラス同一性**で固定する
     *   (件数だけだと別クラスへ差し替えても green になる)。
     *
     * @return list<class-string<Throwable>>
     */
    public static function conditionalClasses(): array
    {
        return [UnknownApiErrorException::class];
    }
}
