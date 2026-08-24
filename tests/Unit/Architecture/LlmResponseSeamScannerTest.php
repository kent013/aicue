<?php

declare(strict_types=1);

use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\LlmJson;
use Tests\Support\Llm\DecodePointPublicSurface;
use Tests\Support\Llm\Fixtures\LenientDecodePointProbe;
use Tests\Support\Llm\LlmResponseSeamResolution;
use Tests\Support\Llm\LlmResponseSeamScanner;
use Tests\Support\Llm\LlmSeamInventoryRules;
use Tests\Support\Prompts\PromptFactoryPopulation;

/*
 * 検出器の自己検査 (AGENTS.md §走査器・gate を新設するときに揃える 4 点の (1) と (2))。
 *
 * 見本ファイルは `tests/Architecture/fixtures/llm-seam/*.php.txt` に置く
 * (`.php` にすると他 gate の母集団 (strict_types 全数宣言・禁止する文) へ混ざるため)。
 * **正例と負例の両方向**を固定し、解決できない形が `Unresolved` として落ちることを確かめる。
 */

/** 見本ファイルの中身。 */
function llmSeamFixture(string $name): string
{
    $path = base_path("tests/Architecture/fixtures/llm-seam/{$name}.php.txt");
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("見本ファイルを読めません: {$path}");
    }

    return $source;
}

/** @return list<string> 目録の鍵に見立てた依頼文 factory */
function llmSeamFactories(): array
{
    return ['App\Prompts\SopExtractPrompt'];
}

test('正例: make(...)->executeSync() が受け取り関数の引数にある形は解決できる', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-resolved-receiver.php',
        llmSeamFixture('seam-resolved-receiver'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
    expect($findings[0]->factory)->toBe('App\Prompts\SopExtractPrompt');
    expect($findings[0]->enclosingCall)
        ->toBe('App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText');
});

test('正例 1b: 名前付き引数で渡す形も「直接の引数」と認める', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-named-argument.php',
        llmSeamFixture('seam-named-argument'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
    expect($findings[0]->enclosingCall)
        ->toBe('App\DataTransferObjects\Manual\Analysis\ExtractedSopData::fromLlmText');
});

test('負例 1: 応答を変数へ束縛する形は未解決になる', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-unresolved-variable.php',
        llmSeamFixture('seam-unresolved-variable'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
    expect($findings[0]->factory)->toBeNull();
    expect($findings[0]->enclosingCall)->toBeNull();
});

test('負例 2: 遅延静的束縛・括弧で包んだ形も未解決になる', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-unresolved-static.php',
        llmSeamFixture('seam-unresolved-static'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(2);
    foreach ($findings as $finding) {
        expect($finding->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
    }
});

test('負例 3: 目録外の型は ResolvedOther (未解決と混ぜない)', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-resolved-other.php',
        llmSeamFixture('seam-resolved-other'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedOther);
    expect($findings[0]->factory)->toBe('Fixture\LlmSeam\Unregistered');
});

test('負例 4: 受け取り関数でない関数の引数に渡す形は囲みの解決先が異なる', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-wrong-enclosing.php',
        llmSeamFixture('seam-wrong-enclosing'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
    expect($findings[0]->enclosingCall)->toBe('Fixture\LlmSeam\Sink::consume');
});

