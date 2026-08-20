<?php

declare(strict_types=1);

use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
use Tests\Support\TemplateDivergence\ComparisonState;
use Tests\Support\TemplateDivergence\FingerprintLedger;
use Tests\Support\TemplateDivergence\FingerprintReconciler;
use Tests\Support\TemplateDivergence\LedgerPins;
use Tests\Support\TemplateDivergence\LedgerRole;
use Tests\Support\TemplateDivergence\PathObservation;
use Tests\Support\TemplateDivergence\RepoRelativePath;

/*
 * 指紋台帳の突合 (`FingerprintReconciler`) と、その入力を作る純関数
 * (`FingerprintLedger` / `RepoRelativePath` / `PathObservation` /
 * `AdoptionDebtInventory`) の**負例と正例**を固定する。
 *
 * ★負例が本テストの存在理由である (共通規約 (c))。突合 gate
 *   (`tests/Architecture/TemplateDivergenceFingerprintTest.php`) は現物を読むだけの
 *   薄い層なので、「検出器が何も検出できないまま緑」という状態は**ここでしか**落とせない。
 *
 * ★検体は文字列と配列で組み立てる。DB もファイルシステムも触らない
 *   (実ファイルを読むのは「現物が通ること」を見る正例だけである)。
 *
 * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
 *   `FingerprintLedger::fromJson()` = 11 形 / `RepoRelativePath::isValid()` = 8 形 /
 *   `PathObservation` = 7 形 / `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
 *   `FingerprintReconciler` = 8 種別。
 *
 * 生成器側 (`AppFingerprintBuilder` / `AtomicLedgerWriter` / `AtomicTextWriter` /
 * `FingerprintGenerationContext` / `FingerprintGenerationService` / 実プロセス) の
 * 負例は `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` が持つ。
 */

/** 検体で使う 64 桁小文字 hex (末尾の 1 文字だけを変えて別ハッシュを作る)。 */
function fingerprintHash(string $tail = 'a'): string
{
    return str_repeat('0', 63).$tail;
}

/** 検体で使う 40 桁小文字 hex の commit。 */
function fingerprintCommit(string $tail = 'a'): string
{
    return str_repeat('1', 39).$tail;
}

/**
 * 正準形の指紋台帳 JSON を組み立てる (DTO の直列化をそのまま使う)。
 *
 * @param  array<string, string>  $entries
 */
function fingerprintLedgerJson(array $entries, LedgerRole $role = LedgerRole::App): string
{
    return (new FingerprintLedger(
        FingerprintLedger::SCHEMA_VERSION,
        $role,
        fingerprintCommit(),
        $entries,
    ))->toJson();
}

/** 採用時債務一覧の検体を組み立てる (ヘッダ + 昇順の 2 列)。 */
function adoptionDebtText(string $commit, string ...$lines): string
{
    return '# template_ledger_commit='.$commit."\n".($lines === [] ? '' : implode("\n", $lines)."\n");
}

// ---------------------------------------------------------------------------
// FingerprintLedger::fromJson() — 11 形の負例と正例
// ---------------------------------------------------------------------------

test('負例: FingerprintLedger::fromJson() が解釈不能な指紋台帳を例外にする', function (string $json): void {
    expect(fn (): FingerprintLedger => FingerprintLedger::fromJson($json))
        ->toThrow(RuntimeException::class);
})->with([
    '1: JSON として不正' => ['{"schema_version": 1,}'],
    '2: 最上位が object でない (空配列を含む)' => ['[]'],
    '3: キー集合が正準形と不一致 (余分なキー)' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":{},"extra":1}',
    ],
    '4: schema_version が 1 でない' => [
        '{"schema_version":2,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
    ],
    '5: role が文字列でない' => [
        '{"schema_version":1,"role":1,"generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
    ],
    '6: role が値域外' => [
        '{"schema_version":1,"role":"library","generated_at_commit":"'.str_repeat('1', 40).'","entries":{}}',
    ],
    '7: generated_at_commit が 40 桁小文字 hex でない' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"ABC","entries":{}}',
    ],
    '8: entries が object でない (空配列を含む)' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":[]}',
    ],
    '9: キーが repo-relative な単一ファイルパスでない' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
            .'{"../escape.php":"'.str_repeat('0', 64).'"}}',
    ],
    '10: 値が 64 桁小文字 hex でない' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
            .'{"a.php":"deadbeef"}}',
    ],
    '11: キーが昇順でない' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
            .'{"b.php":"'.str_repeat('0', 64).'","a.php":"'.str_repeat('0', 64).'"}}',
    ],
]);

