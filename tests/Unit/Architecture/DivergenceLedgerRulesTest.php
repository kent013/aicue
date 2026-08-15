<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
use Tests\Support\TemplateDivergence\LedgerContext;
use Tests\Support\TemplateDivergence\TodoLedgerReference;

/*
 * 逸脱の登録簿の形式検査 (`DivergenceLedgerParser` + `DivergenceLedgerRules`) の
 * 正例と負例を固定する。
 *
 * ★負例が本テストの存在理由である。検査が「何も検出できないまま緑」になっていても
 *   実物の台帳が合格していれば Architecture レーンは緑になるので、
 *   検出器そのものの実効性はここでしか固定できない。
 *
 * ★期限の判定は**固定した基準日**を渡して検証する (実行日でテストが揺れない)。
 *
 * ★検体は文字列で組み立てる。実ファイルの実在判定は文脈 (`LedgerContext`) の
 *   クロージャに閉じてあるので、DB もファイルシステムも触らない。
 */

/** 検体の基準日。期限の境界はすべてこの日を起点に書く。 */
function divergenceBaseDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-16')->startOfDay();
}

/**
 * 検体で実在扱いにするファイル。
 *
 * @return list<string>
 */
function divergenceExistingFiles(): array
{
    return ['docs/template-divergence.md', 'AGENTS.md', 'README.md'];
}

/** 検体用の文脈 (実在判定は固定の一覧で答える)。 */
function divergenceContext(int $pinnedEntryCount = 1): LedgerContext
{
    return new LedgerContext(
        baseDate: divergenceBaseDate(),
        pinnedEntryCount: $pinnedEntryCount,
        pathExists: fn (string $path): bool => in_array($path, divergenceExistingFiles(), true),
        directoryExists: fn (string $path): bool => $path === 'devnotes/20260816-0300-todo-T179',
        rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn(
            $reference,
            "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n| T179 | 逸脱の登録簿の形式検査 |\n",
        ),
    );
}

/**
 * 登録メタ表の既定値 (すべて合格する値)。
 *
 * @return array<string, string>
 */
function divergenceDefaultMeta(): array
{
    return [
        '対象パス' => '`docs/template-divergence.md`',
        '業務要件起因の説明' => '業務の都合でテンプレートの形から外した理由',
        '揃え続ける不変条件と保証機構' => '不変条件 X を gate Y が守り続ける',
        '再判定の条件' => '前提 Z が変わったら見直す',
        '決めた日' => '2026-08-01',
        '決めた人' => '開発者',
        '根拠' => 'T010',
        '状態' => '恒久',
        '見直し期限' => '—',
    ];
}

/**
 * 登録メタ表の行を規定の順序で組み立てる。
 *
 * @param  array<string, string>  $overrides
 * @return list<array{0: string, 1: string}>
 */
function divergenceMetaRows(array $overrides = []): array
{
    $defaults = divergenceDefaultMeta();

    /** @var list<array{0: string, 1: string}> $rows */
    $rows = [];
    foreach (DivergenceLedgerParser::META_LABELS as $label) {
        $rows[] = [$label, $overrides[$label] ?? $defaults[$label]];
    }

    return $rows;
}

/**
 * 登録 1 件の Markdown を組み立てる。
 *
 * @param  list<array{0: string, 1: string}>  $rows
 */
function divergenceEntry(string $heading, array $rows): string
{
    $markdown = $heading."\n\n| 行 | 内容 |\n|---|---|\n";
    foreach ($rows as $row) {
        $markdown .= sprintf("| %s | %s |\n", $row[0], $row[1]);
    }

    return $markdown."\n| 観点 | テンプレート | 本アプリ |\n|---|---|---|\n| 例 | 例 | 例 |\n\n### なぜ正当な差分か\n\n説明。\n";
}

/**
 * 登録簿 1 冊の Markdown を組み立てる (規約節つき)。
 *
 * @param  list<string>  $entries
 */
