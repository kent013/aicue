<?php

declare(strict_types=1);

namespace Tests\Support\Prompts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Prism Facade の LLM 系メソッド (`Prism::text()`, `Prism::structured()`, `Prism::stream()`,
 * `Prism::embeddings()`, `Prism::image()`, `Prism::audio()`, `Prism::moderation()`) を
 * 直接呼び出すコードを token ベースで検出する scanner。
 *
 * ★走査根は **`app/` + `routes/` + `database/` + `config/` + `bootstrap/` の 5 本**である
 *   (`routes/` のクロージャや seeder から直呼びできる場所を残さない)。
 *   scanner は `token_get_all` ベースでコメント・docblock・文字列リテラルを無視するため、
 *   `config/` を加えてもコメント中の文字列で偽陽性は出ない。
 *
 * ★`tests/Architecture/PromptGuardrailTest.php` から**移設**した (振る舞い不変)。
 *   Pest の `--parallel` はファイル単位でプロセスを分けるため、テストファイル内の
 *   グローバルクラスは他 gate から参照できない。委譲の生存確認
 *   (`ExternalSeamInventoryTest`) が本クラスを呼ぶため `tests/Support/` へ置く
 *   (`Tests\Support\QueueLeaseConfig` と同じ規律)。
 *
 * 検出アルゴリズム:
 *  - `token_get_all()` で PHP code をトークン化し、コメント / docblock / 文字列リテラル中の
 *    出現は無視する (誤検出防止)。
 *  - `Prism::method(` を `識別子 + T_DOUBLE_COLON + T_STRING(method) + '('` の sequence で判定。
 *  - 識別子が `Prism` 単体 (use alias 経由) または `Prism\Prism\Facades\Prism` (完全修飾名) の
 *    場合のみ facade とみなす。`Foo\Bar\Prism::text(` のような同名別クラスは誤検出しない。
 *  - method 名は case-insensitive 比較 (PHP のメソッド呼び出し仕様に整合)。
 *  - `use ... as alias` / カンマ区切り use も解決する。
 */
final class PrismDirectDispatchScanner
{
    /**
     * ★`moderation` は現行 vendor に無くても deny 側に置く (後から生えたときに黙って通らない)。
     *
     * @var list<string>
     */
    private const array TARGET_METHODS = ['text', 'structured', 'stream', 'embeddings', 'image', 'audio', 'moderation'];

    /**
     * @var list<string> リポジトリルートからの相対パスで指定。テンプレートは allowlist 不要のため空。
     *                   将来正当な理由で直叩きが必要になった場合のみ追加し、理由を明記すること。
     */
    private const array ALLOWED_FILES = [];

    /** 走査根 (リポジトリルートからの相対パス)。 */
    private const array ROOT_DIRECTORIES = ['app', 'routes', 'database', 'config', 'bootstrap'];