test('正例: FingerprintLedger::fromJson() が正準形の指紋台帳を受理する', function (): void {
    $ledger = FingerprintLedger::fromJson(fingerprintLedgerJson([
        'a.php' => fingerprintHash('a'),
        'b.php' => fingerprintHash('b'),
    ]));

    expect($ledger->schemaVersion)->toBe(1)
        ->and($ledger->role)->toBe(LedgerRole::App)
        ->and($ledger->generatedAtCommit)->toBe(fingerprintCommit())
        ->and($ledger->entries)->toBe(['a.php' => fingerprintHash('a'), 'b.php' => fingerprintHash('b')]);
});

test('正例: entries が空 object の指紋台帳は解釈できる (母集合の非空は gate が見る)', function (): void {
    expect(FingerprintLedger::fromJson(fingerprintLedgerJson([]))->entries)->toBe([]);
});

test('FingerprintLedger の鮮度比較は generated_at_commit を無視する', function (): void {
    $entries = ['a.php' => fingerprintHash()];
    $left = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('a'), $entries);
    $right = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('b'), $entries);

    expect($left->matchesIgnoringGeneratedCommit($right))->toBeTrue()
        ->and($left->matchesIgnoringGeneratedCommit(
            new FingerprintLedger(1, LedgerRole::Template, fingerprintCommit('a'), $entries),
        ))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 正準形バイト一致 (F1 の上積み) — 重複キー・整形の崩れを落とす
// ---------------------------------------------------------------------------

test('負例: 非正準な指紋台帳は解釈して直列化し直すとバイト一致しない', function (string $json): void {
    expect($json)->not->toBe(FingerprintLedger::fromJson($json)->toJson());
})->with([
    '最上位キーの重複' => [
        '{"schema_version":1,"role":"app","role":"app","generated_at_commit":"'.str_repeat('1', 40).'",'
            .'"entries":{"a.php":"'.str_repeat('0', 64).'"}}'."\n",
    ],
    'entries 内のパス重複' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
            .'{"a.php":"'.str_repeat('0', 64).'","a.php":"'.str_repeat('0', 64).'"}}'."\n",
    ],
    '整形の崩れ (最小化された JSON)' => [
        '{"schema_version":1,"role":"app","generated_at_commit":"'.str_repeat('1', 40).'","entries":'
            .'{"a.php":"'.str_repeat('0', 64).'"}}'."\n",
    ],
    '末尾改行が無い' => [
        rtrim(fingerprintLedgerJson(['a.php' => fingerprintHash()]), "\n"),
    ],
]);

test('正例: 現物の指紋台帳が正準形バイト一致である', function (): void {
    $raw = file_get_contents(base_path('docs/template-fingerprints.json'));
    expect($raw)->toBeString();

    $ledger = FingerprintLedger::fromJson((string) $raw);

    expect((string) $raw)->toBe($ledger->toJson())
        ->and($ledger->role)->toBe(LedgerRole::App)
        ->and($ledger->generatedAtCommit)->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT)
        ->and($ledger->entries)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);
});

// ---------------------------------------------------------------------------
// RepoRelativePath::isValid() — 8 形の負例と正例
// ---------------------------------------------------------------------------

test('負例: RepoRelativePath::isValid() が単一ファイルパスでない形を落とす', function (string $path): void {
    expect(RepoRelativePath::isValid($path))->toBeFalse();
})->with([
    '1: 空文字' => [''],
    '2: 絶対パス' => ['/etc/passwd'],
    '3: 要素が空' => ['app//Example.php'],
    '4: 要素が . ' => ['app/./Example.php'],
    '5: 要素が ..' => ['app/../Example.php'],
    '6: NUL を含む' => ["app/Example.php\0"],
    '7: 末尾がスラッシュ (ディレクトリ表記)' => ['app/'],
    '8: 制御文字を含む' => ["app/Ex\tample.php"],
]);

test('正例: RepoRelativePath::isValid() が実在する追跡パスの形を受理する', function (string $path): void {
    expect(RepoRelativePath::isValid($path))->toBeTrue();
})->with([
    'tests/Pest.php',
    '.claude/skills/app-design/SKILL.md',
    'docs/template-divergence.md',
    'scripts/ci/drop-test-db.php',
    'lang/ja/auth.php',
]);

// ---------------------------------------------------------------------------
// PathObservation — 許容 4 形 / 不正 7 形
// ---------------------------------------------------------------------------

