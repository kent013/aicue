<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use Webmozart\Assert\Assert;

/**
 * 指定した関数名の**素のグローバル関数呼び出し**を数える純関数 (`ArchBaselineTest` の S2 用)。
 *
 * ★**倒す向きが他の走査と逆である**。利用側 (S2) は「違反の検出」ではなく
 *   **「使用の証明」**なので、数えすぎ = 腐った例外登録の見逃し (危険)、
 *   数え漏らし = 赤 (安全) になる。したがって**狭く数える**。
 *
 * **数える**: `sha1(` / `\sha1(`
 * **数えない**: `->sha1(` / `?->sha1(` / `::sha1(` / `function sha1(` / `new sha1(` /
 * 直前が識別子 / `mysha1(` / `not_sha1(` / `sha1_file(` / `Foo\sha1(` / `\App\Other\sha1(`
 *
 * ★**大文字小文字を区別する**。`SHA1(` は数えない。これは
 *   **Pest 側の判定粒度に揃えるため**である — Pest は層の名前
 *   (`ArchBaseline::RULES` の綴り = 小文字) と AST に書かれた綴りを `===` で突き合わせる
 *   (`PHPUnit\Architecture\Asserts\Dependencies\DependenciesAsserts::getObjectsWhichUsesOnLayerAFromLayerB()`)
 *   ので、`SHA1(` を**検出しない**。したがって `SHA1(` しか無いクラスの例外登録は
 *   S2 で赤になるが、**それが正しい** — Pest が検出しない以上その例外登録は不要だからである。
 *   **`ArchSurfaceScanner` (S4) は逆に大小を無視する**。理由が逆なので混同しないこと。
 *
 * ★**接尾辞・接頭辞・打ち消しは原理的に混入しない** — トークン化は `mysha1` / `not_sha1` /
 *   `sha1_file` をそれぞれ 1 つの `T_STRING` として返すので、綴りの完全一致で自動的に弾かれる。
 *   `ArchBaselineScannerTest` の負例はこれを**固定するため**に置く (共通規約 (e) の 3 形)。
 *
 * ★**保証しない (数えない = 赤へ倒す)**: 可変関数 (`$f = 'sha1'; $f()`) /
 *   文字列経由の呼び出し / `.blade.php` / `tests/js/`。
 *   **この構文について検出力を主張しない**。
 *
 * ★**トークン化できない入力は例外**にする (`ArchTokenStream` が `TOKEN_PARSE` で担保)。
 *   **無言で 0 件を返さない**。
 */
final class GlobalFunctionCallScanner
{
    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * 直前に来ると「グローバル関数呼び出しではない」と判定するトークン。
     *
     * `T_STRING` を含むのは `new sha1(` のような**識別子の直後**を落とすためである。
     *
     * @var list<int>
     */
    private const array DISQUALIFYING_PREVIOUS_TOKENS = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_NEW,
        T_CONST,
        T_STRING,
    ];

    /**
     * ソース中の素のグローバル関数呼び出しを関数名ごとに数える。
     *
     * ★**0 件でもキーを残す** (「対象名が消えた」と「呼び出しが 0 件」を利用側が区別できるようにする)。
     *
     * @param  list<string>  $functionNames  綴りが**完全一致**する対象 (小文字で書く)
     * @return array<string, int> 関数名 => 件数
     */
    public static function countCallsInSource(string $source, array $functionNames): array
    {
        $counts = [];
        foreach ($functionNames as $functionName) {
            $counts[$functionName] = 0;
        }

        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $total = count($tokens);

        for ($index = 0; $index < $total; $index++) {
            $name = self::plainFunctionNameAt($tokens, $index);
            if ($name === null || ! array_key_exists($name, $counts)) {
                continue;
            }

            $counts[$name]++;
        }

        return $counts;
    }

    /**
     * ファイルを読んで {@see self::countCallsInSource()} へ委譲するだけのラッパー。
     *
     * @param  list<string>  $functionNames
     * @return array<string, int>
     */
    public static function countCallsInFile(string $absolutePath, array $functionNames): array
    {
        Assert::fileExists($absolutePath, "走査対象のファイルが存在しない: {$absolutePath}");

        $source = file_get_contents($absolutePath);
        Assert::string($source, "走査対象のファイルを読めない: {$absolutePath}");

        return self::countCallsInSource($source, $functionNames);
    }

    /**
     * 指定位置が「素のグローバル関数呼び出し」なら**呼ばれている関数名**を返す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function plainFunctionNameAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];

        if ($token['id'] === T_STRING) {
            $name = $token['text'];
        } elseif ($token['id'] === T_NAME_FULLY_QUALIFIED) {
            // `\sha1` は素のグローバル関数呼び出し。`\App\Other\sha1` は**別の関数**なので
            // 先頭の `\` を除いた綴りに `\` が残る = 完全一致しない。
            $name = substr($token['text'], 1);
        } else {
            return null;
        }

        if (! ArchTokenStream::isPunctuation($tokens, $index + 1, '(')) {
            return null;
        }

        $previousId = $tokens[$index - 1]['id'] ?? null;
        if ($previousId !== null && in_array($previousId, self::DISQUALIFYING_PREVIOUS_TOKENS, true)) {
            return null;
        }

        return $name;
    }
}
