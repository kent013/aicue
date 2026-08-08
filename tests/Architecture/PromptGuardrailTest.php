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

use Tests\Support\Prompts\PrismDirectDispatchScanner;

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
