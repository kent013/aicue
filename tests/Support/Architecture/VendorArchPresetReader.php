<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use ReflectionClass;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * vendor の Pest arch preset の**ソース表現**から禁止語彙を抽出する純関数
 * (`ArchBaselineTest` の S5 用)。
 *
 * **抽出定義 (これが正本)**:
 * `expect(` の直後に始まる**配列リテラル**のうち、閉じ括弧の後に
 * `->not->toBeUsed()` が続くものの文字列要素。
 * `expect('App\Providers')->not->toBeUsed()` のような**文字列引数の形は対象外**である
 * (層の指定であって禁止語彙ではない)。同じく `->toOnlyBeUsedIn([...])` のように
 * **`expect(` の直後ではない**配列も対象外である。
 *
 * **配列要素の受け付け方 (fail-closed)**:
 * - **単一引用符の `T_CONSTANT_ENCAPSED_STRING` だけ**を受け付ける
 * - 解くエスケープは `\\` と `\'` の**2 つだけ**。それ以外のエスケープが現れたら例外
 * - **キー付き要素 (`=>`) / spread (`...`) / 式 / ネストした配列 / 変数 /
 *   二重引用符文字列 / ヒアドキュメントは、すべて例外**
 * - 期待する配列の個数と実数が違えば例外 (0 個でも 2 個でも赤)
 *
 * ★**vendor の公開 API ではなくソース表現に依存する**。`composer update` で赤くなり得るのは
 *   **仕様**であり、そのときは `ArchBaseline::RULES` を更新する。
 *   preset の実体は `@internal` であり、実行して式を取り出す口が無い
 *   (`AbstractPreset::execute()` は Pest のテスト宣言を副作用として積む)。
 *
 * ★**保証しないもの**: preset が語彙を定数・別メソッド・配列の合成で組み立てる書き方へ
 *   変わった場合は抽出できない。そのときは**無言で空を返さず例外**になる
 *   (期待個数との不一致で落ちる)。**この構文について検出力を主張しない**。
 */
final class VendorArchPresetReader
{
    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * preset ソースの文字列から「禁止語彙の配列」を抽出する。
     *
     * @param  int  $expectedArrayCount  期待する配列リテラルの個数 (0 個でも超過でも例外)
     * @return list<string> 語彙 (重複なし・昇順)
     */
    public static function forbiddenSymbolsFromSource(string $source, int $expectedArrayCount): array
    {
        $tokens = ArchTokenStream::significantTokens($source, self::class);
        $total = count($tokens);

        $symbols = [];
        $arrayCount = 0;

        for ($index = 0; $index < $total; $index++) {
            $token = $tokens[$index];

            if ($token['id'] !== T_STRING || mb_strtolower($token['text']) !== 'expect') {
                continue;
            }

            if (! ArchTokenStream::isPunctuation($tokens, $index + 1, '(')
                || ! ArchTokenStream::isPunctuation($tokens, $index + 2, '[')) {
                continue; // 層の指定 (`expect('App\Providers')`) など。禁止語彙の配列ではない
            }

            [$elements, $closingIndex] = self::readStringArray($tokens, $index + 2);

            if (! self::isFollowedByNotToBeUsed($tokens, $closingIndex)) {
                continue; // 別の期待 (`->toBeClasses()` 等) に付く配列は禁止語彙ではない
            }

            $arrayCount++;
            foreach ($elements as $element) {
                $symbols[] = $element;
            }
        }

        if ($arrayCount !== $expectedArrayCount) {
            throw new RuntimeException(
                self::class.": 禁止語彙の配列が期待個数と一致しない (期待 {$expectedArrayCount} 個 / 実測 {$arrayCount} 個)"
            );
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        return $symbols;
    }

    /**
     * `Pest\ArchPresets\{Php,Security,Laravel}` の**ソース**から抽出する薄いラッパー。
     *
     * `class_exists()` で実在を確認 → `ReflectionClass::getFileName()` で解決する
     * (**パスを直書きしない**)。
     *
     * @param  class-string  $presetClass
     * @return list<string>
     */
    public static function forbiddenSymbolsOf(string $presetClass): array
    {
        Assert::classExists($presetClass, "preset クラスが存在しない: {$presetClass}");

        $fileName = (new ReflectionClass($presetClass))->getFileName();
        Assert::string($fileName, "preset クラスのソースを解決できない: {$presetClass}");

        $source = file_get_contents($fileName);
        Assert::string($source, "preset クラスのソースを読めない: {$fileName}");

        return self::forbiddenSymbolsFromSource($source, 1);
    }

    /**
     * `[` の位置から単一引用符文字列だけの配列を読む。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{0: list<string>, 1: int} [要素, 閉じ `]` の位置]
     */
    private static function readStringArray(array $tokens, int $openIndex): array
    {
        $elements = [];
        $total = count($tokens);
        $cursor = $openIndex + 1;

        while ($cursor < $total) {
            if (ArchTokenStream::isPunctuation($tokens, $cursor, ']')) {
                return [$elements, $cursor];
            }

            $token = $tokens[$cursor];
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || ! str_starts_with($token['text'], "'")) {
                throw new RuntimeException(
                    self::class.": 禁止語彙の配列に単一引用符文字列以外の要素がある (行 {$token['line']}: {$token['text']})"
                );
            }

            $elements[] = self::unescapeSingleQuoted($token['text'], $token['line']);
            $cursor++;

            if (ArchTokenStream::isPunctuation($tokens, $cursor, ']')) {
                return [$elements, $cursor];
            }

            if (! ArchTokenStream::isPunctuation($tokens, $cursor, ',')) {
                $unexpected = $tokens[$cursor] ?? null;
                $text = $unexpected === null ? 'EOF' : $unexpected['text'];

                throw new RuntimeException(
                    self::class.": 禁止語彙の配列の要素区切りが `,` でも `]` でもない ({$text})"
                );
            }

            $cursor++;
        }

        throw new RuntimeException(self::class.': 禁止語彙の配列が閉じないまま EOF になった');
    }

    /**
     * 閉じ `]` の直後が `)->not->toBeUsed()` か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isFollowedByNotToBeUsed(array $tokens, int $closingIndex): bool
    {
        $expected = [')', '->', 'not', '->', 'toBeUsed', '(', ')'];

        foreach ($expected as $offset => $text) {
            $token = $tokens[$closingIndex + 1 + $offset] ?? null;
            if ($token === null || $token['text'] !== $text) {
                return false;
            }
        }

        return true;
    }

    /**
     * 単一引用符文字列のトークン綴りから中身を取り出す。
     *
     * 解くエスケープは `\\` と `\'` の 2 つだけで、それ以外は**例外**にする。
     */
    private static function unescapeSingleQuoted(string $literal, int $line): string
    {
        $body = substr($literal, 1, -1);
        $length = strlen($body);

        $value = '';
        for ($position = 0; $position < $length; $position++) {
            $character = $body[$position];

            if ($character !== '\\') {
                $value .= $character;

                continue;
            }

            $next = $body[$position + 1] ?? '';
            if ($next !== '\\' && $next !== "'") {
                throw new RuntimeException(
                    self::class.": 禁止語彙の文字列に未知のエスケープがある (行 {$line}: {$literal})"
                );
            }

            $value .= $next;
            $position++;
        }

        return $value;
    }
}
