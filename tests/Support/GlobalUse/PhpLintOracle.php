<?php

declare(strict_types=1);

namespace Tests\Support\GlobalUse;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `php -l` を真値として、非複合名の import の警告を取り出す。
 *
 * ★実行系は **いまテストを走らせている PHP そのもの** (`PHP_BINARY`) を使う。
 *   別の php を探しに行かないので「手元と CI で版が違うと結果が変わる」問題は起きない
 *   (その実行系が警告を出す形を、その実行系で検出できているかを見る検査になる)。
 * ★`-n` で php.ini を読ませない (opcache 等の状態に左右されない)。
 * ★警告は **標準出力**へ出る (実測)。標準エラーも合わせて返すのは、
 *   プロセスの起動失敗や実行環境側の異常が標準エラーにしか出ないことがあるためである。
 * ★`syntaxValid` の主判定は **終了コード**である (実測: 構文が正しければ警告が出ていても 0、
 *   構文エラーなら 255)。「構文エラーなし」の文言は診断用にだけ使い判定には使わない
 *   (文言は版で変わりうるが終了コードの意味は変わらない)。
 */
final class PhpLintOracle
{
    /** 警告文から名前と行を取り出す規則。文言が変わったら 0 件になるので空振り検知が要る。 */
    private const string WARNING_PATTERN = "/non-compound name '([^']+)' has no effect in .+ on line (\\d+)/";

    /**
     * `php -l` を起動する Process を組み立てる (inspect() が使う。配線検査からも観測できる)。
     *
     * ★子プロセスの言語環境は **`LC_ALL=C` に固定**する (家系の正典 t2)。
     *   警告文からの抽出 (WARNING_PATTERN) は英語の診断文に依存するため、
     *   英語以外の言語環境の開発機で真値の抽出が静かに壊れる (自己検査が空振りする方向)
     *   のを予防する。Symfony Process は明示 env を継承環境の上へ合成するので、
     *   他の環境変数の継承は保たれる。
     * ★**限界 (誇張しない)**: 機械保証は「本メソッドが返す Process の明示 env が
     *   LC_ALL=C の 1 変数ちょうどである」ことまで (gate 側の配線検査)。inspect() が
     *   本メソッドを経由することはコードレビューで見る。言語環境の差による出力差そのものは
     *   この開発機では観測できない (現行の PHP は診断文を翻訳しないため挙動差が出ない)。
     *   これは予防の固定である。
     */
    public static function buildProcess(string $absolutePath): Process
    {
        return new Process(
            [
                PHP_BINARY,
                '-n',
                '-d', 'error_reporting=E_ALL',
                '-d', 'display_errors=1',
                '-d', 'log_errors=0',
                '-l',
                $absolutePath,
            ],
            null,
            ['LC_ALL' => 'C'],
        );
    }

    /**
     * 見本ファイルに対して `php -l` を **1 回だけ**実行し、結果を丸ごと返す。
     *
     * @return array{
     *     warnings: list<array{name: string, line: int}>,
     *     syntaxValid: bool,
     *     exitCode: int,
     *     stdout: string,
     *     stderr: string,
     * }
     */
    public static function inspect(string $absolutePath): array
    {
        $process = self::buildProcess($absolutePath);
        $process->run();

        $exitCode = $process->getExitCode();
        if ($exitCode === null) {
            // null を 0 と読むと構文エラーを合格へ倒しかねないので例外にする (fail-closed)。
            throw new RuntimeException('php -l の終了コードを取得できませんでした: '.$absolutePath);
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $matched = preg_match_all(self::WARNING_PATTERN, $stdout, $matches, PREG_SET_ORDER);
        if ($matched === false) {
            throw new RuntimeException('php -l の出力の照合に失敗しました: '.$absolutePath);
        }

        $warnings = [];
        foreach ($matches as $match) {
            $warnings[] = ['name' => $match[1], 'line' => (int) $match[2]];
        }

        return [
            'warnings' => $warnings,
            'syntaxValid' => $exitCode === 0,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
