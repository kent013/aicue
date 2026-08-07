<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use Webmozart\Assert\Assert;

/**
 * 「決済 gateway 例外を catch して観測へ落とす」と裁定されたクラスの目録エントリ。
 *
 * ★`catchSites` は**メソッド単位**で宣言する。ファイル全体の出現回数だけだと
 *   コメント / 別文脈でも数が合えば green になるため、
 *   `BillingGatewayFailureTaxonomyInventoryTest` が ReflectionMethod の行範囲で切り出して検査する。
 */
final readonly class GatewayObservationEntry
{
    /**
     * @param  array<string, int>  $catchSites  メソッド名 => そのメソッド内で期待する context() 呼び出し回数
     * @param  int  $rawMessageCap  当該クラスのソースに現れてよい `getMessage()` の件数 (exact fit)
     * @param  non-empty-string  $rationale  30 文字以上
     */
    public function __construct(
        public array $catchSites,
        public int $rawMessageCap,
        public string $rationale,
    ) {
        Assert::notEmpty($catchSites, 'catchSites を 1 件以上宣言すること');
        Assert::greaterThanEq($rawMessageCap, 0, 'rawMessageCap は 0 以上で宣言すること');
        Assert::greaterThanEq(mb_strlen($rationale), 30, '観測目録の根拠は 30 文字以上で書くこと');
    }
}
