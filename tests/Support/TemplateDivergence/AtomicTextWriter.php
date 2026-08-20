<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;
use Throwable;

/**
 * 平文の生成物 (採用時債務一覧) の原子的置換。
 *
 * `AtomicLedgerWriter` と同じ 5 つの契約 (同一ディレクトリ / dirname 不一致は書き込み前に fail /
 * 書き込みバイト長の確認 / **読み戻しの検証** / 失敗時は一時ファイルの掃除) を持つ。
 * 違いは 2 点だけである:
 *  1. 読み戻しの検証を**注入された検証関数**が行う (JSON 専用の
 *     `FingerprintLedger::fromJson()` を平文に使えないため)
 *  2. **失敗は例外で返す** (`replace(): void` + `RuntimeException`)。
 *     移植元の `AtomicLedgerWriter::replace()` は失敗理由を戻り値で返す形なので、
 *     **呼び出し側が戻り値を無視すると fail-open になる**。本クラスは新規なので
 *     その形を持ち込まない (正典との差は `docs/template-divergence.md` D33 の範囲内である)
 *
 * ★**保証しないもの**: 原子性は**1 ファイル単位**である。異なるディレクトリの 2 生成物を
 *   セットとして原子的に置き換えることはできない (rename が跨げない)。
 *   片方だけが更新された状態は**突合 gate の F14 (世代識別子の突き合わせ)** が落とす。
 */
final class AtomicTextWriter
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @param  callable(): (string|false)  $tempPathFactory  同一ディレクトリの一時パス生成
     * @param  callable(string, string): (int|false)  $writer  file_put_contents 相当
     * @param  callable(string): (string|false)  $reader  file_get_contents 相当
     * @param  callable(string, string): bool  $renamer  rename 相当
     * @param  callable(string): bool  $remover  unlink 相当 (掃除)
     * @param  callable(string): void  $validator  読み戻した内容の検証 (不合格は例外を投げる)
     *
     * @throws RuntimeException どの段で失敗しても投げる (正本のバイト列は変えない)
     */
    public static function replace(
        string $targetPath,
        string $contents,
        callable $tempPathFactory,
        callable $writer,
        callable $reader,
        callable $renamer,
        callable $remover,
        callable $validator,
    ): void {
        $tempPath = $tempPathFactory();
        if ($tempPath === false || $tempPath === '') {
            throw new RuntimeException('一時ファイルのパスを生成できない (正本には触れていない)');
        }

        if (dirname($tempPath) !== dirname($targetPath)) {
            throw new RuntimeException(sprintf(
                '一時ファイルが正本と別ディレクトリにある (rename が同一 FS に閉じない): %s vs %s',
                dirname($tempPath),
                dirname($targetPath),
            ));
        }

        $written = $writer($tempPath, $contents);
        if ($written === false || $written !== strlen($contents)) {
            self::fail(
                $remover,
                $tempPath,
                sprintf(
                    '一時ファイルへの書き込みが完了しなかった (期待 %d バイト / 実際 %s)',
                    strlen($contents),
                    $written === false ? 'write 失敗' : (string) $written,
                ),
            );
        }

        $readBack = $reader($tempPath);
        if ($readBack === false) {
            self::fail($remover, $tempPath, '一時ファイルを読み直せない');
        }

        try {
            $validator((string) $readBack);
        } catch (Throwable $e) {
            self::fail($remover, $tempPath, '書き出した内容が検証を通らない: '.$e->getMessage());
        }

        if (! $renamer($tempPath, $targetPath)) {
            self::fail($remover, $tempPath, 'rename による正本の置換に失敗した');
        }
    }

    /**
     * 一時ファイルの掃除を試み、例外を投げる。
     *
     * @param  callable(string): bool  $remover
     *
     * @throws RuntimeException 常に投げる
     */
    private static function fail(callable $remover, string $tempPath, string $reason): never
    {
        if ($remover($tempPath)) {
            throw new RuntimeException($reason.' (正本は変更していない)');
        }

        throw new RuntimeException(
            $reason." (正本は変更していない。ただし一時ファイル {$tempPath} の削除にも失敗した — 手で消すこと)",
        );
    }
}
