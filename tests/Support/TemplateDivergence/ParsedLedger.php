<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿を解析した結果。
 *
 * `$unparsable` が true のときは登録簿を読み解けていないので、
 * `DivergenceLedgerRules` は解析時の違反だけを返して**そこで打ち切る** (fail-closed)。
 * 解析できなかったことを空集合へ落として緑にする経路は作らない。
 */
final readonly class ParsedLedger
{
    /**
     * @param  list<ParsedEntry>  $entries  解析できた登録 (見出しが正準形のものだけ)
     * @param  int|null  $declaredCount  「登録エントリ: N 件」の明示行の値 (行がちょうど 1 本でなければ null)
     * @param  list<string>  $parseViolations  解析時点で分かった違反
     */
    public function __construct(
        public array $entries,
        public ?int $declaredCount,
        public array $parseViolations,
        public bool $unparsable,
    ) {}
}
