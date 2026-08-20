<?php

declare(strict_types=1);

use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
use Tests\Support\TemplateDivergence\ComparisonState;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\FingerprintLedger;
use Tests\Support\TemplateDivergence\FingerprintReconciler;
use Tests\Support\TemplateDivergence\LedgerPins;
use Tests\Support\TemplateDivergence\LedgerRole;
use Tests\Support\TemplateDivergence\ParsedLedger;
use Tests\Support\TemplateDivergence\PathObservation;
use Tests\Support\TemplateDivergence\ReconciliationResult;
use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;

/*
 * 指紋台帳 (`docs/template-fingerprints.json`) と実ファイルの**突合** (家系の正典 t1)。
 *
 * 落とすのは 2 つである:
 *  (3a) テンプレートと内容が食い違っているのに、逸脱の登録も採用時債務の記載も無いパス
 *  (3b) 内容がテンプレート準拠へ戻ったのに、逸脱の登録が残っているパス
 * 判定の実体は `FingerprintReconciler` (純関数) にあり、本テストは**現物を読んで観測を組み立て、
 * 種別ごとに空であることを見るだけ**の薄い層である。検出力 (負例) は
 * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` と
 * `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` が固定する。
 *
 * 母集合は**正典が公開する指紋台帳のキー ∩ 本リポジトリの追跡ファイル**である
 * (生成規則の正本は `AppFingerprintBuilder` の docblock)。
 *
 * ---------------------------------------------------------------------------
 * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
 * docs/template-divergence.md には写さない):
 *
 *  1. **粒度はファイル単位**である。共有ファイルの**内部**の逸脱 (規約の一部だけを変えた等) は
 *     検出しない
 *  2. **母集合の外には沈黙する**。アプリ固有ファイル (提供元が共有しないと分類したもの。
 *     `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` 等) と、
 *     正典側にしか無いパス (未受領 / 追従遅れ) は 1 件も見ない
 *  3. **テンプレート更新への追従遅れは検出しない**。指紋は取り込んだ時点の写しなので、
 *     正典が先へ進んでも本リポジトリでは食い違いが生じない
 *  4. **登録済みのパスの追加の drift は検出しない**。既に不一致で登録があるパスは、
 *     その後どれだけ内容が変わっても「不一致のまま」であり同じ判定になる
 *     (検出するのは**一致から不一致へ移る瞬間**である)。
 *     **債務パスは例外**で、採用時ハッシュとの一致まで見るので追加の変更は落ちる
 *  5. **採用時債務の中身は説明されていない**。意図的逸脱と追従遅れの区別は付いていない
 *     (分類の契機は登録簿の D34 の見直し期限である)。件数の正本は
 *     `LedgerPins::ADOPTION_DEBT_COUNT` であり、本 docblock には件数を書かない
 *  6. **手編集による無効化は止まらない**。指紋台帳 / 債務一覧 / `LedgerPins` / 本検査自身の
 *     書き換えは検査を書き換えるのと等価であり、PR レビューの義務である。
 *     F6 が保証するのは**必須メンバが母集合に残り regular file であること**までで、
 *     登録済みになった本検査の**中身**は固定しない
 *  7. **`generated_at_commit` の実在は検証しない** (別リポジトリの commit なので原理的に不可能)。
 *     書式と pin との一致だけを見る
 *  8. **git 追跡外のファイルは母集合に入らない**
 *  9. **本検査は突合であって遮断ではない**。逸脱を作れなくするものではなく、
 *     登録なしに作れなくするものである
 * 10. **債務一覧の増加は機械では止まらない**。生成器のガードと件数 pin の PR 差分に依存する
 *     (本検査は履歴を入力に取らないので旧コミットとの比較はできない)
 * ---------------------------------------------------------------------------
 *
 * 実行不能 (指紋台帳 / 登録簿 / 債務一覧が読めない、解釈できない、git が失敗する) は
 * skip でも緑でもなく**不合格**にする。
 */

/**
 * 本機構自身のファイル (必須メンバ pin)。
 *
 * 検査を黙らせる変更自体を検査対象にするため、**この一覧のすべてが母集合に在り、
 * かつ regular file である**ことを F6 が見る。一覧は `LedgerPins` ではなく本ファイルに置く
 * (pin の置き場に「どのファイルを見るか」を混ぜないため)。
 *
 * @return list<string>
 */
function fingerprintRequiredMembers(): array
{
    return [
        'tests/Architecture/TemplateDivergenceFingerprintTest.php',
        'tests/Support/TemplateDivergence/FingerprintLedger.php',
        'tests/Support/TemplateDivergence/AtomicLedgerWriter.php',
        'tests/Support/TemplateDivergence/LedgerRole.php',
        'tests/Support/TemplateDivergence/ComparisonState.php',
        'scripts/update-template-fingerprints.php',
    ];
}

