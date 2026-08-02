<?php

declare(strict_types=1);

/*
 * Architecture invariant: bug-hunt SKILL が「finding は停止信号ではない」規約を保持すること。
 *
 * SoT = .claude/skills/app-bug-hunt/SKILL.md (AI-CUE の文言が正本。aigenba の文言は参照しない)。
 *
 * 固定する事故: finding (特に Critical/High) を 1 件拾った時点でそのエリアの検証を畳んでしまう
 * 打ち切り。SKILL の継続規定が「続行できるなら続行」程度の任意に薄まると、カバレッジ分母が
 * finding 件数で縮み、run の比較可能性が失われる。
 *
 * 併せて、走行の再現性に直結する以下も固定する:
 *   - 既定 (引数なし) の走行モード集合
 *   - UI/UX ヒューリスティクス H11-H14 の存在と H13 viewport sweep 手順
 *   - shard-report.md の逐次書き出し規約 (budget 超過で結果を全損しないため)
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

function bhSkillSource(): string
{
    $contents = file_get_contents(base_path('.claude/skills/app-bug-hunt/SKILL.md'));
    expect($contents)->toBeString('bug-hunt SKILL.md が読めない');
    /** @var string $contents */
    expect($contents)->not->toBe('', 'bug-hunt SKILL.md が空');

    return $contents;
}

/**
 * H13 の viewport sweep 手順が「H13 の文脈で」残っていることを検査するパターン。
 *
 * H13 見出しに係留し (文脈外の resize/寸法での誤通過を防ぐ)、mobile 375×667 / tablet 768×1024 /
 * resize の 3 要素が近傍に揃うことを要求する。さらに resize と mobile 寸法の双方向近接で
 * 「同一 sweep 手順であること」を担保する (寸法だけ別所に書かれた状態を通さない)。
 * CLI 名 (playwright-cli) は厳密一致させない (ラッパ改名で再 drift しないため)。
 */
function bhSkillH13SweepPattern(): string
{
    return '/H13(?=[\s\S]{0,1200}?768[x×]1024)'
        .'(?=[\s\S]{0,1200}?375[x×]667)'
        .'(?=[\s\S]{0,1200}?resize)'
        .'(?=[\s\S]{0,1400}?(?:resize[\s\S]{0,200}?375[x×]667|375[x×]667[\s\S]{0,200}?resize))/u';
}

/**
 * 「finding は停止信号ではない」系の継続規約の違反一覧 (純関数)。
 * 正の gate と負のコントロールが**同一の判定器**を使うようにするための分離。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function bhSkillContinuityViolations(string $contents): array
{
    /** @var array<string, string> $required key = 規約名, value = 検出パターン */
    $required = [
        'finding は停止信号ではない' => '/finding は停止信号ではない/u',
        'ストーリー完走まで検証を終えない' => '/完走するまで検証は終わらない/u',
        'blocker でも「詰みの先」を取りに行く' => '/詰みの先/u',
        '到達不能な分だけ skip (理由必須)' => '/到達不能な分だけ\s*skip/u',
        'finding 1 件で満足しない' => '/finding を 1 件で満足しない/u',
        'Critical/High のエリアは深掘り' => '/Critical\/High[\s\S]{0,80}?深掘り/u',
        'カバレッジ完了条件が finding と独立' => '/カバレッジ完了条件/u',
        'finding 件数で分母を縮めない' => '/finding 件数で分母を縮めない/u',
        'shard-report.md の逐次書き出し' => '/shard-report\.md は逐次書き出しする/u',
        '最後にまとめて書く方式の禁止' => '/最後にまとめて書く方式は禁止/u',
        'finding を見つけ次第の即追記' => '/finding を 1 件見つけるたびに即追記/u',
    ];

    $violations = [];
    foreach ($required as $label => $pattern) {
        if (preg_match($pattern, $contents) !== 1) {
            $violations[] = "SKILL.md から規約が失われている: {$label}";
        }
    }

    return $violations;
}

test('SKILL が「finding は停止信号ではない」系の継続規約を保持すること', function (): void {
    expect(bhSkillContinuityViolations(bhSkillSource()))->toBe([]);
});

