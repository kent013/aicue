<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 突合の結果。**種別ごとに分けて持つ** (集めて使わない形を作らないため = 共通規約 (d))。
 *
 * 利用側の gate は `isClean()` で畳まず**種別ごとに個別 assert する**。
 * どの種別を見落としているかが人のレビューで分かるようにするためである
 * (`isClean()` は「全種別を 1 度に見たい」単体テスト側の便宜として置く)。
 */
final readonly class ReconciliationResult
{
    /**
     * @param  list<string>  $unregisteredMismatches  3a: 不一致なのに登録も債務も無い
     * @param  list<string>  $staleRegistrations  3b: 一致へ戻ったのに登録が残っている
     * @param  list<string>  $resolvedDebtPaths  債務規則 (i): 一致へ戻ったのに債務一覧に残っている
     * @param  list<string>  $mutatedDebtPaths  債務規則 (i-2): 採用時の姿から変わっている (登録するか戻す)
     * @param  list<string>  $doubleDeclaredPaths  債務規則 (ii): 債務と登録の二重宣言
     * @param  list<string>  $debtPathsOutsidePopulation  債務一覧に母集合外のパスがある
     * @param  list<string>  $duplicateRegisteredPaths  同一パスを 2 つ以上の登録が挙げている
     * @param  list<string>  $inspectionFailures  検査不能 (symlink / 非 regular file / 読めない)
     */
    public function __construct(
        public array $unregisteredMismatches,
        public array $staleRegistrations,
        public array $resolvedDebtPaths,
        public array $mutatedDebtPaths,
        public array $doubleDeclaredPaths,
        public array $debtPathsOutsidePopulation,
        public array $duplicateRegisteredPaths,
        public array $inspectionFailures,
    ) {}

    /** 8 種別すべてが空か。 */
    public function isClean(): bool
    {
        return $this->unregisteredMismatches === []
            && $this->staleRegistrations === []
            && $this->resolvedDebtPaths === []
            && $this->mutatedDebtPaths === []
            && $this->doubleDeclaredPaths === []
            && $this->debtPathsOutsidePopulation === []
            && $this->duplicateRegisteredPaths === []
            && $this->inspectionFailures === [];
    }
}