function divergenceLedgerMarkdown(array $entries, ?int $declaredCount = null): string
{
    $declared = $declaredCount ?? count($entries);

    $markdown = "# テンプレート差分レジストリ\n\n登録エントリ: ".$declared." 件\n\n";
    $markdown .= "## 記録の原則\n\n- 解消した逸脱は登録から消す (この節は登録エントリ領域の外にある)\n\n";
    $markdown .= "## エントリ形式\n\n```\n## D1 <逸脱の要約>\n\n| 行 | 内容 |\n|---|---|\n```\n\n";

    return $markdown.implode("\n", $entries);
}

/**
 * 検体を解析して違反一覧を返す。
 *
 * @return list<string>
 */
function divergenceViolations(string $markdown, int $pinnedEntryCount = 1): array
{
    return DivergenceLedgerRules::violations(
        DivergenceLedgerParser::parse($markdown),
        divergenceContext($pinnedEntryCount),
    );
}

/** 違反一覧に指定の記号で始まる違反が含まれるか。 */
function divergenceHasViolation(string $marker, string $markdown, int $pinnedEntryCount = 1): bool
{
    foreach (divergenceViolations($markdown, $pinnedEntryCount) as $violation) {
        if (str_starts_with($violation, $marker)) {
            return true;
        }
    }

    return false;
}

test('正例: 統一形式を満たす検体は違反 0 件になる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
});

test('負のコントロール: 囲みコード区画の中の記入例は登録として数えない', function (): void {
    // 規約節の記入例 (`## D1 <逸脱の要約>`) は囲みの中にある。数えていれば件数が 2 になり赤くなる。
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ]);

    expect(DivergenceLedgerParser::parse($markdown)->entries)->toHaveCount(1);
});

test('負のコントロール: 登録エントリ領域より前の節は違反にならない', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ]);

    // `## 記録の原則` / `## エントリ形式` は領域の外なので正準形でなくてよい
    expect(divergenceViolations($markdown))->toBe([]);
});

test('TD1a: 見出しに印が付いていると落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 ✅ 逸脱の要約', divergenceMetaRows()),
    ]);

    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
});

test('TD1a: 見出しに解消を表す語や矢印があると落ちる', function (string $heading): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry($heading, divergenceMetaRows()),
    ]);

    expect(divergenceHasViolation('TD1', $markdown))->toBeTrue();
})->with([
    '矢印' => ['## D1 課金ゲートの反転 → 解消'],
    '解消' => ['## D1 課金ゲートの反転 (解消)'],
    '済み' => ['## D1 課金ゲートの反転 (対応済み)'],
]);

test('TD1: 要約に `+` を使う正当な見出しは落ちない', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 招待一本化 + 遷移コマンドロール', divergenceMetaRows()),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
});

test('TD1b: 見出しの階層を 1 段下げると登録として数えられず件数が合わない', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
        divergenceEntry('### D2 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
    ], declaredCount: 2);

    expect(divergenceHasViolation('TD12', $markdown, 2))->toBeTrue();
});

test('TD1c: id が重複すると落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
        divergenceEntry('## D1 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
    ]);

    expect(divergenceHasViolation('TD1', $markdown, 2))->toBeTrue();
});

test('TD2a: 登録メタ表が 9 行に足りないと落ちる', function (int $drop): void {
    $rows = divergenceMetaRows();
    array_splice($rows, $drop, 1);

    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);

    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
})->with([
    '8 行 (末尾を落とす)' => [8],
    '8 行 (途中を落とす)' => [3],
]);

test('TD2a: 登録メタ表が 9 行を超えると落ちる (列を増やして比較表に紛れさせても落ちる)', function (string $extraRow): void {
    $rows = divergenceMetaRows();
    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);
    // 9 行目 (見直し期限) の直後へ余分な行を差し込む
    $markdown = str_replace("| 見直し期限 | — |\n", "| 見直し期限 | — |\n".$extraRow."\n", $markdown);

    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
})->with([
    '2 列の 10 行目' => ['| 備考 | 10 行目 |'],
    '3 列の 10 行目 (比較表と同じ列数)' => ['| 備考 | 10 行目 | 隠したい値 |'],
]);

test('TD2b: ラベルの順序を入れ替えると落ちる', function (): void {
    $rows = divergenceMetaRows();
    [$rows[7], $rows[8]] = [$rows[8], $rows[7]];

    $markdown = divergenceLedgerMarkdown([divergenceEntry('## D1 逸脱の要約', $rows)]);

    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
});