test('負例 4b: 応答を加工してから渡す形は「直接の引数」と認めない', function (): void {
    // `->executeSync().'suffix'` / `?: '{}'` / 配列に入れる形。受け手は解決できるが囲みは解決しない。
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-postprocessed.php',
        llmSeamFixture('seam-postprocessed'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(3);
    foreach ($findings as $finding) {
        expect($finding->resolution)->toBe(LlmResponseSeamResolution::ResolvedPromptFactory);
        expect($finding->enclosingCall)->toBeNull();
    }
});

test('負例 4c: 括弧の対応が取れない形は未解決として落とす', function (): void {
    $findings = LlmResponseSeamScanner::executeSyncSites(
        'fixtures/seam-unbalanced.php',
        llmSeamFixture('seam-unbalanced'),
        llmSeamFactories(),
    );

    expect($findings)->toHaveCount(1);
    expect($findings[0]->resolution)->toBe(LlmResponseSeamResolution::Unresolved);
});

test('負例 5b: 大文字小文字を変えた綴りと先頭 \\ の文字列 callable も検出する', function (): void {
    // PHP の関数名は大文字小文字を区別しないので、綴りを変えるだけで抜けられてはいけない。
    $violations = LlmResponseSeamScanner::decodeVocabularyViolations(
        'fixtures/vocabulary-case-variants.php',
        llmSeamFixture('vocabulary-case-variants'),
    );

    expect($violations)->toHaveCount(4);
});

test('負例 5: 復号語彙の回避経路をすべて検出する', function (): void {
    $violations = LlmResponseSeamScanner::decodeVocabularyViolations(
        'fixtures/vocabulary-violations.php',
        llmSeamFixture('vocabulary-violations'),
    );

    // 素の呼び出し / 完全修飾 / use function の別名 / 文字列リテラル経由 / 囲みの印
    expect($violations)->toHaveCount(5);
    expect(implode("\n", $violations))->toContain('関数呼び出しの json_decode');
    expect(implode("\n", $violations))->toContain('文字列リテラルの json_decode');
    expect(implode("\n", $violations))->toContain('囲みの印を含む文字列リテラル');
});

test('正例 5b: 接頭辞・打ち消し・接尾辞つきの語とメソッド呼び出しと名前空間つきの別名は誤検出しない', function (): void {
    expect(LlmResponseSeamScanner::decodeVocabularyViolations(
        'fixtures/vocabulary-clean.php',
        llmSeamFixture('vocabulary-clean'),
    ))->toBe([]);
});

test('正例 6: 生の応答が復号点へ直接 1 回だけ渡る形は違反にならない', function (): void {
    expect(LlmResponseSeamScanner::receiverFlowViolations(
        'fixtures/receiver-flow-clean.php',
        llmSeamFixture('receiver-flow-clean'),
        'Fixture\LlmSeam\ReceiverFlowClean',
        'fromLlmText',
        LlmJson::class,
        'decode',
    ))->toBe([]);
});

test('負例 6: 復号点を通さない / 別変数へ移す / 2 回使う形はいずれも違反になる', function (
    string $fixture,
    string $class,
): void {
    expect(LlmResponseSeamScanner::receiverFlowViolations(
        "fixtures/{$fixture}.php",
        llmSeamFixture($fixture),
        $class,
        'fromLlmText',
        LlmJson::class,
        'decode',
    ))->not->toBe([]);
})->with([
    'decode を通さない' => ['receiver-flow-missing-decode', 'Fixture\LlmSeam\ReceiverFlowMissingDecode'],
    '別変数へ移す' => ['receiver-flow-rebound', 'Fixture\LlmSeam\ReceiverFlowRebound'],
    '2 回使う' => ['receiver-flow-reused', 'Fixture\LlmSeam\ReceiverFlowReused'],
]);

test('負例 7: 公開面を 1 つ増やした見本は本番と同じ判定関数で赤くなる', function (): void {
    expect(DecodePointPublicSurface::violations(LlmJson::class, LlmOutputInvalidException::class))->toBe([]);
    expect(DecodePointPublicSurface::violations(LenientDecodePointProbe::class, LlmOutputInvalidException::class))
        ->not->toBe([]);
});

test('母集団: 依頼文 factory の走査根は実在し、母集団は空でない', function (): void {
    expect(is_dir(PromptFactoryPopulation::root()))->toBeTrue();
    expect(PromptFactoryPopulation::classes())->not->toBeEmpty();
});

test('母集団: 存在しない走査根は fail-fast で落ちる (無言で空にしない)', function (): void {
    expect(fn (): string => PromptFactoryPopulation::resolve('app/PromptsThatDoNotExist'))
        ->toThrow(RuntimeException::class);
});

// ---- 目録判定 (検査 2 が使う純関数) の両方向 ----

test('目録判定: 現行どおりの入力は違反にならない (正例)', function (): void {
    expect(LlmSeamInventoryRules::otherReceiverViolations([], []))->toBe([]);
    // ★**非空の目録が観測値と完全一致する**分岐も通す (重複した観測値も許容する)。
    //   ここを空目録どうしだけで済ませると、一致を stale / 未登録と誤判定する壊れ方を検出できない
    expect(LlmSeamInventoryRules::otherReceiverViolations(
        ['Foo\\Bar', 'Foo\\Bar'],
        ['Foo\\Bar' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
    ))->toBe([]);
    expect(LlmSeamInventoryRules::exemptionViolations(
        ['app/Support/Llm/GuardedPrompt.php' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
        ['app/Support/Llm/GuardedPrompt.php', 'app/Prompts/SopExtractPrompt.php'],
        ['app/Support/Llm/GuardedPrompt.php' => 1],
    ))->toBe([]);
});

test('目録判定: 未登録の観測値 / stale 登録 / 短すぎる根拠はいずれも違反になる', function (): void {
    // 未登録の観測値
    expect(LlmSeamInventoryRules::otherReceiverViolations(['Foo\Bar'], []))->not->toBe([]);
    // stale 登録 (登録されているが観測されない)
    expect(LlmSeamInventoryRules::otherReceiverViolations(
        [],
        ['Foo\Bar' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
    ))->not->toBe([]);
    // 30 文字未満の根拠
    expect(LlmSeamInventoryRules::otherReceiverViolations(['Foo\Bar'], ['Foo\Bar' => '短い理由']))->not->toBe([]);
    // 末尾一致では通さない (完全修飾名の完全一致。共通規約 (a))
    expect(LlmSeamInventoryRules::otherReceiverViolations(
        ['Foo\BarBaz'],
        ['Foo\Baz' => str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH)],
    ))->not->toBe([]);
});

test('目録判定: 免除の実在 / 根拠 / 前提のいずれが欠けても違反になる', function (): void {
    $reason = str_repeat('あ', LlmSeamInventoryRules::MINIMUM_REASON_LENGTH);

    // 実在しないパス
    expect(LlmSeamInventoryRules::exemptionViolations(['app/Gone.php' => $reason], ['app/Here.php'], []))
        ->not->toBe([]);
    // 30 文字未満の根拠
    expect(LlmSeamInventoryRules::exemptionViolations(['app/Here.php' => '短い'], ['app/Here.php'], ['app/Here.php' => 1]))
        ->not->toBe([]);
    // 前提 (executeSync() を持つ) が失われた免除
    expect(LlmSeamInventoryRules::exemptionViolations(['app/Here.php' => $reason], ['app/Here.php'], ['app/Here.php' => 0]))
        ->not->toBe([]);
});
