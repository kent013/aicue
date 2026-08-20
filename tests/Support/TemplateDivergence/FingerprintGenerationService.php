<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 生成 1 回分の判定と書き出し。
 *
 * ★**判定はすべてここに閉じる**。CLI (`scripts/update-template-fingerprints.php`) は
 *   引数解析と終了コードの写像だけを持つ薄い層である。root・入力・出力先・writer・
 *   pin の期待値をすべて引数で受けるので、**一時ディレクトリを root にして直接呼べる**
 *   (プロセスを起動しないテストが書ける)。
 *
 * ★**両生成物の内容は書き込みを始める前に完全に組み立て、検証まで終える**
 *   (組み立て中の失敗で正本に触れないため)。異なるディレクトリの 2 ファイルなので
 *   **セット単位の原子性は主張しない** — 書き込み開始後の I/O 失敗では片方だけが
 *   更新され得る。その状態は突合 gate の F5 / F9・F10 / **F14 (世代識別子)** の
 *   いずれかで必ず不合格になる。とくに**件数が変わらない部分更新は F14 が検出する**
 *   (件数 pin だけでは増減が相殺されて緑になり得る)。
 *
 * ★**`AtomicLedgerWriter::replace()` の戻り値を無視しない**。非 null は即座に例外にする
 *   (戻り値を無視すると fail-open になる)。この配線は単体テストが固定する。
 *
 * 終了コードの写像は例外の型で決まる: `GenerationRefused` = 3 / `RuntimeException` = 1。
 */
final class FingerprintGenerationService
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @param  string  $templateLedgerRaw  入力の正典台帳の**生バイト列**
     * @param  list<string>  $trackedPaths  git 追跡ファイル
     * @param  callable(string): string  $hasher  repo-relative パス => sha256
     * @param  list<string>  $registeredTargetPaths  登録簿の全対象パス
     * @param  int  $divergenceEntryCount  登録簿の登録件数 (報告に載せるだけ。判定には使わない)
     * @param  array<string, string>  $existingDebt  既存の債務一覧
     * @param  callable(string): (string|false)  $tempPathFactory  正本のパスを受けて一時パスを返す
     * @param  callable(string, string): (int|false)  $writer
     * @param  callable(string): (string|false)  $reader
     * @param  callable(string, string): bool  $renamer
     * @param  callable(string): bool  $remover
     * @return array{
     *     populationCount: int,
     *     adoptionDebtCount: int,
     *     divergenceEntryCount: int,
     *     matched: int,
     *     mismatched: int,
     *     missing: int,
     *     addedDebt: list<string>,
     *     templateLedgerCommit: string,
     *     seeded: bool,
     * }
     *
     * @throws GenerationRefused ガードによる拒否 (終了コード 3)
     * @throws RuntimeException 実行不能 (終了コード 1)
     */
    public static function generate(
        FingerprintGenerationContext $context,
        string $templateLedgerRaw,
        array $trackedPaths,
        callable $hasher,
        array $registeredTargetPaths,
        int $divergenceEntryCount,
        array $existingDebt,
        callable $tempPathFactory,
        callable $writer,
        callable $reader,
        callable $renamer,
        callable $remover,
    ): array {
        // --- 入力の出自 (pin との一致) ---
        $actualSha256 = hash('sha256', $templateLedgerRaw);
        if ($actualSha256 !== $context->expectedTemplateLedgerSha256 && ! $context->adoptNewTemplateLedger) {
            throw new GenerationRefused(sprintf(
                '入力の正典台帳が pin と違う (実測 %s / pin %s)。'
                    .'台帳を載せ替えるなら --adopt-new-template-ledger を明示すること。',
                $actualSha256,
                $context->expectedTemplateLedgerSha256,
            ));
        }

        // --- 入力の構造と正準形 (非正準な JSON を採用経路から通さない) ---
        $templateLedger = FingerprintLedger::fromJson($templateLedgerRaw);
        if ($templateLedgerRaw !== $templateLedger->toJson()) {
            throw new RuntimeException(
                '入力の正典台帳が正準形バイト一致でない (重複キー / 非正準な整形 / 末尾改行の欠落)。'
                    .'正典側の生成器で作られた台帳をそのまま渡すこと。',
            );
        }
        if ($templateLedger->role !== LedgerRole::Template) {
            throw new RuntimeException('入力の正典台帳の role が template でない');
        }
        if ($trackedPaths === []) {
            throw new RuntimeException('git 追跡ファイルが 0 件と算出された (実行不能として落とす)');
        }

        // --- 母集合の縮小の拒否 (同じ正典入力のまま狭めさせない) ---
        if (! $context->adoptNewTemplateLedger && $context->previousLedger !== null) {
            $dropped = array_values(array_diff(
                array_keys($context->previousLedger->entries),
                array_keys($templateLedger->entries),
            ));
            if ($dropped !== []) {
                throw new GenerationRefused(
                    '同じ正典入力のまま母集合を縮小しようとした (正典側から消えていないパス: '
                        .implode(', ', array_slice($dropped, 0, 10)).')',
                );
            }
        }

        $built = AppFingerprintBuilder::build(
            $templateLedger,
            $trackedPaths,
            $hasher,
            $registeredTargetPaths,
            $existingDebt,
            $context->previousLedger,
        );

        // --- 生成物を書き込み前に完全に組み立て、検証まで終える ---
        $ledgerContents = $built['ledger']->toJson();
        if ($ledgerContents !== FingerprintLedger::fromJson($ledgerContents)->toJson()) {
            throw new RuntimeException('組み立てた指紋台帳が正準形でない (生成器の不整合)');
        }

        $debtContents = AdoptionDebtInventory::render($templateLedger->generatedAtCommit, $built['debt']);
        $parsedDebt = AdoptionDebtInventory::parse($debtContents);
        if ($parsedDebt['entries'] !== $built['debt']) {
            throw new RuntimeException('組み立てた採用時債務一覧を読み戻せない (生成器の不整合)');
        }

        // --- 書き出し (どちらも読み戻して検証してから rename する) ---
        $reason = AtomicLedgerWriter::replace(
            $context->fingerprintOutputPath,
            $ledgerContents,
            static fn (): string|false => $tempPathFactory($context->fingerprintOutputPath),
            $writer,
            $reader,
            $renamer,
            $remover,
        );
        if ($reason !== null) {
            throw new RuntimeException('指紋台帳を置換できない: '.$reason);
        }

        AtomicTextWriter::replace(
            $context->debtOutputPath,
            $debtContents,
            static fn (): string|false => $tempPathFactory($context->debtOutputPath),
            $writer,
            $reader,
            $renamer,
            $remover,
            static function (string $contents): void {
                AdoptionDebtInventory::parse($contents);
            },
        );

        return [
            'populationCount' => count($built['ledger']->entries),
            'adoptionDebtCount' => count($built['debt']),
            'divergenceEntryCount' => $divergenceEntryCount,
            'matched' => $built['matched'],
            'mismatched' => $built['mismatched'],
            'missing' => $built['missing'],
            'addedDebt' => $built['addedDebt'],
            'templateLedgerCommit' => $templateLedger->generatedAtCommit,
            'seeded' => $built['seeded'],
        ];
    }
}
