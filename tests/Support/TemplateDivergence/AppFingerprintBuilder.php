<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 正典の指紋台帳と自リポジトリの追跡ファイルから、`role: app` の指紋台帳と
 * 採用時債務一覧を組み立てる (純関数。I/O は注入する)。
 *
 * 母集合の定義 (**2 通り**):
 *  - 初回生成 (前世代の台帳が無い): {正典のキー} ∩ {現在の git 追跡ファイル}
 *  - 2 回目以降: {正典のキー} ∩ ({現在の git 追跡ファイル} ∪ {旧アプリ台帳のキー})
 * 2 項目の和集合を取るのは、**ローカルでファイルを消してから再生成しても母集合から
 * 外せないようにする**ためである (消えたパスは突合 gate で `MissingCurrent` になる)。
 * 母集合から外れるのは**正典側から消えたパスだけ**である。
 * 値は**正典側の sha256 をそのまま写す** (テンプレート側の内容の指紋である)。
 *
 * 債務の更新規則:
 *  - **初回生成は「採用」である**。未登録の相違はすべて**その時点のアプリ側ハッシュで凍結**する
 *    (これが「採用時債務」の由来である)
 *  - **維持**: 既存の債務パスは採用時ハッシュをそのまま持ち越す (凍結された観測なので更新しない)
 *  - **削除**: 内容が正典と一致へ戻った / 登録簿へ登録された パスは債務から外す
 *  - **追加**: 2 回目以降で既存の債務に無いパスの追加は**原則として拒否**する。許すのは
 *    前世代の台帳にそのパスがあり、**現在のアプリ側ハッシュが前世代の正典ハッシュと一致する**
 *    ときだけである (= 差が生じた原因がテンプレート側の前進であることの証明)。
 *    それ以外は「自分が変えたのに登録していない食い違い」なので通さない
 *    (登録を書くか内容を戻す)
 *
 * **保証しないもの**:
 *  - 正典側に存在しないパス (自リポジトリの追加) は母集合に入らない
 *  - 正典側にしか無いパス (未受領 / 追従遅れ) も母集合に入らない
 *  - **初回生成の経路は債務一覧を作り直せる**。アプリ側の指紋台帳を消してから生成器を
 *    走らせると採用がやり直しになるので、この経路は**件数 pin の差分と PR レビュー**でしか
 *    止まらない (指紋台帳・債務一覧・pin・gate 自身の手編集が止まらないのと同じ原理的限界)
 */