/** 指紋台帳の生バイト列 (読めないことは不合格)。 */
function fingerprintLedgerRaw(): string
{
    $raw = file_get_contents(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH));
    if ($raw === false) {
        throw new RuntimeException('指紋台帳 '.LedgerPins::FINGERPRINT_LEDGER_PATH.' を読めない');
    }

    return $raw;
}

/** 指紋台帳の DTO。 */
function fingerprintLedger(): FingerprintLedger
{
    return FingerprintLedger::fromJson(fingerprintLedgerRaw());
}

/**
 * 採用時債務一覧。
 *
 * @return array{templateLedgerCommit: string, entries: array<string, string>}
 */
function fingerprintDebt(): array
{
    static $cache = null;

    if ($cache === null) {
        $cache = AdoptionDebtInventory::read(base_path());
    }

    return $cache;
}

/** git 追跡ファイルの集合 (パス => true)。 */
function fingerprintTrackedSet(): array
{
    static $cache = null;

    if ($cache === null) {
        $cache = array_fill_keys(TrackedRepositoryFiles::all(base_path()), true);
    }

    return $cache;
}

/**
 * 母集合の各パスを観測する。
 *
 * `MissingCurrent` になるのは **git index / working tree から消えた場合だけ**である。
 * symlink / 通常ファイルでない / 読めない / ハッシュ計算の失敗は**別種の「検査不能」**として
 * 記録し、消滅へ畳まない (畳むと「検査不能を消滅へ畳まない」不変条件そのものが壊れる)。
 *
 * @return array<string, PathObservation>
 */
function fingerprintObservations(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $tracked = fingerprintTrackedSet();
    $observations = [];

    foreach (fingerprintLedger()->entries as $path => $templateHash) {
        $absolute = base_path($path);

        if (! array_key_exists($path, $tracked)) {
            $observations[$path] = new PathObservation(ComparisonState::MissingCurrent, null, null);

            continue;
        }
        if (is_link($absolute)) {
            $observations[$path] = new PathObservation(null, null, 'symlink である (内容の指紋を取らない)');

            continue;
        }
        if (! file_exists($absolute)) {
            // index には残っているが working tree に無い = 削除
            $observations[$path] = new PathObservation(ComparisonState::MissingCurrent, null, null);

            continue;
        }
        if (! is_file($absolute)) {
            $observations[$path] = new PathObservation(null, null, '通常ファイルでない');

            continue;
        }

        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            $observations[$path] = new PathObservation(null, null, 'ハッシュを計算できない');

            continue;
        }

        $observations[$path] = new PathObservation(
            $hash === $templateHash ? ComparisonState::Matched : ComparisonState::ContentMismatch,
            $hash,
            null,
        );
    }

    return $cache = $observations;
}

/**
 * 登録簿の解析結果から対象パスのリストを組み立てる (F13 が先に解析の成功を見る)。
 *
 * @return list<array{path: string, label: string}>
 */
function fingerprintRegisteredPaths(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $parsed = fingerprintParsedDivergenceLedger();

    $registered = [];
    foreach ($parsed->entries as $entry) {
        if ($entry->metadata === null) {
            throw new RuntimeException('逸脱の登録簿に登録メタ表を解析できない登録がある: '.$entry->label());
        }
        foreach ($entry->metadata->targetPaths as $path) {
            $registered[] = ['path' => $path, 'label' => $entry->label()];
        }
    }

    return $cache = $registered;
}

/** 逸脱の登録簿の解析結果。 */
function fingerprintParsedDivergenceLedger(): ParsedLedger
{
    static $cache = null;

    if ($cache === null) {
        $markdown = file_get_contents(base_path('docs/template-divergence.md'));
        if ($markdown === false) {
            throw new RuntimeException('逸脱の登録簿 docs/template-divergence.md を読めない');
        }
        $cache = DivergenceLedgerParser::parse($markdown);
    }

    return $cache;
}

/** 突合の結果 (1 回だけ計算する)。 */
function fingerprintReconciliation(): ReconciliationResult
{
    static $cache = null;

    if ($cache === null) {
        $cache = FingerprintReconciler::reconcile(
            observations: fingerprintObservations(),
            registered: fingerprintRegisteredPaths(),
            debt: fingerprintDebt()['entries'],
            templateHashes: fingerprintLedger()->entries,
        );
    }

    return $cache;
}

test('F0: 指紋台帳・登録簿・債務一覧が実在して読めること (読み取り失敗は不合格)', function (): void {
    expect(trim(fingerprintLedgerRaw()))->not->toBe('')
        ->and(fingerprintDebt())->toHaveKey('templateLedgerCommit')
        ->and(is_file(base_path('docs/template-divergence.md')))->toBeTrue();

    // 負のコントロール: 読めない入力が黙って空へ潰れず例外になること
    expect(fn (): array => AdoptionDebtInventory::read(base_path('storage/framework/t236-absent')))
        ->toThrow(RuntimeException::class);
    expect(fn (): FingerprintLedger => FingerprintLedger::fromJson(''))
        ->toThrow(RuntimeException::class);
});

