<?php

declare(strict_types=1);

use App\Prompts\ExampleSummaryPrompt;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Values\UserInput;

/*
 * LLM プロンプトの untrusted 入力契約 invariant (二層防御)。
 *
 *  1. coverage(型): app/Prompts/ の各 factory が組み立てる template 変数のうち、
 *     end-user 由来の自由テキストは **UserInput 型** で渡すこと (生 string 不可)。
 *     UserInput はタグ区切り + delimiter escape で prompt-injection 境界を明示する。
 *  2. deny-by-default: app/Prompts/ 配下の全 factory を inventory に分類する (未分類 fail)。
 *     新しい prompt を追加したら untrusted 変数名を inventory へ登録するか、
 *     end-user 入力なしなら空配列で登録する。
 *
 * 検査対象クラスは dataset 化しており、prompt 追加時は inventory (= dataset の源) に
 * 1 エントリ足すだけで両層の検査に載る。
 */

/**
 * prompt factory FQCN => [untrusted template 変数名の list, 組み立て closure]。
 * end-user 入力なしの prompt は変数 list を空配列で登録する (exempt を明示)。
 *
 * @return array<class-string, array{list<string>, Closure(): Prompt}>
 */
function promptUntrustedInputInventory(): array
{
    return [
        ExampleSummaryPrompt::class => [
            ['text'],
            fn (): Prompt => ExampleSummaryPrompt::make('untrusted end-user text'),
        ],
    ];
}

/** @return list<class-string> app/Prompts/ 配下の具象クラス (deny-by-default 走査)。 */
function discoverPromptFactoryClasses(): array
{
    $base = realpath(__DIR__.'/../../app/Prompts');
    if (! is_string($base)) {
        return [];
    }

    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($base) + 1, -4);
        $class = 'App\\Prompts\\'.str_replace('/', '\\', $relative);
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            continue;
        }
        $classes[] = $class;
    }
    sort($classes);

    return $classes;
}

dataset('untrusted_prompt_inputs', function (): iterable {
    foreach (promptUntrustedInputInventory() as $class => [$untrustedVars, $factory]) {
        yield $class => [$class, $untrustedVars, $factory];
    }
});

// ── 1. coverage(型) ──────────────────────────────────────────────────
test('untrusted template 変数は UserInput 型で渡される', function (string $class, array $untrustedVars, Closure $factory): void {
    $prompt = $factory();
    expect($prompt)->toBeInstanceOf(Prompt::class);

    // Prompt::load で渡された template 変数を reflection で取り出す
    $property = new ReflectionProperty(Prompt::class, 'templateVariables');
    /** @var array<string, mixed> $variables */
    $variables = $property->getValue($prompt);

    foreach ($untrustedVars as $name) {
        expect($variables)->toHaveKey($name);
        expect($variables[$name])->toBeInstanceOf(
            UserInput::class,
            "{$class}: 変数 '{$name}' は UserInput 型で渡してください"
            .' (生 string はタグ区切りされず prompt-injection の抜け道になる)',
        );
    }
})->with('untrusted_prompt_inputs');

// ── 2. deny-by-default ───────────────────────────────────────────────
test('app/Prompts/ の全 factory が inventory に分類されている (deny-by-default)', function (): void {
    $discovered = discoverPromptFactoryClasses();
    expect($discovered)->not->toBeEmpty();

    $unclassified = array_values(array_diff($discovered, array_keys(promptUntrustedInputInventory())));
    expect($unclassified)->toBe([],
        '未分類の prompt factory があります。untrusted 変数名を inventory に登録するか、'
        .'end-user 入力なしなら空配列で登録してください。'.PHP_EOL.implode(PHP_EOL, $unclassified));
});

test('inventory の key は現存 prompt factory (逆方向 stale 検出)', function (): void {
    $discovered = discoverPromptFactoryClasses();
    $stale = array_values(array_diff(array_keys(promptUntrustedInputInventory()), $discovered));
    expect($stale)->toBe([], 'inventory に現存しない prompt factory: '.implode(', ', $stale));
});