test('正例: PathObservation が許容する 4 形はすべて構築できる', function (): void {
    $matched = new PathObservation(ComparisonState::Matched, fingerprintHash(), null);
    $mismatch = new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('b'), null);
    $missing = new PathObservation(ComparisonState::MissingCurrent, null, null);
    $failed = new PathObservation(null, null, 'symlink である');

    expect($matched->state)->toBe(ComparisonState::Matched)
        ->and($mismatch->currentHash)->toBe(fingerprintHash('b'))
        ->and($missing->currentHash)->toBeNull()
        ->and($failed->inspectionFailure)->toBe('symlink である')
        ->and($failed->state)->toBeNull();
});

test('負例: PathObservation が矛盾した組み合わせを例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
    expect(fn (): PathObservation => new PathObservation($state, $hash, $failure))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '1: MissingCurrent にハッシュが付いている' => [ComparisonState::MissingCurrent, fingerprintHash(), null],
    '2: MissingCurrent に検査不能の理由が付いている' => [ComparisonState::MissingCurrent, null, '読めない'],
    '3: Matched なのにハッシュが無い' => [ComparisonState::Matched, null, null],
    '4: ContentMismatch なのにハッシュが無い' => [ComparisonState::ContentMismatch, null, null],
    '5: 正常状態に検査不能の理由が付いている' => [ComparisonState::Matched, null, '読めない'],
    '6: 3 つすべて null (状態も理由も無い)' => [null, null, null],
    '7: 検査不能の理由が空文字' => [null, null, ''],
]);

test('負例: PathObservation がハッシュの書式違反を例外にする', function (): void {
    expect(fn (): PathObservation => new PathObservation(ComparisonState::Matched, 'DEADBEEF', null))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// FingerprintReconciler — 8 種別を個別に発火させる / 正常入力では全種別が空
// ---------------------------------------------------------------------------

/**
 * 突合の検体。母集合は 4 パス固定で、テンプレート側ハッシュは `t` 系である。
 *
 * @return array<string, string>
 */
function reconcilerTemplateHashes(): array
{
    return [
        'kept.php' => fingerprintHash('1'),
        'registered.php' => fingerprintHash('2'),
        'debt.php' => fingerprintHash('3'),
        'plain.php' => fingerprintHash('4'),
    ];
}

/**
 * 母集合を検体の一部だけに絞る (突合は観測と母集合がちょうど一致することを要求する)。
 *
 * @return array<string, string>
 */
function reconcilerHashesFor(string ...$paths): array
{
    return array_intersect_key(reconcilerTemplateHashes(), array_flip($paths));
}

test('正例: 一致・登録済み相違・採用時のままの債務だけなら 8 種別すべて空', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            // テンプレートと一致している (未登録・非債務でよい)
            'kept.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('1'), null),
            // 相違だが登録簿に説明がある
            'registered.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
            // 相違かつ債務一覧にあり、採用時の姿のまま
            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('8'), null),
            'plain.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('4'), null),
        ],
        registered: [['path' => 'registered.php', 'label' => 'D1']],
        debt: ['debt.php' => fingerprintHash('8')],
        templateHashes: reconcilerTemplateHashes(),
    );

    expect($result->isClean())->toBeTrue()
        ->and($result->unregisteredMismatches)->toBe([])
        ->and($result->staleRegistrations)->toBe([])
        ->and($result->resolvedDebtPaths)->toBe([])
        ->and($result->mutatedDebtPaths)->toBe([])
        ->and($result->doubleDeclaredPaths)->toBe([])
        ->and($result->debtPathsOutsidePopulation)->toBe([])
        ->and($result->duplicateRegisteredPaths)->toBe([])
        ->and($result->inspectionFailures)->toBe([]);
});

test('負例: 3a — 相違なのに登録も債務も無いパスを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'plain.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
        ],
        registered: [],
        debt: [],
        templateHashes: ['plain.php' => fingerprintHash('4')],
    );

    expect($result->unregisteredMismatches)->toBe(['plain.php'])
        ->and($result->isClean())->toBeFalse();
});

test('負例: 3a — 消えたパス (MissingCurrent) も未登録なら 3a 側へ倒れる', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: ['plain.php' => new PathObservation(ComparisonState::MissingCurrent, null, null)],
        registered: [],
        debt: [],
        templateHashes: ['plain.php' => fingerprintHash('4')],
    );

    expect($result->unregisteredMismatches)->toBe(['plain.php'])
        ->and($result->inspectionFailures)->toBe([]);
});

test('負例: 3b — 一致へ戻ったのに登録が残っているパスを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'registered.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('2'), null),
        ],
        registered: [['path' => 'registered.php', 'label' => 'D1']],
        debt: [],
        templateHashes: reconcilerHashesFor('registered.php'),
    );

    expect($result->staleRegistrations)->toBe(['registered.php']);
});

