<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 「その PHP ソースで厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 *
 * ★**検体自身の出力や制御フローを判定材料にしない**。判定は
 *   「追記したプローブに到達し、その場で観測した結果」だけを見る:
 *   1. 終了コードが 0 でない (Fatal / Parse error で読み込めない) → 厳密化は成立しない = false
 *   2. 終了コードが 0 なら、標準出力は **nonce つきの標識と完全一致**していなければならない。
 *      一致しない場合 (検体が自分で出力した / `exit` や `__halt_compiler()` で
 *      プローブへ到達しなかった / `?>` の後ろとして素通しされた) は**実測不能**として
 *      例外にする。false を返して黙らない (fail-open 防止)
 *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
 *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
     * @return bool 厳密化が実際に効いたか
     *
     * @throws RuntimeException 検体がプローブと干渉して実測できないとき
     */
    public static function strictTypesInEffect(string $phpSource): bool
    {
        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
        if ($path === false) {
            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
        }

        $nonce = bin2hex(random_bytes(8));
        $probe = <<<PHP

            function probe_{$nonce}(int \$value): int { return \$value; }
            try { probe_{$nonce}("1"); echo 'WEAK-{$nonce}'; }
            catch (\\TypeError \$e) { echo 'STRICT-{$nonce}'; }
            PHP;

        try {
            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            if (! $process->isSuccessful()) {
                return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
            }

            $output = trim($process->getOutput());
            if ($output === 'STRICT-'.$nonce) {
                return true;
            }
            if ($output === 'WEAK-'.$nonce) {
                return false;
            }

            throw new RuntimeException(
                '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
                ."出力: {$output}"
            );
        } finally {
            @unlink($path);
        }
    }
}