final class AppFingerprintBuilder
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @param  list<string>  $trackedPaths  git 追跡ファイル (重複や不正パスは例外。並び順は問わない)
     * @param  callable(string): string  $hasher  自リポジトリのファイルの sha256
     *                                            (**戻り値が 64 桁小文字 hex でなければ例外**。読めない場合も例外)
     * @param  list<string>  $registeredTargetPaths  登録簿の全対象パス
     * @param  array<string, string>  $existingDebt  既存の債務一覧 (path => 採用時のアプリ側 sha256)
     * @return array{
     *     ledger: FingerprintLedger,
     *     debt: array<string, string>,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     addedDebt: list<string>,
     *     seeded: bool,
     * }
     *
     * @throws GenerationRefused 債務へ新規パスを追加しようとしたとき
     * @throws RuntimeException 入力が不正なとき / 母集合が 0 件のとき / ハッシュを計算できないとき
     */
    public static function build(
        FingerprintLedger $templateLedger,
        array $trackedPaths,
        callable $hasher,
        array $registeredTargetPaths,
        array $existingDebt,
        ?FingerprintLedger $previousLedger,
    ): array {
        if ($templateLedger->role !== LedgerRole::Template) {
            throw new RuntimeException('入力が正典の指紋台帳でない (role: template を要求する)');
        }
        if ($previousLedger !== null && $previousLedger->role !== LedgerRole::App) {
            throw new RuntimeException('前世代の指紋台帳の role が app でない');
        }

        $tracked = self::uniquePathSet($trackedPaths, 'git 追跡ファイル');
        $registered = self::uniquePathSet($registeredTargetPaths, '登録簿の対象パス');
        self::assertDebtShape($existingDebt);

        $seeding = $previousLedger === null;

        // --- 母集合 (正典のキーとの積。2 回目以降は旧台帳のキーも候補に残す) ---
        $candidates = $tracked;
        if ($previousLedger !== null) {
            foreach (array_keys($previousLedger->entries) as $path) {
                $candidates[$path] = true;
            }
        }

        $population = [];
        foreach ($templateLedger->entries as $path => $templateHash) {
            if (array_key_exists($path, $candidates)) {
                $population[$path] = $templateHash;
            }
        }
        ksort($population, SORT_STRING);

        if ($population === []) {
            throw new RuntimeException(
                '母集合が 0 件と算出された。0 件は合格ではなく実行不能として落とす (正典 boundary (5b))。',
            );
        }

        $debt = [];
        $addedDebt = [];
        $matched = 0;
        $mismatched = 0;
        $missing = 0;

        foreach ($population as $path => $templateHash) {
            $isRegistered = array_key_exists($path, $registered);

            if (! array_key_exists($path, $tracked)) {
                // 旧台帳にだけあるパス = ローカルで消された (母集合からは外さない)
                $missing++;
                if (array_key_exists($path, $existingDebt)) {
                    throw new RuntimeException(
                        "債務パスが git 追跡から消えている: {$path} — 復元するか、逸脱として登録簿へ書くこと",
                    );
                }
                if (! $isRegistered) {
                    throw new GenerationRefused(
                        "git 追跡から消えた未登録パスは債務へ追加できない: {$path} — 復元するか登録簿へ書くこと",
                    );
                }

                continue;
            }

            $currentHash = self::hashOf($hasher, $path);

            if ($currentHash === $templateHash) {
                $matched++;

                continue; // 一致へ戻ったパスは債務からも外れる
            }

            $mismatched++;

            if ($isRegistered) {
                continue; // 登録簿に説明がある = 債務ではない
            }

            if (array_key_exists($path, $existingDebt)) {
                $debt[$path] = $existingDebt[$path]; // 採用時ハッシュを持ち越す (更新しない)

                continue;
            }

            if ($seeding) {
                $debt[$path] = $currentHash; // 採用: この時点の姿を凍結する
                $addedDebt[] = $path;

                continue;
            }

            $previousTemplateHash = $previousLedger->entries[$path] ?? null;
            if ($previousTemplateHash !== null && $currentHash === $previousTemplateHash) {
                // 前世代の正典と一致していた = 差の原因はテンプレート側の前進である
                $debt[$path] = $currentHash;
                $addedDebt[] = $path;

                continue;
            }

            throw new GenerationRefused(sprintf(
                '債務一覧へ新規パスを追加しようとした: %s — 自分で変えた食い違いは債務にできない。'
                    .'逸脱として docs/template-divergence.md へ登録するか、内容をテンプレートへ戻すこと。',
                $path,
            ));
        }

        ksort($debt, SORT_STRING);
        sort($addedDebt, SORT_STRING);

        return [
            'ledger' => new FingerprintLedger(
                FingerprintLedger::SCHEMA_VERSION,
                LedgerRole::App,
                $templateLedger->generatedAtCommit,
                $population,
            ),
            'debt' => $debt,
            'matched' => $matched,
            'mismatched' => $mismatched,
            'missing' => $missing,
            'addedDebt' => $addedDebt,
            'seeded' => $seeding,
        ];
    }

    /**
     * パスの一覧を「重複なし・書式が正しい」集合へ変える。
     *
     * @param  list<string>  $paths
     * @return array<string, true>
     *
     * @throws RuntimeException
     */
    private static function uniquePathSet(array $paths, string $label): array
    {
        $set = [];
        foreach ($paths as $path) {
            if (! RepoRelativePath::isValid($path)) {
                throw new RuntimeException("{$label} に単一ファイルパスでない値がある: ".var_export($path, true));
            }
            if (array_key_exists($path, $set)) {
                throw new RuntimeException("{$label} にパスの重複がある: {$path}");
            }
            $set[$path] = true;
        }

        return $set;
    }

    /**
     * 既存の債務一覧の形を確かめる。
     *
     * @param  array<string, string>  $existingDebt
     *
     * @throws RuntimeException
     */
    private static function assertDebtShape(array $existingDebt): void
    {
        foreach ($existingDebt as $path => $hash) {
            if (! RepoRelativePath::isValid((string) $path)) {
                throw new RuntimeException('既存の債務一覧に単一ファイルパスでないキーがある: '.var_export($path, true));
            }
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException("既存の債務一覧の採用時ハッシュが 64 桁小文字 hex でない: {$path}");
            }
        }
    }

    /**
     * 注入されたハッシュ関数を呼び、戻り値の書式まで確かめる。
     *
     * @param  callable(string): string  $hasher
     *
     * @throws RuntimeException
     */
    private static function hashOf(callable $hasher, string $path): string
    {
        $hash = $hasher($path);

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new RuntimeException("ハッシュ関数が 64 桁小文字 hex を返さなかった: {$path}");
        }

        return $hash;
    }
}
