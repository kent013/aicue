<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;
use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 * 宣言を落とすと、docs/architecture.md §AI 解析ジョブの運用契約 が挙げる 3 前提が
 * 成立する現行実装では実効値が config/prism.php の request_timeout (30 秒) へ縮み、
 * 360 秒前提の時間 budget 連鎖 (AnalysisTimeBudgetInvariantTest) の前提が黙って崩れて
 * provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる。
 * ★この gate は**実効値そのものは見ない** (下の「保証しないもの」1)。
 *   実効値の話は前提つきの運用契約であり、本 gate が固定するのは**宣言の側**だけである。
 *
 * 【走査対象】`PromptYaml::paths()` = resources/prompts 配下の *.yaml / *.yml を再帰全数
 *   (大文字拡張子も拾う)。0 件は失敗にする。
 * 【判定の正本】`Tests\Support\PromptWaitBudget` **1 箇所**である。
 *   待ち予算を読む検査 (本 gate / AnalysisTimeBudgetInvariantTest /
 *   AnalysisTokenBudgetInvariantTest) はすべて同じ読み取り器を参照する
 *   (同じ規則を 2 実装持つと、片方だけが緩んでも気付けない)。
 *   検出力の裏取り (負例 9 類型 + 正例 + 解決不能形 3 種) は
 *   tests/Unit/Architecture/PromptWaitBudgetTest.php が持つ。
 *
 * 【この gate が保証しないもの (誇張しない)】
 *  1. **宣言値が実効値であること**は見ない (読み取り器の docblock が正本)。
 *  2. **走査の再帰そのものの検出力は裏取りしていない**。`PromptYaml::paths()` は
 *     探索根を引数で受けず `base_path('resources/prompts')` を直接見るため、見本
 *     ディレクトリを食わせられない。テスト中に resources/prompts へ一時ファイルを作る形は
 *     同じ分母を見る他の 3 gate (PromptYamlContractTest / DefensiveInstructionsPresenceTest /
 *     PromptDefenseWindowGateTest) を汚すので採らない。**実データにも
 *     サブディレクトリが無い**ので、再帰が壊れても本 gate は気付かない。
 *  3. 到達証明は**「現在の列挙結果に既知 5 本が含まれること」**だけである。
 *     全数性そのものは `PromptYaml::paths()` の実装契約に依存する。
 *     既知 5 本は**いずれも resources/prompts 直下**にあるので、
 *     `paths()` が非再帰へ退行しても 5 本は取れて**緑のまま**になる
 *     (= 再帰性の退行は検出しない。上の 2 と同じ限界である)。
 *     新規 prompt が分母に入ることも本証明は保証しない (再帰全数走査の既定拒否が受け持つ)。
 */

/** 走査根 (resources/prompts) からの相対パス。違反ラベルに使う。 */
function promptWaitBudgetLabel(string $absolutePath): string
{
    $prefix = rtrim(base_path('resources/prompts'), '/').'/';
    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);

    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
}

/**
 * 現在の列挙結果に必ず含まれる既知の prompt (到達証明)。
 *
 * ★件数の pin ではなく**包含**である。新規 prompt の追加でこの一覧を直す必要は無く、
 *   既知の 1 本が消えた・改名された・走査根が別物になったときだけ赤くなる。
 *   意図した削除なら同じ PR でこの一覧を直す。
 * ★**再帰性の退行は検出しない** (5 本とも resources/prompts 直下にあるため)。
 */
const PROMPT_WAIT_BUDGET_REQUIRED_LABELS = [
    'example-summary.yaml',
    'scenario-generation.yaml',
    'sop-extract-media.yaml',
    'sop-extract.yaml',
    'work-decomposition.yaml',
];

test('走査の列挙結果に既知の prompt YAML が含まれる (分母の到達証明)', function (): void {
    $labels = array_map(promptWaitBudgetLabel(...), PromptYaml::paths());

    expect($labels)->not->toBeEmpty();

    $missing = array_values(array_diff(PROMPT_WAIT_BUDGET_REQUIRED_LABELS, $labels));

    expect($missing)->toBe([],
        '走査の列挙結果に既知の prompt YAML が含まれていません'
        .' (走査根の改名・移動、または既知ファイルの削除・改名)。'
        .PHP_EOL.'不足: '.implode(', ', $missing));
});

test('全 prompt YAML が client_options.timeout (>0 の int) を宣言する', function (): void {
    $files = PromptYaml::paths();

    // ★到達証明の test と重複するが**意図的に残す**。各不変条件の test を単独で
    //   フィルタ実行したときにも「分母 0 件で緑」にならないようにするため。
    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        array_push($violations, ...PromptWaitBudget::violations($file, promptWaitBudgetLabel($file)));
    }

    expect($violations)->toBe([],
        'client_options.timeout invariant に違反があります (provider 無応答時に打ち切れない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
