<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 「regular file であることを確かめてから読む」**読み取り境界**。
 *
 * (純関数ではない — ファイル種別の確認と読み取りという I/O を行う。
 *  副作用は無いが、同じ引数でも結果はファイルシステムの状態で変わる。)
 *
 * ★本機構が正本として読むファイル (指紋台帳・採用時債務一覧) は**symlink を受理しない**。
 *   `file_get_contents()` はリンク先を読むので、リンクを差し替えるだけで
 *   **母集合や債務の内容ごと入れ替えられる**。判定を 1 か所へ集めて、
 *   利用側がうっかり素の `file_get_contents()` を呼ばないようにする。
 *
 * ★**読めないことを空へ潰さない** (fail-open を作らない)。落とす形は 4 つである:
 *   symlink である / 存在しない / 通常ファイルでない (ディレクトリ等) / 読み取りが失敗した。
 *
 * ★**保証しないもの**: 見るのは呼ばれた時点の状態だけである (TOCTOU は閉じない)。
 *   ファイルの中身の妥当性は見ない (それは呼び出し側の解析器の担当である)。
 *   利用側が本クラスを通さずに読む経路を機械では塞げない (レビューの義務)。
 *
 * 負例と正例は `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が固定する。
 */
final class RegularFileReader
{
    /** インスタンス化しない (静的な読み取り境界のみ)。 */
    private function __construct() {}

    /**
     * @param  string  $label  失敗メッセージに出す名前 (どの正本の話か分かるようにする)
     * @param  (callable(string): (string|false))|null  $reader  読み取り器 (既定は file_get_contents)。
     *                                                           **注入できるのは「regular file の判定を通った後に読み取りが失敗する」分岐を
     *                                                           独立して負例で裏取りするため**である (symlink と不在は手前の分岐で落ちるので、
     *                                                           実ファイルだけでは最後の分岐に到達できない)。
     *
     * @throws RuntimeException symlink / 不在 / 通常ファイルでない / 読み取り失敗
     */
    public static function read(string $path, string $label, ?callable $reader = null): string
    {
        if (is_link($path)) {
            throw new RuntimeException("{$label} が symlink である (内容を差し替えられるため受理しない): {$path}");
        }
        if (! file_exists($path)) {
            throw new RuntimeException("{$label} が存在しない: {$path}");
        }
        if (! is_file($path)) {
            throw new RuntimeException("{$label} が通常ファイルでない: {$path}");
        }

        $contents = ($reader ?? static fn (string $p): string|false => file_get_contents($p))($path);
        if ($contents === false) {
            throw new RuntimeException("{$label} を読めない: {$path}");
        }

        return $contents;
    }
}