test('TD3a: 対象パスが 0 件だと落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => ''])),
    ]);

    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
});

test('TD3b/TD3c/TD3d: 対象パスの値域と実在を見る', function (string $cell): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
    ]);

    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
})->with([
    'glob' => ['`app/Models/*.php`'],
    '波括弧展開' => ['`app/Models/{Cut,Take}.php`'],
    '絶対パス' => ['`/workspace/AGENTS.md`'],
    '上位への相対指定' => ['`../AGENTS.md`'],
    '実在しない' => ['`app/Nope.php`'],
    'ディレクトリ' => ['`devnotes/20260816-0300-todo-T179`'],
]);

test('TD3e: 対象パスのセルにバッククォート外の説明文を添えると落ちる', function (string $cell): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => $cell])),
    ]);

    expect(divergenceHasViolation('TD3', $markdown))->toBeTrue();
})->with([
    '説明を添える' => ['`AGENTS.md` (規約の正本)'],
    '読点でつなぐ' => ['`AGENTS.md`、`README.md`'],
    'バッククォート無し' => ['AGENTS.md'],
]);

test('TD3: 複数の対象パスを ` / ` でつなぐ形は合格する', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
});

test('TD4: 2 つの登録が同じ対象パスを挙げると落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md`'])),
        divergenceEntry('## D2 別の逸脱の要約', divergenceMetaRows(['対象パス' => '`AGENTS.md` / `README.md`'])),
    ]);

    expect(divergenceHasViolation('TD4', $markdown, 2))->toBeTrue();
});

test('TD5: 状態が値域の外だと落ちる', function (string $state): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['状態' => $state])),
    ]);

    expect(divergenceHasViolation('TD5', $markdown))->toBeTrue();
})->with([
    '解消済み' => ['解消済み'],
    '解消' => ['解消'],
    '未実装' => ['未実装'],
    '空' => [''],
]);

test('TD6: 監視中の見直し期限が不正だと落ちる', function (string $deadline): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
            '状態' => '監視中',
            '見直し期限' => $deadline,
        ])),
    ]);

    expect(divergenceHasViolation('TD6', $markdown))->toBeTrue();
})->with([
    '期限が無い' => ['—'],
    '空' => [''],
    '日付でない' => ['not-a-date'],
    '実在しない日付' => ['2026-02-30'],
    '基準日の前日 (期限切れ)' => ['2026-08-15'],
    '基準日から 401 日後' => ['2027-09-21'],
]);

test('TD6e: 監視中の見直し期限の境界は合格する', function (string $deadline): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
            '状態' => '監視中',
            '見直し期限' => $deadline,
        ])),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
})->with([
    '基準日当日' => ['2026-08-16'],
    '基準日の翌日' => ['2026-08-17'],
    '基準日から 400 日後' => ['2027-09-20'],
]);

test('TD7: 恒久に日付の見直し期限が書いてあると落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([
            '状態' => '恒久',
            '見直し期限' => '2026-12-31',
        ])),
    ]);

    expect(divergenceHasViolation('TD7', $markdown))->toBeTrue();
});

test('TD8: 決めた日が未来日・不正な日付だと落ちる', function (string $decidedOn): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => $decidedOn])),
    ]);

    expect(divergenceHasViolation('TD8', $markdown))->toBeTrue();
})->with([
    '基準日の翌日 (未来日)' => ['2026-08-17'],
    '実在しない日付' => ['2026-02-30'],
    '空' => [''],
    '日付でない' => ['not-a-date'],
    '桁が足りない' => ['2026-8-1'],
]);

test('TD8b: 決めた日が基準日当日は合格する', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた日' => '2026-08-16'])),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
});

test('TD9: 決めた人が値域の外だと落ちる', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['決めた人' => 'チーム'])),
    ]);

    expect(divergenceHasViolation('TD9', $markdown))->toBeTrue();
});

