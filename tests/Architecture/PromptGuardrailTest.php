<?php

declare(strict_types=1);

/*
 * LLM 呼び出しの guardrail (07 ガイド §6):
 *
 * 1. Prism の直呼び禁止: LLM 呼び出しは kent013/laravel-prism-prompt の
 *    Prompt 経由のみ (観測 = llm_call_logs と prompt-injection 防御を迂回させない)。
 *    検出は token_get_all ベースの scanner で行い、コメント / 文字列リテラル中の
 *    "Prism::text(" や同名別クラス (Foo\Bar\Prism) を誤検出しない
 * 2. Prompt::load の呼び出しは app/Prompts/ のみ (prompt 定義の窓口を 1 箇所に集約)
 */

/**
 * app/ 配下で Prism Facade の LLM 系メソッド (`Prism::text()`, `Prism::structured()`,
 * `Prism::stream()`, `Prism::embeddings()`, `Prism::image()`, `Prism::audio()`) を
 * 直接呼び出すコードを token ベースで検出する scanner。
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
    private const TARGET_METHODS = ['text', 'structured', 'stream', 'embeddings', 'image', 'audio'];

    /**
     * @var list<string> app/ からの相対パスで指定。テンプレートは allowlist 不要のため空。
     *                   将来正当な理由で直叩きが必要になった場合のみ追加し、理由を明記すること。
     */
    private const ALLOWED_FILES = [];

    /**
     * @return list<string> 違反ファイル (app/ 相対パス)
     */
    public static function findViolations(): array
    {
        $appDir = realpath(__DIR__.'/../../app');
        if (! is_string($appDir)) {
            throw new RuntimeException('app/ ディレクトリを解決できません');
        }

        $allowedAbsolutePaths = array_map(
            fn (string $relative): string => $appDir.'/'.$relative,
            self::ALLOWED_FILES,
        );

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (in_array($path, $allowedAbsolutePaths, true)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsPrismDirectCall($contents)) {
                $violations[] = substr($path, strlen($appDir) + 1);
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

/**
 * @return list<string>
 */
function phpFilesUnder(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

test('app/ で Prism Facade の LLM 系メソッドを直接呼んでいない (Prompt 経由のみ)', function (): void {
    $violations = PrismDirectDispatchScanner::findViolations();

    expect($violations)->toBe([],
        'LLM 呼び出しは Kent013\\PrismPrompt\\Prompt サブクラス経由で行ってください。'
        .' app/ で Prism::text()/structured() 等を直叩きすると、llm_call_logs 記録と'
        .' prompt-injection 防御 (UserInput / DefensiveInstructions) を素通りします。'
        .PHP_EOL.'違反ファイル: '.implode(', ', $violations));
});

test('scanner の自己検証 (app dir が解決できる)', function (): void {
    // degenerate failure (走査対象が空のまま黙って PASS) を防ぐ自己検証。
    $appDir = realpath(__DIR__.'/../../app');
    expect($appDir)->toBeString()
        ->and(is_dir((string) $appDir))->toBeTrue();
});

test('scanner はコメント / 文字列リテラル中の Prism::text を誤検出しない', function (): void {
    $source = <<<'PHP'
<?php
// 例: Prism::text() を直接呼ぶのは禁止 (このコメントは違反扱いされない)
class Example
{
    public function note(): string
    {
        return 'Prism::text() should not be called';
    }
}
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($source))->toBeFalse();
});

test('scanner は同名別 namespace のクラスを誤検出しない', function (): void {
    $source = <<<'PHP'
<?php
namespace App\Test;
class A
{
    public function go(): mixed
    {
        return \Foo\Bar\Prism::text();
    }
}
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($source))->toBeFalse();
});

test('scanner は case-insensitive なメソッド名を検出する', function (): void {
    $upper = <<<'PHP'
<?php
use Prism\Prism\Facades\Prism;
class A { public function go() { return Prism::TEXT(); } }
PHP;
    $title = <<<'PHP'
<?php
use Prism\Prism\Facades\Prism;
class B { public function go() { return Prism::Text(); } }
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($upper))->toBeTrue();
    expect(PrismDirectDispatchScanner::containsPrismDirectCall($title))->toBeTrue();
});

test('scanner は alias import を検出する (case-insensitive)', function (): void {
    $alias = <<<'PHP'
<?php
use Prism\Prism\Facades\Prism as PrismFacade;
class A { public function go() { return PrismFacade::text(); } }
PHP;
    $aliasLower = <<<'PHP'
<?php
use Prism\Prism\Facades\Prism as PrismFacade;
class A { public function go() { return prismfacade::text(); } }
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($alias))->toBeTrue();
    expect(PrismDirectDispatchScanner::containsPrismDirectCall($aliasLower))->toBeTrue();
});

test('scanner はカンマ区切り use と完全修飾名を検出する', function (): void {
    $comma = <<<'PHP'
<?php
use App\Models\User, Prism\Prism\Facades\Prism;
class A { public function go() { return Prism::text(); } }
PHP;
    $fqn = <<<'PHP'
<?php
class B { public function go() { return \Prism\Prism\Facades\Prism::structured(); } }
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($comma))->toBeTrue();
    expect(PrismDirectDispatchScanner::containsPrismDirectCall($fqn))->toBeTrue();
});

test('Prompt::load の呼び出し箇所は app/Prompts/ に限る', function (): void {
    $violations = [];

    foreach (phpFilesUnder(app_path()) as $file) {
        if (str_starts_with($file, app_path('Prompts'))) {
            continue;
        }
        $contents = (string) file_get_contents($file);
        if (preg_match('/(?:Prompt|TextPrompt|EmbeddingPrompt)::load\(/', $contents) === 1) {
            $violations[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($violations)->toBe([]);
});
