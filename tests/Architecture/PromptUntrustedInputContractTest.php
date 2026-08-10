<?php

declare(strict_types=1);

use App\DataTransferObjects\LlmCallContextData;
use App\Models\VideoManual;
use App\Prompts\ExampleSummaryPrompt;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
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
 *  3. coverage(帰属): factory が組み立てた Prompt の metadata_context に
 *     llm_call_logs の帰属キー (organization_id / subject_type / subject_id) が入ること。
 *     欠けると llm_call_logs が metadata_missing になり、組織別・対象別の費用が出せない。
 *     帰属の対象を持たない prompt (見本など) は期待キーを空配列で登録して exempt を明示する。
 *
 * ★ この 3 層目が固定できるのは **組み立て済み Prompt の内部**までである。
 *   「metadata_context がイベント → listener → llm_call_logs へ流れること」は
 *   テストレーンでは検証できない (Prompt::$fake は executePrism() の先頭で短絡して
 *   PromptExecutionCompleted を発火せず、PromptFake::record() は metadata を記録しない)。
 *   その end-to-end 確認は bug-hunt レーンの `dev:pipeline-smoke` の llm-evidence 段が担う。
 *
 * 検査対象クラスは dataset 化しており、prompt 追加時は inventory (= dataset の源) に
 * 1 エントリ足すだけで両層の検査に載る。
 */

/**
 * 検査用の帰属 context。DB へ書かない (makeOne + 親キーの明示指定で親 factory を解決させない)。
 * Architecture lane は DB を張らないため、ここで DB に触れてはならない。
 */
function promptAttributionContext(): LlmCallContextData
{
    $manual = VideoManual::factory()->makeOne(['id' => 42, 'project_id' => 1, 'created_by' => 1]);

    return LlmCallContextData::for(7, $manual, 3);
}

/**
 * prompt factory FQCN => [untrusted template 変数名の list, 期待する帰属キーの list, 組み立て closure]。
 * end-user 入力なしの prompt は変数 list を空配列で登録する (exempt を明示)。
 * 帰属の対象を持たない prompt は帰属キー list を空配列で登録する (exempt を明示)。
 *
 * @return array<class-string, array{list<string>, list<string>, Closure(): Prompt}>
 */
function promptUntrustedInputInventory(): array
{
    $context = promptAttributionContext();

    return [
        // 見本 prompt。呼び出し元が無く帰属の対象も無いので帰属は exempt (空配列で明示)
        ExampleSummaryPrompt::class => [
            ['text'],
            [],
            fn (): Prompt => ExampleSummaryPrompt::make('untrusted end-user text'),
        ],
        // AI 解析 3 段 (SOP 由来の untrusted テキスト/JSON は全段 UserInput 経由)
        SopExtractPrompt::class => [
            ['text'],
            ['organization_id', 'subject_type', 'subject_id'],
            fn (): Prompt => SopExtractPrompt::make('untrusted sop text', $context),
        ],
        WorkDecompositionPrompt::class => [
            ['extracted'],
            ['organization_id', 'subject_type', 'subject_id'],
            fn (): Prompt => WorkDecompositionPrompt::make('{"sections":[]}', $context),
        ],
        ScenarioGenerationPrompt::class => [
            ['decomposition'],
            ['organization_id', 'subject_type', 'subject_id'],
            fn (): Prompt => ScenarioGenerationPrompt::make('{"steps":[]}', $context),
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
    foreach (promptUntrustedInputInventory() as $class => [$untrustedVars, $attributionKeys, $factory]) {
        yield $class => [$class, $untrustedVars, $attributionKeys, $factory];
    }
});

// ── 1. coverage(型) ──────────────────────────────────────────────────
test('untrusted template 変数は UserInput 型で渡される', function (string $class, array $untrustedVars, array $_attributionKeys, Closure $factory): void {
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

// ── 3. coverage(帰属) ────────────────────────────────────────────────
test('帰属が必要な prompt は metadata_context に organization / subject を持つ', function (
    string $class,
    array $_untrustedVars,
    array $attributionKeys,
    Closure $factory,
): void {
    $prompt = $factory();

    // Prompt::withMetadata() が array_merge するだけの内部バッグを reflection で取り出す
    // (パッケージは中身を解釈せず PromptExecution* イベントへそのまま流す)。
    $property = new ReflectionProperty(Prompt::class, 'metadata_context');
    /** @var array<string, mixed> $metadata */
    $metadata = $property->getValue($prompt);

    if ($attributionKeys === []) {
        expect($metadata)->toBe([], "{$class}: 帰属 exempt として登録されていますが metadata が付いています");

        return;
    }

    foreach ($attributionKeys as $key) {
        // toHaveKey() の第 2 引数は「期待する値」なので、説明付きで落とすには assertArrayHasKey を使う
        $this->assertArrayHasKey(
            $key,
            $metadata,
            "{$class}: withMetadata() で '{$key}' を渡してください"
            .' (欠けると llm_call_logs が metadata_missing になり組織・対象別の費用が出せません)',
        );
    }
})->with('untrusted_prompt_inputs');
