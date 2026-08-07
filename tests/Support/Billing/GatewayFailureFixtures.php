<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use App\Enums\Billing\GatewayFailureClass;
use Illuminate\Database\QueryException;
use LogicException;
use PDOException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 「**本物の gateway が実際に伝播させる例外クラスそのもの**」を分類ごとに返す共有 fixture。
 *
 * ★fake が独自の RuntimeException を投げると、分類を記録する経路がテストで一度も
 *   本物と同じ値を見ない (偽グリーン)。fake の失敗注入をここへ集約し、
 *   `BillingGatewayFailureTaxonomyInventoryTest` が
 *   「fixture の case 集合 == 業務 4 case」「classify(fixture(case)) === case」
 *   「fixture が返すクラスが実ライブラリ名前空間に属する」を deny-by-default で固定する。
 * ★`Unknown` は parity の対象外 (写像の不在専用なので「本物と同じ例外」が存在しない)。
 *   `Unknown` の固定は分類器の Unit テストが UnmappedGatewayFailureForTest で行う。
 */
final class GatewayFailureFixtures
{
    /**
     * 全 fixture の message に必ず含める「外部生成文字列」の目印。
     *
     * ★これが**無いと negative assertion が空虚に green になる**。
     *   「ログにこの文字列が含まれない」という検査は、
     *   「例外 message にはこの文字列が確かに入っている」という保証とセットでしか意味を持たない。
     *   gate が全 fixture について `str_contains(getMessage(), MARKER)` を検査する。
     */
    public const string EXTERNAL_MESSAGE_MARKER = 'FIXTURE-EXTERNAL-MESSAGE';

    /**
     * fixture が返してよいクラスの名前空間 (gate が参照する)。
     *
     * @var list<string>
     */
    public const array ALLOWED_NAMESPACE_PREFIXES = [
        'Stripe\\Exception\\',
        'Laravel\\Cashier\\Exceptions\\',
        'Illuminate\\',
        'Webmozart\\Assert\\',
    ];

    /** parity の対象 (業務分類 4 case)。`Unknown` を含めない。 */
    public static function throwableFor(GatewayFailureClass $class): Throwable
    {
        return match ($class) {
            // Stripe に到達できない (接続断) — 本物では ApiConnectionException が伝播する
            GatewayFailureClass::ProviderUnavailable => ApiConnectionException::factory(
                self::EXTERNAL_MESSAGE_MARKER.': stripe unreachable',
            ),
            // 要求が拒否された (400) — 本物では InvalidRequestException が伝播する
            GatewayFailureClass::ProviderRejected => InvalidRequestException::factory(
                self::EXTERNAL_MESSAGE_MARKER.': invalid request',
                400,
            ),
            // 本物の terminateInvoice の paid 判定 (Assert::true) と**同じクラス**
            GatewayFailureClass::InvariantViolation => self::assertFailure(),
            // reconcile が DB 例外を受ける経路
            GatewayFailureClass::LocalFailure => new QueryException(
                'pgsql',
                'select 1',
                [],
                // ★QueryException::formatMessage() は previous の message を取り込むため、
                //   マーカーは QueryException 自身の getMessage() にも現れる (実測で確認済み)。
                new PDOException(self::EXTERNAL_MESSAGE_MARKER.': db unavailable'),
            ),
            GatewayFailureClass::Unknown => throw new LogicException(
                'Unknown は parity の対象外。分類器 Unit テストの UnmappedGatewayFailureForTest を使うこと',
            ),
        };
    }

    /**
     * parity 対象の業務 4 case (`Unknown` を除く全 case)。
     *
     * @return list<GatewayFailureClass>
     */
    public static function parityCases(): array
    {
        return array_values(array_filter(
            GatewayFailureClass::cases(),
            static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
        ));
    }

    /** Webmozart\Assert\InvalidArgumentException を「実際に Assert に投げさせて」得る。 */
    private static function assertFailure(): Throwable
    {
        try {
            Assert::true(false, self::EXTERNAL_MESSAGE_MARKER.': 不変条件違反');
        } catch (Throwable $throwable) {
            return $throwable;
        }

        throw new LogicException('Assert::true(false) が例外を投げませんでした');
    }
}
