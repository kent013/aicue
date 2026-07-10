<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\TextResponseFake as PromptTextResponseFake;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\Support\Prompts\MinimalLlmCallPrompt;
use Tests\Support\StrayLlmCallGuard;

/**
 * StrayLlmCallGuard の self-test。
 *
 * 各テスト末尾で `StrayLlmCallGuard::drainForAssertion()` を呼んで accumulator を空にしておかないと、
 * tests/Pest.php の global afterEach (`flushAndFailIfStray()`) が test 自身を fail させてしまう。
 * drain することで「stray call が記録された」事実を assert したうえで accumulator を空にする。
 */
beforeEach(function (): void {
    // executeSync は fake 中も PromptExecutionCompleted を発火し、listener → writer が
    // FX 解決 (HTTP) を試みるため stray request を防ぐ
    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
});

test('case A: fake なしで Prompt サブクラスを呼ぶと guard が RuntimeException + accumulator 記録', function (): void {
    $prompt = new MinimalLlmCallPrompt;

    $threw = false;
    try {
        $prompt->executeSync();
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call');
    }

    expect($threw)->toBeTrue('expected RuntimeException from guard');

    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['provider'])->toBe('openai');
});

test('case B: Prism::fake([...]) を install すれば guard は無効化される', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('hello')->withUsage(new Usage(10, 5)),
    ]);

    $result = (new MinimalLlmCallPrompt)->executeSync();

    expect($result)->toBe('hello');
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});

test('case C: Prompt::fake([...]) を install すれば guard は無効化される (Prompt 層 short-circuit)', function (): void {
    Prompt::fake([
        PromptTextResponseFake::make()->withText('prompt-fake-ok'),
    ]);

    $result = (new MinimalLlmCallPrompt)->executeSync();

    expect($result)->toBe('prompt-fake-ok');
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});

test('case D: Prism::text() 直叩き経由でも guard が効く', function (): void {
    $threw = false;
    try {
        Prism::text()->using('openai', 'gpt-4o-mini');
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call');
    }

    expect($threw)->toBeTrue();
    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1);
});

test('case E: try/catch で guard 例外を握り潰しても accumulator に残る (= afterEach で必ず fail する)', function (): void {
    // Service 層の `try { executeSync() } catch (Throwable) { fallback }` を再現
    try {
        (new MinimalLlmCallPrompt)->executeSync();
    } catch (Throwable) {
        // swallow (fallback の挙動を模す)
    }

    // 握り潰しても accumulator に記録されている = global afterEach の flushAndFailIfStray() が
    // ここで test 自身を fail させる契機。本 test では drain して afterEach を素通りさせ、
    // 「accumulator に 1 件記録された」事実だけを assert する。
    $drained = StrayLlmCallGuard::drainForAssertion();
    expect($drained)->toHaveCount(1, 'guard が握り潰されても accumulator は記録する');
});

test('case F: flushAndFailIfStray() は accumulator 非空のとき RuntimeException を throw + finally で clear する', function (): void {
    // 握り潰し
    try {
        (new MinimalLlmCallPrompt)->executeSync();
    } catch (Throwable) {
        // swallow
    }

    $threw = false;
    try {
        StrayLlmCallGuard::flushAndFailIfStray();
    } catch (RuntimeException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('Stray LLM call detected');
    }
    expect($threw)->toBeTrue('flushAndFailIfStray must throw when accumulator is non-empty');

    // finally で accumulator が clear されていることを確認
    expect(StrayLlmCallGuard::drainForAssertion())->toBe([]);
});