test('負例: 債務規則 (i) — 一致へ戻ったのに債務一覧に残っているパスを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: ['debt.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('3'), null)],
        registered: [],
        debt: ['debt.php' => fingerprintHash('8')],
        templateHashes: reconcilerHashesFor('debt.php'),
    );

    expect($result->resolvedDebtPaths)->toBe(['debt.php'])
        ->and($result->mutatedDebtPaths)->toBe([]);
});

test('負例: 債務規則 (i-2) — 採用時の姿から変わった債務パスを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('7'), null),
        ],
        registered: [],
        debt: ['debt.php' => fingerprintHash('8')],
        templateHashes: reconcilerHashesFor('debt.php'),
    );

    expect($result->mutatedDebtPaths)->toBe(['debt.php'])
        ->and($result->resolvedDebtPaths)->toBe([]);
});

test('負例: 債務規則 (i-2) — 削除された債務パスも採用時の姿から変わった扱いになる', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: ['debt.php' => new PathObservation(ComparisonState::MissingCurrent, null, null)],
        registered: [],
        debt: ['debt.php' => fingerprintHash('8')],
        templateHashes: reconcilerHashesFor('debt.php'),
    );

    expect($result->mutatedDebtPaths)->toBe(['debt.php'])
        ->and($result->unregisteredMismatches)->toBe([]);
});

test('負例: 債務規則 (ii) — 債務と登録の二重宣言を検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('8'), null),
        ],
        registered: [['path' => 'debt.php', 'label' => 'D1']],
        debt: ['debt.php' => fingerprintHash('8')],
        templateHashes: reconcilerHashesFor('debt.php'),
    );

    expect($result->doubleDeclaredPaths)->toBe(['debt.php']);
});

test('負例: 債務一覧に母集合外のパスがあることを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: ['plain.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('4'), null)],
        registered: [],
        debt: ['outside.php' => fingerprintHash('8')],
        templateHashes: ['plain.php' => fingerprintHash('4')],
    );

    expect($result->debtPathsOutsidePopulation)->toBe(['outside.php'])
        ->and($result->mutatedDebtPaths)->toBe([]);
});

test('負例: 同一パスを 2 つ以上の登録が挙げていることを検出する', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'registered.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
        ],
        registered: [
            ['path' => 'registered.php', 'label' => 'D1'],
            ['path' => 'registered.php', 'label' => 'D2'],
        ],
        debt: [],
        templateHashes: reconcilerHashesFor('registered.php'),
    );

    expect($result->duplicateRegisteredPaths)->toBe(['registered.php']);
});

test('負例: 検査不能の観測は登録済み・債務でも吸収されない', function (array $registered, array $debt): void {
    $result = FingerprintReconciler::reconcile(
        observations: ['debt.php' => new PathObservation(null, null, 'symlink である')],
        registered: $registered,
        debt: $debt,
        templateHashes: reconcilerHashesFor('debt.php'),
    );

    expect($result->inspectionFailures)->toBe(['debt.php'])
        ->and($result->unregisteredMismatches)->toBe([])
        ->and($result->mutatedDebtPaths)->toBe([]);
})->with([
    '未登録・非債務' => [[], []],
    '登録済み' => [[['path' => 'debt.php', 'label' => 'D1']], []],
    '債務一覧にある' => [[], ['debt.php' => '0000000000000000000000000000000000000000000000000000000000000008']],
]);

test('突合はすべての種別を評価してから返す (早期 return しない)', function (): void {
    $result = FingerprintReconciler::reconcile(
        observations: [
            'kept.php' => new PathObservation(null, null, '読めない'),
            'registered.php' => new PathObservation(ComparisonState::Matched, fingerprintHash('2'), null),
            'debt.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('7'), null),
            'plain.php' => new PathObservation(ComparisonState::ContentMismatch, fingerprintHash('9'), null),
        ],
        registered: [
            ['path' => 'registered.php', 'label' => 'D1'],
            ['path' => 'registered.php', 'label' => 'D2'],
        ],
        debt: ['debt.php' => fingerprintHash('8'), 'outside.php' => fingerprintHash('8')],
        templateHashes: reconcilerTemplateHashes(),
    );

    expect($result->inspectionFailures)->toBe(['kept.php'])
        ->and($result->staleRegistrations)->toBe(['registered.php'])
        ->and($result->duplicateRegisteredPaths)->toBe(['registered.php'])
        ->and($result->mutatedDebtPaths)->toBe(['debt.php'])
        ->and($result->unregisteredMismatches)->toBe(['plain.php'])
        ->and($result->debtPathsOutsidePopulation)->toBe(['outside.php']);
});