test('F1: 指紋台帳の schema が解釈でき role が app で、正本が正準形バイト一致であること', function (): void {
    $raw = fingerprintLedgerRaw();
    $ledger = FingerprintLedger::fromJson($raw);

    expect($ledger->role)->toBe(LedgerRole::App)
        ->and($ledger->schemaVersion)->toBe(FingerprintLedger::SCHEMA_VERSION)
        // 重複キー・非正準な整形・キー順の崩れ・末尾改行の欠落をまとめて落とす
        ->and($raw)->toBe($ledger->toJson());
});

test('F2: composer.json の name が aicue の識別子と完全一致すること', function (): void {
    $raw = file_get_contents(base_path('composer.json'));
    expect($raw)->toBeString();

    /** @var mixed $decoded */
    $decoded = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    $name = $decoded['name'] ?? null;

    expect($name)->toBe('rio-development/aicue');
});

test('F3: 母集合の件数が pin と完全一致すること', function (): void {
    expect(fingerprintLedger()->entries)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);
});

test('F4: 母集合と git 追跡ファイルがどちらも非空であること (走査の生存確認)', function (): void {
    expect(fingerprintLedger()->entries)->not->toBeEmpty()
        ->and(fingerprintTrackedSet())->not->toBeEmpty()
        ->and(count(fingerprintTrackedSet()))->toBeGreaterThanOrEqual(1000);
});

test('F5: 指紋台帳の generated_at_commit が出自の pin と一致すること', function (): void {
    expect(fingerprintLedger()->generatedAtCommit)->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT);
});

test('F6: 本機構自身のファイルが母集合にあり regular file であること', function (): void {
    $members = fingerprintRequiredMembers();
    $population = fingerprintLedger()->entries;

    // 一覧そのものが空になったら (= 誰も pin していない状態) 不合格
    expect($members)->not->toBeEmpty();

    foreach ($members as $member) {
        expect(array_key_exists($member, $population))->toBeTrue(
            "本機構のファイルが母集合から外れています: {$member}",
        );
        expect(is_file(base_path($member)) && ! is_link(base_path($member)))->toBeTrue(
            "本機構のファイルが regular file ではありません: {$member}",
        );
    }
});

test('F7: 母集合の全パスを観測でき、消滅と検査不能を混同しないこと', function (): void {
    $observations = fingerprintObservations();

    expect($observations)->toHaveCount(LedgerPins::FINGERPRINT_POPULATION_COUNT);

    foreach ($observations as $path => $observation) {
        // 状態が付いた観測と検査不能の観測は排他である (PathObservation が型で保証している)
        expect($observation->state !== null || $observation->inspectionFailure !== null)->toBeTrue(
            "観測が状態も理由も持っていません: {$path}",
        );
    }
});

test('F8: 検査不能の観測が 0 件であること (登録済み・債務で吸収させない)', function (): void {
    expect(fingerprintReconciliation()->inspectionFailures)->toBe([]);
});

test('F9: 3a / 3b が 0 件であること', function (): void {
    $result = fingerprintReconciliation();

    expect($result->unregisteredMismatches)->toBe([], fingerprintFailureHint3a($result->unregisteredMismatches))
        ->and($result->staleRegistrations)->toBe([], fingerprintFailureHint3b($result->staleRegistrations));
});

test('F10: 採用時債務の規則違反が 0 件であること', function (): void {
    $result = fingerprintReconciliation();

    expect($result->resolvedDebtPaths)->toBe([], fingerprintFailureHintResolved($result->resolvedDebtPaths))
        ->and($result->mutatedDebtPaths)->toBe([], fingerprintFailureHintMutated($result->mutatedDebtPaths))
        ->and($result->doubleDeclaredPaths)->toBe([], '債務一覧と逸脱の登録が同じパスを二重に宣言しています')
        ->and($result->debtPathsOutsidePopulation)->toBe([], '債務一覧に母集合外のパスがあります (生成器で再生成すること)')
        ->and($result->duplicateRegisteredPaths)->toBe([], '同じ対象パスを 2 つ以上の登録が挙げています');
});