test('TD10: 根拠が実在しない・書式外・プレースホルダだと落ちる', function (string $rationale): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => $rationale])),
    ]);

    expect(divergenceHasViolation('TD10', $markdown))->toBeTrue();
})->with([
    '実在しない T 番号' => ['T999'],
    'プレースホルダ' => ['TBD'],
    '空' => [''],
    '実在しない devnotes' => ['devnotes/9999-nope/'],
    '書式外 (末尾のスラッシュ無し)' => ['devnotes/20260816-0300-todo-T179'],
    '書式外 (自由記述)' => ['前任者の口頭指示'],
]);

test('TD10: 実在する devnotes ディレクトリは根拠として合格する', function (): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['根拠' => 'devnotes/20260816-0300-todo-T179/'])),
    ]);

    expect(divergenceViolations($markdown))->toBe([]);
});

test('TD10c: T 番号の照合は表のセル境界で行う (T1 が T10 に一致しない)', function (): void {
    $todo = "| ID | タイトル |\n|---|---|\n| T010 | 何かの作業 |\n";

    expect(TodoLedgerReference::existsIn('T010', $todo))->toBeTrue()
        ->and(TodoLedgerReference::existsIn('T01', $todo))->toBeFalse()
        ->and(TodoLedgerReference::existsIn('T1', $todo))->toBeFalse();
});

test('TD11: 自由記述 3 欄が空かプレースホルダだと落ちる', function (string $label, string $value): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows([$label => $value])),
    ]);

    expect(divergenceHasViolation('TD11', $markdown))->toBeTrue();
})->with([
    '説明が空' => ['業務要件起因の説明', ''],
    '不変条件が伏せ字' => ['揃え続ける不変条件と保証機構', '...'],
    '再判定の条件が未定' => ['再判定の条件', '未定'],
    '再判定の条件が不在の記号' => ['再判定の条件', '—'],
]);

test('TD12: 明示件数・解析件数・固定件数の 3 点一致を要求する', function (int $declared, int $pinned): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ], declaredCount: $declared);

    expect(divergenceHasViolation('TD12', $markdown, $pinned))->toBeTrue();
})->with([
    '明示件数が多い' => [2, 1],
    '明示件数が少ない' => [0, 1],
    '固定件数が多い' => [1, 2],
    '固定件数が少ない' => [1, 0],
]);

test('TD12: 件数の明示行が無い・2 本ある場合も落ちる', function (string $markdown): void {
    expect(divergenceHasViolation('TD12', $markdown))->toBeTrue();
})->with([
    '明示行が無い' => [
        "# 台帳\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ],
    '明示行が 2 本' => [
        "# 台帳\n\n登録エントリ: 1 件\n\n登録エントリ: 1 件\n\n".divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ],
]);

test('P1: 囲みコード区画が閉じていないと解析不能で落ち、以降の規則を評価しない', function (): void {
    $markdown = "# 台帳\n\n登録エントリ: 1 件\n\n```\n## D1 <逸脱の要約>\n";

    $violations = divergenceViolations($markdown);

    // 件数 (TD12) も対象パス (TD3) も評価されないことまで固定する (fail-closed)
    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('P1');
});

test('P2: 登録エントリ領域が見つからないと解析不能で落ちる', function (): void {
    $markdown = "# 台帳\n\n登録エントリ: 0 件\n\n## 記録の原則\n\n- 何か\n";

    $violations = divergenceViolations($markdown, 0);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('P2');
});

test('P3: 台帳が扱わない囲みの書き方は明示的に拒否する', function (string $fence): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows()),
    ])."\n".$fence."\n本文\n".$fence."\n";

    expect(divergenceHasViolation('P3', $markdown))->toBeTrue();
})->with([
    'バッククォート 4 個' => ['````'],
    'チルダ 3 個' => ['~~~'],
    '言語名を添えた囲み' => ['```php'],
    '語を添えた囲み' => ['``` markdown'],
]);

test('P4: 登録メタ表のセルに `|` を書くと落ちる', function (string $value): void {
    $markdown = divergenceLedgerMarkdown([
        divergenceEntry('## D1 逸脱の要約', divergenceMetaRows(['再判定の条件' => $value])),
    ]);

    expect(divergenceHasViolation('TD2', $markdown))->toBeTrue();
})->with([
    '素の縦棒' => ['A | B が変わったら見直す'],
    'エスケープした縦棒' => ['A \\| B が変わったら見直す'],
]);