    /**
     * 走査根 (相対パス => 絶対パス)。**存在しない根は fail-fast** で落とす
     * (根の移動 / typo で黙って PASS する事故を防ぐ)。
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $repoRoot = dirname(__DIR__, 3);

        $roots = [];
        foreach (self::ROOT_DIRECTORIES as $relative) {
            $absolute = realpath($repoRoot.'/'.$relative);
            if (! is_string($absolute)) {
                throw new RuntimeException("走査根を解決できません: {$relative}");
            }
            $roots[$relative] = $absolute;
        }

        return $roots;
    }

    /**
     * 走査対象ファイル (**空振り防止 / 委譲の生存確認に使う**)。
     *
     * @return list<string> 絶対パス
     */
    public static function scannedFiles(): array
    {
        $files = [];
        foreach (self::roots() as $absolute) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> 違反ファイル (リポジトリルート相対パス)
     */
    public static function findViolations(): array
    {
        $violations = [];
        foreach (self::roots() as $relativeRoot => $absoluteRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $relative = $relativeRoot.'/'.ltrim(substr($path, strlen($absoluteRoot)), '/');
                if (in_array($relative, self::ALLOWED_FILES, true)) {
                    continue;
                }

                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new RuntimeException("Failed to read PHP source: {$path}");
                }

                if (self::containsPrismDirectCall($contents)) {
                    $violations[] = $relative;
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * `Prism::text(` 等の直接呼び出しを token-based で検出。
     * コメント / 文字列リテラル / docblock 内の出現は無視する。
     */
    public static function containsPrismDirectCall(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        $aliases = self::collectUseAliases($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }
            [$id, $value] = $token;

            // Prism Facade に限定。同名別クラス (Foo\Bar\Prism) を誤検出しない。
            if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            // alias map で短縮名 (T_STRING) を resolve してから facade 判定 (alias は case-insensitive)。
            $key = strtolower(ltrim($value, '\\'));
            $resolved = $aliases[$key] ?? $value;
            if (! self::isPrismFacadeIdentifier($resolved)) {
                continue;
            }

            // 直後の non-whitespace token が `::`
            $j = self::nextNonWhitespace($tokens, $i);
            if ($j === null) {
                continue;
            }
            $next = $tokens[$j];
            if (! is_array($next) || $next[0] !== T_DOUBLE_COLON) {
                continue;
            }

            // さらに次の non-whitespace token が target method (case-insensitive)
            $k = self::nextNonWhitespace($tokens, $j);
            if ($k === null) {
                continue;
            }
            $methodToken = $tokens[$k];
            if (! is_array($methodToken) || $methodToken[0] !== T_STRING) {
                continue;
            }
            if (! in_array(strtolower($methodToken[1]), self::TARGET_METHODS, true)) {
                continue;
            }

            // さらに次の non-whitespace token が `(` であれば確定
            $l = self::nextNonWhitespace($tokens, $k);
            if ($l === null) {
                continue;
            }
            if ($tokens[$l] === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * `use` 文を走査し、`{short_name_lowercase => fqn}` の map を返す。
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, string> lowercase short_name → fqn
     */
    private static function collectUseAliases(array $tokens): array
    {
        $aliases = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $cursor = $i + 1;
            while ($cursor < $count) {
                $nameIndex = self::nextNonWhitespace($tokens, $cursor - 1);
                if ($nameIndex === null) {
                    break;
                }
                $nameToken = $tokens[$nameIndex];
                if (! is_array($nameToken)) {
                    break;
                }
                if ($nameToken[0] !== T_STRING && $nameToken[0] !== T_NAME_QUALIFIED && $nameToken[0] !== T_NAME_FULLY_QUALIFIED) {
                    break;
                }

                $fqn = ltrim($nameToken[1], '\\');
                $segments = explode('\\', $fqn);
                $shortName = end($segments);
                $aliasIndexUsed = $nameIndex;

                $afterIndex = self::nextNonWhitespace($tokens, $nameIndex);
                if ($afterIndex !== null) {
                    $afterToken = $tokens[$afterIndex];
                    if (is_array($afterToken) && $afterToken[0] === T_AS) {
                        $aliasIndex = self::nextNonWhitespace($tokens, $afterIndex);
                        if ($aliasIndex !== null && is_array($tokens[$aliasIndex]) && $tokens[$aliasIndex][0] === T_STRING) {
                            $shortName = $tokens[$aliasIndex][1];
                            $aliasIndexUsed = $aliasIndex;
                        }
                    }
                }

                $aliases[strtolower($shortName)] = $fqn;

                $sepIndex = self::nextNonWhitespace($tokens, $aliasIndexUsed);
                if ($sepIndex === null) {
                    break;
                }
                if ($tokens[$sepIndex] === ',') {
                    $cursor = $sepIndex + 1;

                    continue;
                }
                break;
            }
        }

        return $aliases;
    }

    /**
     * Prism Facade を表す識別子か判定する (`Prism` 単体 or `Prism\Prism\Facades\Prism`、case-insensitive)。
     */
    private static function isPrismFacadeIdentifier(string $identifier): bool
    {
        $normalized = strtolower(ltrim($identifier, '\\'));

        return $normalized === 'prism' || $normalized === 'prism\\prism\\facades\\prism';
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextNonWhitespace(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
