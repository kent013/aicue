<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 指紋台帳の原子的置換。
 *
 * 同一ディレクトリの一時ファイルへ書き、(1) 書き込みバイト長、(2) 読み直した内容が
 * `FingerprintLedger::fromJson()` を通ること、を確認してから rename で置換する。
 * **どの段で失敗しても正本のバイト列を変えない** (切り詰められた JSON を正本に残さない)。
 *
 * 一時ファイルの契約:
 *  (a) 一時ファイルは正本と同一ディレクトリに作る (rename を同一 FS に閉じる)
 *  (b) 一時パスの dirname が正本の dirname と一致しなければ**書き込み前に** fail する
 *  (c) 一時パス生成の失敗は正本に触れずに失敗を返す
 *  (d) write / read / rename のどの失敗でも一時ファイルの削除を試み、削除にも失敗したら
 *      **元の失敗に加えてその旨を報告する** (「削除失敗時も残らない」とは主張しない)
 *  (e) rename 成功後は一時ファイルが存在しない (rename が消費するため)
 *
 * I/O はすべて注入する (失敗注入でユニットテストできるようにするため)。
 *
 * ★**本クラスは JSON 専用である** — 読み戻しの検証が `FingerprintLedger::fromJson()` に
 *   固定されているため、採用時債務一覧のような平文の生成物には使えない。
 *   平文は `AtomicTextWriter` (検証関数を注入する版) が書く。両者は同じ 5 つの契約を持ち、
 *   違いは読み戻しの検証を誰が行うかと、失敗を**戻り値で返すか例外で投げるか**だけである。
 *   本クラスは正典 (laravel-claude-template) からの移植なので戻り値の形を保つ。
 *   **呼び出し側は戻り値が null でないことを必ず判定して失敗させること**
 *   (無視すると fail-open になる。`FingerprintGenerationService` がそれを固定している)。
 */
final class AtomicLedgerWriter
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @param  callable(): (string|false)  $tempPathFactory  同一ディレクトリの一時パス生成
     * @param  callable(string, string): (int|false)  $writer  file_put_contents 相当
     * @param  callable(string): (string|false)  $reader  file_get_contents 相当
     * @param  callable(string, string): bool  $renamer  rename 相当
     * @param  callable(string): bool  $remover  unlink 相当 (掃除)
     * @return string|null 失敗理由 (null = 置換成功)
     */
    public static function replace(
        string $targetPath,
        string $contents,
        callable $tempPathFactory,
        callable $writer,
        callable $reader,
        callable $renamer,
        callable $remover,
    ): ?string {
        $tempPath = $tempPathFactory();
        if ($tempPath === false || $tempPath === '') {
            return '一時ファイルのパスを生成できない (正本には触れていない)';
        }

        if (dirname($tempPath) !== dirname($targetPath)) {
            return sprintf(
                '一時ファイルが正本と別ディレクトリにある (rename が同一 FS に閉じない): %s vs %s',
                dirname($tempPath),
                dirname($targetPath),
            );
        }

        $written = $writer($tempPath, $contents);
        if ($written === false || $written !== strlen($contents)) {
            return self::cleanup(
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
            return self::cleanup($remover, $tempPath, '一時ファイルを読み直せない');
        }

        try {
            FingerprintLedger::fromJson($readBack);
        } catch (RuntimeException $e) {
            return self::cleanup($remover, $tempPath, '書き出した内容が指紋台帳として解釈できない: '.$e->getMessage());
        }

        if (! $renamer($tempPath, $targetPath)) {
            return self::cleanup($remover, $tempPath, 'rename による正本の置換に失敗した');
        }

        return null;
    }

    /**
     * 一時ファイルの掃除を試み、失敗理由を組み立てる。
     *
     * @param  callable(string): bool  $remover
     */
    private static function cleanup(callable $remover, string $tempPath, string $reason): string
    {
        if ($remover($tempPath)) {
            return $reason.' (正本は変更していない)';
        }

        return $reason." (正本は変更していない。ただし一時ファイル {$tempPath} の削除にも失敗した — 手で消すこと)";
    }
}
