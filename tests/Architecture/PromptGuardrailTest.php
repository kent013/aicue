<?php

declare(strict_types=1);

/*
 * LLM 呼び出しの操作単位ガードレール (裁定 AG-028 の「操作単位のガードレール」。07 ガイド §6):
 *
 * 1. Prism の直呼び禁止: LLM 呼び出しは kent013/laravel-prism-prompt の
 *    Prompt 経由のみ (観測 = llm_call_logs と prompt-injection 防御を迂回させない)。
 *    検出は token_get_all ベースの scanner で行い、コメント / 文字列リテラル中の
 *    "Prism::text(" や同名別クラス (Foo\Bar\Prism) を誤検出しない
 * 2. Prism の入口型 (Prism ファサード実体 / PrismManager / Text\PendingRequest) への参照が
 *    0 件であること。例外クラス (Prism\Prism\Exceptions\*) は AnalysisPipeline が
 *    正当に参照するため母集団に入れない (偽陽性を作らない)
 * 3. vendor prompt の読み込み (`Prompt::load` 等) は**窓口 1 ファイル**に限る
 *    (窓口 = app/Support/Llm/PromptDefense.php。無害化・タグ境界化・合言葉の合流を
 *     必ず通す。呼び出し site 全体の 1 本道は PromptDefenseWindowGateTest が担う)
 *
 * 走査根はいずれも **app/ + routes/ + database/ + config/ + bootstrap/ の 5 本**である。
 */

use Tests\Support\Llm\PromptWindowRule;
use Tests\Support\Llm\PromptWindowScanner;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\Prompts\PrismDirectDispatchScanner;
use Tests\Support\ReferenceKind;

/** vendor prompt の読み込みを許す唯一のファイル (窓口)。 */
const PROMPT_WINDOW_FILE = 'app/Support/Llm/PromptDefense.php';

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

test('5 走査根で Prism Facade の LLM 系メソッドを直接呼んでいない (Prompt 経由のみ)', function (): void {
    $violations = PrismDirectDispatchScanner::findViolations();

    expect($violations)->toBe([],
        'LLM 呼び出しは Kent013\\PrismPrompt\\Prompt サブクラス経由で行ってください。'
        .' Prism::text()/structured() 等を直叩きすると、llm_call_logs 記録と'
        .' prompt-injection 防御 (窓口 PromptDefense) を素通りします。'
        .PHP_EOL.'違反ファイル: '.implode(', ', $violations));
});

test('scanner の自己検証 (5 走査根が解決でき、いずれも空でない)', function (): void {
    // degenerate failure (走査対象が空のまま黙って PASS) を防ぐ自己検証。
    $roots = PrismDirectDispatchScanner::roots();
    expect(array_keys($roots))->toBe(['app', 'routes', 'database', 'config', 'bootstrap']);

    foreach ($roots as $relative => $absolute) {
        expect(is_dir($absolute))->toBeTrue("走査根 {$relative} が存在しません");
        expect(phpFilesUnder($absolute))->not->toBeEmpty("走査根 {$relative} に PHP ファイルがありません");
    }
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

test('scanner は moderation も検出する (現行 vendor に無くても deny 側に置く)', function (): void {
    $source = <<<'PHP'
<?php
use Prism\Prism\Facades\Prism;
class A { public function go() { return Prism::moderation(); } }
PHP;

    expect(PrismDirectDispatchScanner::containsPrismDirectCall($source))->toBeTrue();
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

test('Prism の入口型への参照が 0 件 (例外クラスは母集団に入れない)', function (): void {
    $entryTypes = [
        'Prism\\Prism\\Prism',
        'Prism\\Prism\\PrismManager',
        'Prism\\Prism\\Text\\PendingRequest',
    ];

    $violations = [];
    foreach (PrismDirectDispatchScanner::roots() as $relativeRoot => $absoluteRoot) {
        foreach (PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot) as $relative => $source) {
            foreach (PhpReferenceScanner::references($relative, $source)->sites as $site) {
                if ($site->kind === ReferenceKind::MethodCall || $site->kind === ReferenceKind::StaticCall) {
                    continue; // 呼び出しの名前は型参照ではない
                }
                if (in_array($site->name, $entryTypes, true)) {
                    $violations[] = "{$relative}:{$site->line} {$site->name}";
                }
            }
        }
    }

    expect($violations)->toBe([],
        'Prism の入口型を直接掴むと、Prompt 層の観測と防御を迂回する経路が作れます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('vendor prompt の読み込みは窓口 1 ファイルに限る', function (): void {
    // ★判定は PromptWindowScanner (= PhpReferenceScanner の正規化トークン列) に委ねる。
    //   素の正規表現でソースを見ると、窓口の仕組みを説明した docblock 中の
    //   `Prompt::load()` に反応して常時赤になる (実測で踏んだ)。
    $violations = [];
    foreach (PrismDirectDispatchScanner::roots() as $relativeRoot => $absoluteRoot) {
        foreach (PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot) as $relative => $source) {
            foreach (PromptWindowScanner::scan($relative, $source) as $site) {
                if ($site->rule === PromptWindowRule::VendorPromptLoad && $relative !== PROMPT_WINDOW_FILE) {
                    $violations[] = "{$relative}:{$site->line}";
                }
            }
        }
    }

    expect($violations)->toBe([],
        'vendor prompt の読み込みは窓口 ('.PROMPT_WINDOW_FILE.') の中だけで行ってください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