test('突合は観測の集合が母集合と一致しないと例外にする (取り違えを黙って通さない)', function (array $observations, array $templateHashes): void {
    expect(fn (): mixed => FingerprintReconciler::reconcile(
        observations: $observations,
        registered: [],
        debt: [],
        templateHashes: $templateHashes,
    ))->toThrow(RuntimeException::class);
})->with([
    '観測にだけあるパス' => [
        ['unknown.php' => new PathObservation(ComparisonState::Matched, '0000000000000000000000000000000000000000000000000000000000000004', null)],
        ['plain.php' => '0000000000000000000000000000000000000000000000000000000000000004'],
    ],
    '母集合にだけあるパス (観測が欠けている)' => [
        [],
        ['plain.php' => '0000000000000000000000000000000000000000000000000000000000000004'],
    ],
]);

test('突合は観測の比較状態が正典側ハッシュと矛盾したら例外にする', function (ComparisonState $state, string $hash): void {
    expect(fn (): mixed => FingerprintReconciler::reconcile(
        observations: ['plain.php' => new PathObservation($state, $hash, null)],
        registered: [],
        debt: [],
        templateHashes: ['plain.php' => fingerprintHash('4')],
    ))->toThrow(RuntimeException::class);
})->with([
    '一致と称しているのにハッシュが違う' => [ComparisonState::Matched, fingerprintHash('9')],
    '相違と称しているのにハッシュが同じ' => [ComparisonState::ContentMismatch, fingerprintHash('4')],
]);

// ---------------------------------------------------------------------------
// AdoptionDebtInventory — 11 形 (読み取り失敗 1 + 内容 10) の負例と正例
// ---------------------------------------------------------------------------

test('負例: AdoptionDebtInventory::read() は一覧が読めないと例外にする (1 形目)', function (): void {
    expect(fn (): array => AdoptionDebtInventory::read(sys_get_temp_dir().'/t236-no-such-root-'.bin2hex(random_bytes(6))))
        ->toThrow(RuntimeException::class);
});

test('負例: AdoptionDebtInventory::parse() が壊れた一覧を例外にする', function (string $contents): void {
    expect(fn (): array => AdoptionDebtInventory::parse($contents))->toThrow(RuntimeException::class);
})->with([
    '2: 空' => [''],
    '3: 先頭行が世代識別子のヘッダでない' => ["# something-else\na.php\t".str_repeat('0', 64)."\n"],
    '4: 末尾改行が無い' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\t".str_repeat('0', 64)],
    '5: 空行がある' => ['# template_ledger_commit='.str_repeat('1', 40)."\n\na.php\t".str_repeat('0', 64)."\n"],
    '6: タブ 2 列でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\n"],
    '7: 前後に空白がある' => ['# template_ledger_commit='.str_repeat('1', 40)."\n a.php\t".str_repeat('0', 64)."\n"],
    '8: パスの重複' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
        ."a.php\t".str_repeat('0', 64)."\n"
        ."a.php\t".str_repeat('0', 64)."\n", ],
    '9: パスが単一ファイルパスでない' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
        ."../a.php\t".str_repeat('0', 64)."\n", ],
    '10: ハッシュが 64 桁小文字 hex でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\na.php\tDEADBEEF\n"],
    '11: パスの昇順でない' => ['# template_ledger_commit='.str_repeat('1', 40)."\n"
        ."b.php\t".str_repeat('0', 64)."\n"
        ."a.php\t".str_repeat('0', 64)."\n", ],
]);

test('正例: AdoptionDebtInventory::parse() がヘッダと 2 列の一覧を受理する', function (): void {
    $parsed = AdoptionDebtInventory::parse(adoptionDebtText(
        fingerprintCommit(),
        "a.php\t".fingerprintHash('1'),
        "b.php\t".fingerprintHash('2'),
    ));

    expect($parsed['templateLedgerCommit'])->toBe(fingerprintCommit())
        ->and($parsed['entries'])->toBe(['a.php' => fingerprintHash('1'), 'b.php' => fingerprintHash('2')]);
});

test('正例: ヘッダだけの一覧 (債務 0 件) は受理する (0 件は最終目標である)', function (): void {
    $parsed = AdoptionDebtInventory::parse(adoptionDebtText(fingerprintCommit()));

    expect($parsed['entries'])->toBe([]);
});

test('正例: 現物の採用時債務一覧が読めて件数の pin と一致する', function (): void {
    $parsed = AdoptionDebtInventory::read(base_path());

    expect($parsed['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT)
        ->and($parsed['templateLedgerCommit'])->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT);
});
