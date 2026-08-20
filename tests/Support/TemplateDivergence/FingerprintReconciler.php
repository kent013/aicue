<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 3a / 3b と採用時債務の規則の判定 (純関数)。
 *
 * 突合の本体は**集合の等式 1 本**である:
 *   {母集合のうち不一致のパス} == ({全登録の対象パス} ∩ {母集合}) ∪ {債務一覧のパス}
 * 等式なので ⊃ (不一致なのに未登録 = 3a) も ⊂ (一致へ戻ったのに登録が残る = 3b) も落ちる。
 * 債務側はさらに**採用時ハッシュとの一致**まで見る (下記の 3 分岐)。
 *
 * ★**登録の状態 (`恒久` / `監視中`) は読まない**。状態を突合のフィルタにすると、
 *   内容をテンプレートへ戻した後に状態だけ変えて 3b を回避できてしまう。
 * ★結果は**種別ごとに分けて返す** (集めて使わない形を作らないため = 共通規約 (d))。
 * ★**すべての種別を評価してから返す** (早期 return しない)。1 回の実行でどの違反も全部見える。
 * ★**登録の対象パスの書式・実在・値域は見ない**。それは形式検査
 *   (`TemplateDivergenceLedgerFormatTest` の TD3) の担当で、本クラスは**重複だけ**を
 *   自分で検出する (重複は突合の正しさに直接効くため)。解析が成功していることは
 *   利用側 gate の F13 が同じ実行の中で確かめる。
 *
 * ★**取り違えは黙って通さない** (fail-closed): 観測の集合が母集合と一致しない場合と、
 *   観測の比較状態がテンプレート側ハッシュとの実際の関係と矛盾する場合は例外にする。
 *
 * **保証しないもの**: 粒度はファイル単位であり、ファイルの内部のどこが逸脱したかは見ない。
 * 母集合の外 (アプリ固有ファイル / 正典側にしか無いパス) には沈黙する。
 * 負例と正例は `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が持つ。
 */
final class FingerprintReconciler
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @param  array<string, PathObservation>  $observations  母集合の全キーに対する観測
     * @param  list<array{path: string, label: string}>  $registered  全登録の対象パス
     *                                                                (**リストで受ける**。`array<string, string>` で受けると配列構築の時点で後勝ちに潰れて
     *                                                                同一パスの重複が見えなくなる)
     * @param  array<string, string>  $debt  債務一覧 (パス => 採用時のアプリ側ハッシュ)
     * @param  array<string, string>  $templateHashes  母集合のパス => 正典側ハッシュ
     *
     * @throws RuntimeException 観測の集合が母集合と一致しない / 観測が自己矛盾している
     */
    public static function reconcile(
        array $observations,
        array $registered,
        array $debt,
        array $templateHashes,
    ): ReconciliationResult {
        self::assertObservationsCoverPopulation($observations, $templateHashes);

        // --- 登録の対象パスを数える (重複はここで見える) ---
        $registeredCounts = [];
        foreach ($registered as $entry) {
            $registeredCounts[$entry['path']] = ($registeredCounts[$entry['path']] ?? 0) + 1;
        }
        $duplicateRegisteredPaths = array_keys(array_filter(
            $registeredCounts,
            static fn (int $count): bool => $count >= 2,
        ));

        // --- 検査不能 (どの種別へも畳まない) ---
        $inspectionFailures = [];
        foreach ($observations as $path => $observation) {
            if ($observation->inspectionFailure !== null) {
                $inspectionFailures[] = $path;
            }
        }

        // --- 債務一覧の現況 ---
        $debtPathsOutsidePopulation = [];
        $doubleDeclaredPaths = [];
        $resolvedDebtPaths = [];
        $mutatedDebtPaths = [];
        foreach ($debt as $path => $adoptionHash) {
            if (! array_key_exists($path, $templateHashes)) {
                // 母集合外の債務はハッシュ比較へ進めない (未定義キーで途中終了させない)
                $debtPathsOutsidePopulation[] = $path;

                continue;
            }
            if (array_key_exists($path, $registeredCounts)) {
                $doubleDeclaredPaths[] = $path;
            }

            $observation = $observations[$path];
            if ($observation->inspectionFailure !== null) {
                continue; // 検査不能として既に報告済み
            }
            if ($observation->currentHash === null) {
                $mutatedDebtPaths[] = $path; // 削除された = 採用時の姿ではない

                continue;
            }
            if ($observation->currentHash === $adoptionHash) {
                continue; // 採用時の姿のまま = 未解消債務として許容する
            }
            if ($observation->currentHash === $templateHashes[$path]) {
                $resolvedDebtPaths[] = $path; // 一致へ戻った = 一覧から削れ

                continue;
            }
            $mutatedDebtPaths[] = $path; // 登録を書くか、採用時の姿へ戻すか、テンプレートへ同期する
        }

        // --- 母集合 − 債務 の範囲で 3a / 3b ---
        $unregisteredMismatches = [];
        $staleRegistrations = [];
        foreach ($templateHashes as $path => $templateHash) {
            if (array_key_exists($path, $debt)) {
                continue;
            }
            $observation = $observations[$path];
            if ($observation->inspectionFailure !== null) {
                continue;
            }

            $isRegistered = array_key_exists($path, $registeredCounts);
            $isMatched = $observation->state === ComparisonState::Matched;

            if (! $isMatched && ! $isRegistered) {
                $unregisteredMismatches[] = $path;
            }
            if ($isMatched && $isRegistered) {
                $staleRegistrations[] = $path;
            }
        }

        return new ReconciliationResult(
            unregisteredMismatches: self::sorted($unregisteredMismatches),
            staleRegistrations: self::sorted($staleRegistrations),
            resolvedDebtPaths: self::sorted($resolvedDebtPaths),
            mutatedDebtPaths: self::sorted($mutatedDebtPaths),
            doubleDeclaredPaths: self::sorted($doubleDeclaredPaths),
            debtPathsOutsidePopulation: self::sorted($debtPathsOutsidePopulation),
            duplicateRegisteredPaths: self::sorted($duplicateRegisteredPaths),
            inspectionFailures: self::sorted($inspectionFailures),
        );
    }

    /**
     * 観測が母集合とちょうど一致し、比較状態が矛盾していないことを確かめる。
     *
     * @param  array<string, PathObservation>  $observations
     * @param  array<string, string>  $templateHashes
     *
     * @throws RuntimeException
     */
    private static function assertObservationsCoverPopulation(array $observations, array $templateHashes): void
    {
        $population = self::sorted(array_keys($templateHashes));
        $observed = self::sorted(array_keys($observations));

        if ($population !== $observed) {
            throw new RuntimeException(sprintf(
                '観測の集合が母集合と一致しない (母集合にだけある: %s / 観測にだけある: %s)',
                implode(', ', array_diff($population, $observed)) ?: '無し',
                implode(', ', array_diff($observed, $population)) ?: '無し',
            ));
        }

        foreach ($observations as $path => $observation) {
            if ($observation->state === ComparisonState::Matched && $observation->currentHash !== $templateHashes[$path]) {
                throw new RuntimeException("観測が一致と称しているのに正典側ハッシュと違う: {$path}");
            }
            if ($observation->state === ComparisonState::ContentMismatch && $observation->currentHash === $templateHashes[$path]) {
                throw new RuntimeException("観測が相違と称しているのに正典側ハッシュと同じ: {$path}");
            }
        }
    }

    /**
     * @param  list<string>|array<int|string, string>  $paths
     * @return list<string>
     */
    private static function sorted(array $paths): array
    {
        $values = array_values($paths);
        sort($values, SORT_STRING);

        return $values;
    }
}
