<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;
use Tests\Support\PromptYaml;

/*
 * `Tests\Support\PromptWaitBudget` の検出力を**両方向**で固定する (AGENTS.md 共通規約 (c))。
 *
 * ★本自己テストが本読み取り器の存在理由である。待ち予算の判定を各 gate へインラインで
 *   書くと「0 以下を見ていない実装」が混ざっても誰も気付けない
 *   (実際に AnalysisBudget::clientTimeoutSecondsFromYaml() が `timeout: 0` を通していた)。
 *
 * 見本ファイル: tests/Architecture/fixtures/prompt-wait-budget/
 */

/** 見本ディレクトリの絶対パス。 */
function promptWaitBudgetFixtureDir(): string
{
    return base_path('tests/Architecture/fixtures/prompt-wait-budget');
}

test('待ち予算の 9 類型の違反がラベルの集合として全部上がる', function (): void {
    $bad = [
        'missing-client-options.yaml', 'client-options-not-array.yaml', 'missing-timeout.yaml',
        'zero.yaml', 'negative.yaml', 'numeric-string.yaml', 'float.yaml', 'bool.yaml', 'null.yaml',
    ];

    // ★件数ではなく**ラベルの集合**で照合する (1 件取りこぼして別の 1 件を二重報告しても
    //   件数だけは一致してしまう = 偽の緑)。
    $labels = [];
    foreach ($bad as $name) {
        foreach (PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/'.$name, $name) as $violation) {
            $label = strstr($violation, ': ', true);
            expect($label)->not->toBeFalse("違反メッセージにラベルがありません: {$violation}");
            $labels[] = (string) $label;
        }
    }

    sort($labels);
    $expected = $bad;
    sort($expected);
    expect($labels)->toBe($expected);
});

test('正例 (正の整数を宣言した見本) を誤検出しない', function (): void {
    expect(PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml'))
        ->toBe([]);
});

test('解決できない形は 3 種それぞれが別の分類で違反になる (fail-closed)', function (): void {
    // ★1 件だけ確かめて「解決不能形は落ちる」と主張しない。分岐は 3 つある。
    // ★**分類まで固定する**。ファイル不在が再び parse 失敗へ統合されても
    //   「違反が空でない」だけの照合では緑のままになる。
    //   pin するのはラベルの**安定部分**だけで、vendor の例外本文は pin しない。
    $unresolvable = [
        'ファイル不在' => [promptWaitBudgetFixtureDir().'/does-not-exist.yaml', 'prompt YAML が無い'],
        'parse 不能' => [promptWaitBudgetFixtureDir().'/broken.yaml', 'parse 失敗'],
        '最上位が map でない' => [
            promptWaitBudgetFixtureDir().'/list-top-level.yaml',
            'top-level が連想配列(map)でない',
        ],
    ];

    foreach ($unresolvable as $kind => [$path, $expectedFragment]) {
        $violations = PromptWaitBudget::violations($path, basename($path));
        expect($violations)->not->toBe([], "{$kind} が違反として上がっていません");

        $joined = implode(PHP_EOL, $violations);
        expect($joined)->toContain(basename($path));
        // ★`toContain()` は needle の可変長引数なので**メッセージを渡せない**
        //   (第 2 引数は別の needle として照合される)。分類の pin は述語 + メッセージで書く。
        expect(str_contains($joined, $expectedFragment))
            ->toBeTrue("{$kind} の分類が変わっています ({$expectedFragment} を含まない): {$joined}");
    }
});

test('共有ヘルパは解決不能形で必ず理由を積む (段 2 の fail-closed が依存する前提)', function (): void {
    // ★読み取り器の段 2 は `PromptYaml::parseOrFail()` が積んだ理由をそのまま違反にする。
    //   「null を返すのに理由が空」だと violations() だけが空 (= 適合) を返し、
    //   requirePositive() との間に**非対称**が生まれる (violations 側が fail-open)。
    //   到達不能な guard を読み取り器へ積む代わりに、**依存している前提そのもの**を固定する。
    foreach (['broken.yaml', 'list-top-level.yaml'] as $name) {
        /** @var list<string> $violations */
        $violations = [];
        $parsed = PromptYaml::parseOrFail(promptWaitBudgetFixtureDir().'/'.$name, $violations);

        expect($parsed)->toBeNull("{$name} は解決不能形として null を返すこと");
        expect($violations)->not->toBe([], "{$name}: 共有ヘルパが理由を積まずに null を返した");
    }
});

test('requirePositive は違反があれば例外にする (違反を無視しない)', function (): void {
    expect(fn (): int => PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/zero.yaml', 'zero.yaml',
    ))->toThrow(RuntimeException::class);
});

test('requirePositive は正常な見本から正の整数を返す', function (): void {
    expect(PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml',
    ))->toBe(300);
});