test('F11: 採用時債務の件数が pin と完全一致すること', function (): void {
    expect(fingerprintDebt()['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT);
});

test('F12: 債務が非空の間は債務一覧のファイルが登録簿に登録されていること', function (): void {
    $debt = fingerprintDebt()['entries'];
    if ($debt === []) {
        // 0 件になったら一覧ファイルと登録を同じ変更で消す (D34 の再判定の条件)
        expect(true)->toBeTrue();

        return;
    }

    $registeredPaths = array_column(fingerprintRegisteredPaths(), 'path');

    expect(in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true))->toBeTrue(
        '債務が残っている間は '.AdoptionDebtInventory::INVENTORY_PATH.' を登録簿へ登録しておくこと',
    );
});

test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
    $parsed = fingerprintParsedDivergenceLedger();

    expect($parsed->unparsable)->toBeFalse()
        ->and($parsed->parseViolations)->toBe([])
        ->and($parsed->entries)->not->toBeEmpty();

    foreach ($parsed->entries as $entry) {
        expect($entry->metadata)->not->toBeNull('登録メタ表を解析できない登録があります: '.$entry->label());
    }
});

test('F14: 2 生成物の世代が揃っていて、債務が定義どおり食い違っていること', function (): void {
    $ledger = fingerprintLedger();
    $debt = fingerprintDebt();

    // 片方だけが更新された状態を落とす (件数 pin だけでは増減が相殺されて緑になり得る)
    expect($debt['templateLedgerCommit'])->toBe(
        $ledger->generatedAtCommit,
        '債務一覧のヘッダと指紋台帳の generated_at_commit が食い違っています (生成器で再生成すること)',
    );

    foreach ($debt['entries'] as $path => $adoptionHash) {
        // 母集合外の債務は F10 の担当なのでここでは hash 比較へ進めない
        if (! array_key_exists($path, $ledger->entries)) {
            continue;
        }

        expect($adoptionHash)->not->toBe(
            $ledger->entries[$path],
            "債務パスの採用時ハッシュが正典側ハッシュと同じです (債務は定義上食い違っている): {$path}",
        );
    }
});

/**
 * 3a の直し方 (失敗メッセージ)。
 *
 * @param  list<string>  $paths
 */
function fingerprintFailureHint3a(array $paths): string
{
    return 'テンプレートと共有するファイルを変えたのに登録が無いパスがあります ('.count($paths).' 件):'.PHP_EOL
        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
        .'直し方は 2 通りです (指紋台帳や債務一覧を書き換えて黙らせないこと):'.PHP_EOL
        .'  1. 意図的逸脱なら docs/template-divergence.md へ登録を足し、'.PHP_EOL
        .'     LedgerPins::DIVERGENCE_ENTRY_COUNT を同じ変更で 1 増やす'.PHP_EOL
        .'  2. 逸脱でないなら内容をテンプレート準拠へ戻す';
}

/**
 * 3b の直し方 (失敗メッセージ)。
 *
 * @param  list<string>  $paths
 */
function fingerprintFailureHint3b(array $paths): string
{
    return '内容がテンプレート準拠へ戻ったのに登録が残っているパスがあります ('.count($paths).' 件):'.PHP_EOL
        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
        .'直し方: 該当パスを登録の対象パス欄から削り (全パスが戻ったなら登録ごと削除し)、'.PHP_EOL
        .'        LedgerPins::DIVERGENCE_ENTRY_COUNT を同じ変更で直すこと。'.PHP_EOL
        .'        状態の語 (恒久 / 監視中) で「解消済み」を表さないこと。';
}

/**
 * 債務が解消したときの直し方 (失敗メッセージ)。
 *
 * @param  list<string>  $paths
 */
function fingerprintFailureHintResolved(array $paths): string
{
    return '内容がテンプレート準拠へ戻ったのに債務一覧に残っているパスがあります ('.count($paths).' 件):'.PHP_EOL
        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
        .'直し方: 該当行を '.AdoptionDebtInventory::INVENTORY_PATH.' から削り、'.PHP_EOL
        .'        LedgerPins::ADOPTION_DEBT_COUNT を同じ変更で減らすこと。';
}

/**
 * 債務パスを触ってしまったときの直し方 (失敗メッセージ)。
 *
 * @param  list<string>  $paths
 */
function fingerprintFailureHintMutated(array $paths): string
{
    return '採用時の姿から変わった債務パスがあります ('.count($paths).' 件):'.PHP_EOL
        .implode(PHP_EOL, array_map(static fn (string $p): string => "  - {$p}", $paths)).PHP_EOL
        .'債務は「採用時点の凍結された観測」なので、変更したまま残すことはできません。'.PHP_EOL
        .'次の 3 つから選んでください:'.PHP_EOL
        .'  1. 内容を採用時の姿へ戻す'.PHP_EOL
        .'  2. テンプレート準拠へ同期して債務一覧から削る'.PHP_EOL
        .'  3. 意図的逸脱として docs/template-divergence.md へ登録を書き、債務一覧から削る'.PHP_EOL
        .'いずれの場合も LedgerPins の件数を同じ変更で直すこと。';
}