test('SKILL の既定 (引数なし) が全走行モード集合であること', function (): void {
    $contents = bhSkillSource();

    $m = [];
    $matched = preg_match('/\*\*既定 \(引数なし\) = `([^`]+)`\*\*/u', $contents, $m);
    expect($matched)->toBe(1, '既定 (引数なし) の宣言行が SKILL.md に無い');
    /** @var array{0: string, 1: string} $m */
    $declared = $m[1];

    // 語順は将来変わりうるため集合として固定する (退行させたくないのはモードの欠落)。
    foreach (['--all', '--coverage', '--parallel', '--deviate', '--real-llm'] as $flag) {
        expect($declared)->toContain($flag);
    }

    // argument-hint (slash command の案内) も同じ集合を案内していること。
    $hint = [];
    expect(preg_match('/^argument-hint:\s*"([^"]*)"/mu', $contents, $hint))->toBe(1);
    /** @var array{0: string, 1: string} $hint */
    foreach (['--all', '--coverage', '--parallel', '--deviate', '--real-llm'] as $flag) {
        expect($hint[1])->toContain($flag);
    }
});

test('SKILL が UI/UX ヒューリスティクス H11-H14 を定義すること', function (): void {
    $contents = bhSkillSource();

    foreach (['H11', 'H12', 'H13', 'H14'] as $h) {
        expect($contents)->toContain("| {$h} |");
    }
    expect($contents)->toMatch('/視覚破綻/u');
    expect($contents)->toMatch('/アフォーダンス/u');
    expect($contents)->toMatch('/レスポンシブ/u');
    expect($contents)->toMatch('/アクセシビリティ基礎/u');
});

test('SKILL が H13 の viewport sweep 手順 (resize / mobile・tablet) を含むこと', function (): void {
    $contents = bhSkillSource();

    expect($contents)->toMatch(bhSkillH13SweepPattern());
    // 失敗時にどの寸法が欠けたか読みやすくするための補助 assertion。
    expect($contents)->toMatch('/375[x×]667/u');
    expect($contents)->toMatch('/768[x×]1024/u');
});

test('Phase 4 の report テンプレートが UI/UX 検証行を含むこと', function (): void {
    $contents = bhSkillSource();

    expect($contents)->toMatch('/UI\/UX 検証:/u');
    // H11-H14 の所見欄が report 骨子に残っていること。
    expect($contents)->toMatch('/UI\/UX 検証:[\s\S]{0,200}?H14/u');
});

/*
 * 負のコントロール: 各 pin が「規約が失われた状態」を実際に検出することを fixture で確認する
 * (実 SKILL.md は書き換えない)。空振り gate を green として扱わないため。
 */
test('負のコントロール: 継続規約が薄まった SKILL 文面を検出する', function (): void {
    // 「続行できるなら続行」程度の任意規定に薄まり、逐次書き出し規約も消えた状態。
    $weakened = <<<'MD'
    ## 走行プロトコル

    5. 異常を見たら screenshot で証跡保存 → finding 記録 → 続行できるなら続行する。
    6. finding を見つけたらそのエリアは十分とみなしてよい。

    ## Phase 4: レポート

    走行が終わったら report を書く。
    MD;

    $violations = bhSkillContinuityViolations($weakened);
    $joined = implode("\n", $violations);
    expect($violations)->toHaveCount(11);
    expect($joined)->toContain('finding は停止信号ではない');
    expect($joined)->toContain('finding 件数で分母を縮めない');
    expect($joined)->toContain('最後にまとめて書く方式の禁止');

    // 正のコントロール: 実 SKILL.md は違反ゼロ (正 gate と同一判定器であることの確認)。
    expect(bhSkillContinuityViolations(bhSkillSource()))->toBe([]);
});

test('負のコントロール: H13 sweep の非近接 (寸法だけ別所) を検出する', function (): void {
    // H13 行と各寸法はあるが、resize が mobile 寸法から 200 字超離れている = 同一 sweep 手順でない。
    $withoutSweep = "| H13 | レスポンシブ | High |\n"
        .'375×667 という寸法だけ先に書き'.str_repeat('x', 250)."ここで resize の語が出る\n"
        ."768×1024 も別所にある\n";
    expect($withoutSweep)->not->toMatch(bhSkillH13SweepPattern());

    // 正のコントロール: sweep 行として書かれていれば掛かる。
    $withSweep = "| H13 | レスポンシブ | High |\n"
        .'`playwright-cli resize` を mobile 375×667 と tablet 768×1024 に変えて確認する';
    expect($withSweep)->toMatch(bhSkillH13SweepPattern());
});

test('負のコントロール: 既定モード集合の欠落を検出する', function (): void {
    // --real-llm が既定から落ちた宣言行。
    $declaration = '> **既定 (引数なし) = `--all --coverage --parallel --deviate`**';
    $m = [];
    expect(preg_match('/\*\*既定 \(引数なし\) = `([^`]+)`\*\*/u', $declaration, $m))->toBe(1);
    /** @var array{0: string, 1: string} $m */
    expect($m[1])->not->toContain('--real-llm');
});
