<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
use Tests\Support\TemplateDivergence\AppFingerprintBuilder;
use Tests\Support\TemplateDivergence\AtomicLedgerWriter;
use Tests\Support\TemplateDivergence\AtomicTextWriter;
use Tests\Support\TemplateDivergence\FingerprintGenerationContext;
use Tests\Support\TemplateDivergence\FingerprintGenerationService;
use Tests\Support\TemplateDivergence\FingerprintLedger;
use Tests\Support\TemplateDivergence\GenerationRefused;
use Tests\Support\TemplateDivergence\LedgerPins;
use Tests\Support\TemplateDivergence\LedgerRole;
use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;

/*
 * 生成器側の負例と正例を固定する — `AppFingerprintBuilder` (母集合と債務の規則) /
 * `AtomicLedgerWriter` `AtomicTextWriter` (原子的置換) /
 * `FingerprintGenerationContext` (入力条件の値域) /
 * `FingerprintGenerationService` (拒否・実行不能・部分更新) / 実プロセス (引数解析)。
 *
 * ★**判定は一時ディレクトリを root にした service を直接呼んで確かめる**。
 *   CLI は `dirname(__DIR__)` で自分のリポジトリを指す作りなので、プロセスを起動して
 *   生成の成否を試すと**本物の生成物を書き換えてしまう**。
 *   実プロセスを起動するのは**書き込み前に終了する経路だけ** (引数の欠落・未知オプション・
 *   重複オプション・入力ファイル不在) で、そのとき本物の生成物が 1 バイトも変わらないことも見る。
 *
 * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
 *   `AtomicLedgerWriter` / `AtomicTextWriter` = 各 8 件 (正常系 1 + 失敗 7) /
 *   `FingerprintGenerationContext` = 6 形 / service の拒否 = 4 経路。
 */

/** 検体で使う 64 桁小文字 hex。 */
function generatorHash(string $tail): string
{
    return str_repeat('0', 63).$tail;
}

/** 検体で使う 40 桁小文字 hex の commit。 */
function generatorCommit(string $tail): string
{
    return str_repeat('1', 39).$tail;
}

/**
 * 出力先 2 つのディレクトリを持つ一時 root を作る (テスト終了後も /tmp に残るだけ)。
 */
function generatorTempRoot(): string
{
    $root = sys_get_temp_dir().'/t236-gen-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs', 0o777, true);
    mkdir($root.'/tests/Support/TemplateDivergence', 0o777, true);

    return $root;
}

/**
 * 正典側の指紋台帳 (role: template) の生バイト列。
 *
 * @param  array<string, string>  $entries
 */
function generatorTemplateRaw(array $entries, ?string $commit = null): string
{
    return (new FingerprintLedger(
        FingerprintLedger::SCHEMA_VERSION,
        LedgerRole::Template,
        $commit ?? generatorCommit('a'),
        $entries,
    ))->toJson();
}

/** 実ファイルを触る I/O 一式 (service へ渡す)。 */
function generatorIo(): array
{
    return [
        'tempPathFactory' => static fn (string $targetPath): string|false => dirname($targetPath).'/.'
            .basename($targetPath).'.'.bin2hex(random_bytes(6)).'.tmp',
        'writer' => static fn (string $path, string $data): int|false => file_put_contents($path, $data),
        'reader' => static fn (string $path): string|false => is_file($path) ? file_get_contents($path) : false,
        'renamer' => static fn (string $from, string $to): bool => rename($from, $to),
        'remover' => static fn (string $path): bool => ! is_file($path) || unlink($path),
    ];
}

/**
 * service を一時 root で 1 回走らせる。
 *
 * @param  array<string, string>  $templateEntries
 * @param  array<string, string>  $files  repo-relative パス => 実際に置く内容
 * @param  list<string>  $registeredTargetPaths
 * @param  array<string, string>  $existingDebt
 */
function generatorRun(
    string $root,
    array $templateEntries,
    array $files,
    array $registeredTargetPaths = [],
    array $existingDebt = [],
    ?FingerprintLedger $previousLedger = null,
    bool $adopt = false,
    ?string $templateCommit = null,
    ?callable $writer = null,
): array {
    foreach ($files as $relative => $contents) {
        $absolute = $root.'/'.$relative;
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }
        file_put_contents($absolute, $contents);
    }

    $raw = generatorTemplateRaw($templateEntries, $templateCommit);
    $io = generatorIo();

    $context = FingerprintGenerationContext::forRoot(
        root: $root,
        expectedTemplateLedgerSha256: hash('sha256', $raw),
        expectedSourceCommit: FingerprintLedger::fromJson($raw)->generatedAtCommit,
        adoptNewTemplateLedger: $adopt,
        previousLedger: $previousLedger,
    );

    return FingerprintGenerationService::generate(
        context: $context,
        templateLedgerRaw: $raw,
        trackedPaths: array_keys($files),
        hasher: static fn (string $relative): string => hash_file('sha256', $root.'/'.$relative) ?: '',
        registeredTargetPaths: $registeredTargetPaths,
        divergenceEntryCount: 32,
        existingDebt: $existingDebt,
        tempPathFactory: $io['tempPathFactory'],
        writer: $writer ?? $io['writer'],
        reader: $io['reader'],
        renamer: $io['renamer'],
        remover: $io['remover'],
    );
}

// ---------------------------------------------------------------------------
// TrackedRepositoryFiles
// ---------------------------------------------------------------------------

test('負例: TrackedRepositoryFiles は git リポジトリでない場所で例外にする (空を返さない)', function (): void {
    $root = generatorTempRoot();

    expect(fn (): array => TrackedRepositoryFiles::all($root))->toThrow(RuntimeException::class);
});

test('正例: TrackedRepositoryFiles は本リポジトリで非空の昇順一覧を返す', function (): void {
    $paths = TrackedRepositoryFiles::all(base_path());

    $sorted = $paths;
    sort($sorted, SORT_STRING);

    expect($paths)->not->toBeEmpty()
        ->and($paths)->toBe($sorted)
        ->and($paths)->toContain('tests/Pest.php')
        ->and(count($paths))->toBe(count(array_unique($paths)));
});

// ---------------------------------------------------------------------------
// AppFingerprintBuilder — 母集合と債務の規則
// ---------------------------------------------------------------------------

test('正例: 初回生成は正典キーと現在の追跡パスの積を母集合にし、未登録の相違を凍結する', function (): void {
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
        'kept.php' => generatorHash('1'),
        'moved.php' => generatorHash('2'),
        'registered.php' => generatorHash('3'),
        'template-only.php' => generatorHash('4'),
    ]);

    $built = AppFingerprintBuilder::build(
        $template,
        ['kept.php', 'moved.php', 'registered.php', 'app-only.php'],
        static fn (string $path): string => match ($path) {
            'kept.php' => generatorHash('1'),      // 一致
            'moved.php' => generatorHash('9'),     // 相違・未登録 → 債務へ凍結
            'registered.php' => generatorHash('8'), // 相違・登録済み → 債務ではない
            default => generatorHash('0'),
        },
        ['registered.php'],
        [],
        null,
    );

    expect(array_keys($built['ledger']->entries))->toBe(['kept.php', 'moved.php', 'registered.php'])
        ->and($built['ledger']->role)->toBe(LedgerRole::App)
        ->and($built['ledger']->generatedAtCommit)->toBe(generatorCommit('a'))
        ->and($built['debt'])->toBe(['moved.php' => generatorHash('9')])
        ->and($built['matched'])->toBe(1)
        ->and($built['mismatched'])->toBe(2)
        ->and($built['missing'])->toBe(0)
        ->and($built['addedDebt'])->toBe(['moved.php'])
        ->and($built['seeded'])->toBeTrue();
});

test('正例: 2 回目以降は既存の債務の採用時ハッシュを持ち越し、解消したものを外す', function (): void {
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
        'kept.php' => generatorHash('1'),
        'resolved.php' => generatorHash('2'),
    ]);
    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
        'kept.php' => generatorHash('1'),
        'resolved.php' => generatorHash('2'),
    ]);

    $built = AppFingerprintBuilder::build(
        $template,
        ['kept.php', 'resolved.php'],
        static fn (string $path): string => match ($path) {
            'kept.php' => generatorHash('7'),        // 相違のまま (採用時ハッシュを持ち越す)
            'resolved.php' => generatorHash('2'),    // テンプレート一致へ戻った
            default => generatorHash('0'),
        },
        [],
        ['kept.php' => generatorHash('7'), 'resolved.php' => generatorHash('5')],
        $previous,
    );

    expect($built['debt'])->toBe(['kept.php' => generatorHash('7')])
        ->and($built['addedDebt'])->toBe([])
        ->and($built['seeded'])->toBeFalse();
});

test('正例: 載せ替えで前世代の正典ハッシュと一致する新規債務は通る', function (): void {
    // 前世代の正典では a.php = hash1 で、アプリもそのまま (= 一致していた)。
    // 新しい正典で a.php = hash2 へ動いたので、アプリ側は「テンプレートが前進した」側の相違になる。
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
        'a.php' => generatorHash('2'),
    ]);
    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
        'a.php' => generatorHash('1'),
    ]);

    $built = AppFingerprintBuilder::build(
        $template,
        ['a.php'],
        static fn (string $path): string => generatorHash('1'),
        [],
        [],
        $previous,
    );

    expect($built['debt'])->toBe(['a.php' => generatorHash('1')])
        ->and($built['addedDebt'])->toBe(['a.php']);
});

test('負例: 載せ替えでも前世代の正典ハッシュと一致しない新規債務は拒否される', function (): void {
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
        'a.php' => generatorHash('2'),
    ]);
    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
        'a.php' => generatorHash('1'),
    ]);

    expect(fn (): array => AppFingerprintBuilder::build(
        $template,
        ['a.php'],
        static fn (string $path): string => generatorHash('9'), // 自分で変えた食い違い
        [],
        [],
        $previous,
    ))->toThrow(GenerationRefused::class);
});

test('正例: ローカルで消したパスは母集合に残り消滅として数えられる', function (): void {
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
        'gone.php' => generatorHash('1'),
        'here.php' => generatorHash('2'),
    ]);
    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
        'gone.php' => generatorHash('1'),
        'here.php' => generatorHash('2'),
    ]);

    $built = AppFingerprintBuilder::build(
        $template,
        ['here.php'], // gone.php は追跡から消えた
        static fn (string $path): string => generatorHash('2'),
        ['gone.php'], // 登録済みなので債務へ入れる必要が無い
        [],
        $previous,
    );

    expect(array_keys($built['ledger']->entries))->toBe(['gone.php', 'here.php'])
        ->and($built['missing'])->toBe(1)
        ->and($built['debt'])->toBe([]);
});

test('正例: 正典側から消えたパスは母集合から外れる', function (): void {
    $template = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('b'), [
        'here.php' => generatorHash('2'),
    ]);
    $previous = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
        'dropped.php' => generatorHash('1'),
        'here.php' => generatorHash('2'),
    ]);

    $built = AppFingerprintBuilder::build(
        $template,
        ['dropped.php', 'here.php'],
        static fn (string $path): string => generatorHash('2'),
        [],
        [],
        $previous,
    );

    expect(array_keys($built['ledger']->entries))->toBe(['here.php']);
});

test('負例: AppFingerprintBuilder が不正な入力を例外にする', function (callable $call): void {
    expect($call)->toThrow(RuntimeException::class);
})->with([
    '入力の role が app である' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        [],
        null,
    )],
    '母集合が 0 件' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['b.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        [],
        null,
    )],
    'ハッシュ関数が 64 桁 hex を返さない' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => 'DEADBEEF',
        [],
        [],
        null,
    )],
    'ハッシュ関数が失敗して例外を投げる' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => throw new RuntimeException('読めない'),
        [],
        [],
        null,
    )],
    '追跡パスに重複がある' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php', 'a.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        [],
        null,
    )],
    '追跡パスに不正な形がある' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php', '../escape.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        [],
        null,
    )],
    '登録の対象パスに重複がある' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => str_repeat('0', 64),
        ['a.php', 'a.php'],
        [],
        null,
    )],
    '既存の債務のハッシュが 64 桁 hex でない' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        ['a.php' => 'DEADBEEF'],
        null,
    )],
    '前世代の台帳の role が template である' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ['a.php'],
        fn (string $p): string => str_repeat('0', 64),
        [],
        [],
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
    )],
    '債務パスが git 追跡から消えている' => [fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), [
            'a.php' => str_repeat('0', 64),
            'b.php' => str_repeat('0', 63).'2',
        ]),
        ['b.php'],
        fn (string $p): string => str_repeat('0', 63).'2',
        [],
        ['a.php' => str_repeat('0', 63).'9'],
        new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), [
            'a.php' => str_repeat('0', 64),
            'b.php' => str_repeat('0', 63).'2',
        ]),
    )],
]);

test('負例: 消えた未登録パスを債務へ追加しようとすると拒否される', function (): void {
    expect(fn (): array => AppFingerprintBuilder::build(
        new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), [
            'gone.php' => generatorHash('1'),
            'here.php' => generatorHash('2'),
        ]),
        ['here.php'],
        static fn (string $path): string => generatorHash('2'),
        [],
        [],
        new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), [
            'gone.php' => generatorHash('1'),
            'here.php' => generatorHash('2'),
        ]),
    ))->toThrow(GenerationRefused::class);
});

// ---------------------------------------------------------------------------
// AtomicLedgerWriter / AtomicTextWriter — 各 8 件 (正常系 1 + 失敗 7)
// ---------------------------------------------------------------------------

/** 置換対象の正本を用意し、元の内容を返す。 */
function atomicTarget(string $original): string
{
    $dir = sys_get_temp_dir().'/t236-atomic-'.bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);
    $path = $dir.'/ledger.json';
    file_put_contents($path, $original);

    return $path;
}

/** 有効な指紋台帳の内容 (writer の読み戻し検証を通る)。 */
function atomicValidJson(string $tail = 'a'): string
{
    return (new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), [
        'a.php' => str_repeat('0', 63).$tail,
    ]))->toJson();
}

test('正例: AtomicLedgerWriter は検証を通った内容で正本を置換する', function (): void {
    $target = atomicTarget(atomicValidJson('a'));
    $next = atomicValidJson('b');
    $io = generatorIo();

    $reason = AtomicLedgerWriter::replace(
        $target,
        $next,
        static fn (): string|false => $io['tempPathFactory']($target),
        $io['writer'],
        $io['reader'],
        $io['renamer'],
        $io['remover'],
    );

    expect($reason)->toBeNull()
        ->and(file_get_contents($target))->toBe($next)
        ->and(glob(dirname($target).'/.*.tmp'))->toBe([]);
});

test('負例: AtomicLedgerWriter はどの段で失敗しても正本のバイト列を変えない', function (
    callable $tempPathFactory,
    callable $writer,
    callable $reader,
    callable $renamer,
    callable $remover,
    string $contents,
): void {
    $original = atomicValidJson('a');
    $target = atomicTarget($original);

    $reason = AtomicLedgerWriter::replace(
        $target,
        $contents,
        static fn (): string|false => $tempPathFactory($target),
        $writer,
        $reader,
        $renamer,
        $remover,
    );

    expect($reason)->toBeString()
        ->and(file_get_contents($target))->toBe($original);
})->with(fn (): array => atomicFailureDatasets());

test('正例: AtomicTextWriter は検証関数を通った内容で正本を置換する', function (): void {
    $target = atomicTarget('# template_ledger_commit='.str_repeat('1', 40)."\n");
    $next = '# template_ledger_commit='.str_repeat('2', 40)."\n";
    $io = generatorIo();

    AtomicTextWriter::replace(
        $target,
        $next,
        static fn (): string|false => $io['tempPathFactory']($target),
        $io['writer'],
        $io['reader'],
        $io['renamer'],
        $io['remover'],
        static function (string $contents): void {
            AdoptionDebtInventory::parse($contents);
        },
    );

    expect(file_get_contents($target))->toBe($next)
        ->and(glob(dirname($target).'/.*.tmp'))->toBe([]);
});

test('負例: AtomicTextWriter はどの段で失敗しても例外を投げ正本を変えない', function (
    callable $tempPathFactory,
    callable $writer,
    callable $reader,
    callable $renamer,
    callable $remover,
    string $contents,
): void {
    $original = '# template_ledger_commit='.str_repeat('1', 40)."\n";
    $target = atomicTarget($original);

    expect(fn (): mixed => AtomicTextWriter::replace(
        $target,
        $contents,
        static fn (): string|false => $tempPathFactory($target),
        $writer,
        $reader,
        $renamer,
        $remover,
        static function (string $c): void {
            AdoptionDebtInventory::parse($c);
        },
    ))->toThrow(RuntimeException::class);

    expect(file_get_contents($target))->toBe($original);
})->with(fn (): array => atomicFailureDatasets(
    '# template_ledger_commit='.str_repeat('3', 40)."\n",
    'これは債務一覧として解釈できない',
));

/**
 * 原子的置換の失敗注入 7 件。dataset 名を件数の正本とする。
 *
 * @return array<string, list<mixed>>
 */
function atomicFailureDatasets(?string $validContents = null, ?string $invalidContents = null): array
{
    $io = generatorIo();
    $valid = $validContents ?? atomicValidJson('b');
    $invalid = $invalidContents ?? '{ これは JSON ではない';

    return [
        '1: 一時パスを生成できない' => [
            static fn (string $target): string|false => false,
            $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $valid,
        ],
        '2: 一時パスの dirname が正本と違う' => [
            static fn (string $target): string|false => sys_get_temp_dir().'/t236-elsewhere.tmp',
            $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $valid,
        ],
        '3: 書き込みが途中で切れた' => [
            $io['tempPathFactory'],
            static fn (string $path, string $data): int|false => (int) file_put_contents($path, substr($data, 0, 3)),
            $io['reader'], $io['renamer'], $io['remover'], $valid,
        ],
        '4: 一時ファイルを読み直せない' => [
            $io['tempPathFactory'], $io['writer'],
            static fn (string $path): string|false => false,
            $io['renamer'], $io['remover'], $valid,
        ],
        '5: 読み戻した内容が検証を通らない' => [
            $io['tempPathFactory'], $io['writer'], $io['reader'], $io['renamer'], $io['remover'], $invalid,
        ],
        '6: rename に失敗した' => [
            $io['tempPathFactory'], $io['writer'], $io['reader'],
            static fn (string $from, string $to): bool => false,
            $io['remover'], $valid,
        ],
        '7: 失敗のうえ一時ファイルの削除にも失敗した' => [
            $io['tempPathFactory'], $io['writer'], $io['reader'],
            static fn (string $from, string $to): bool => false,
            static fn (string $path): bool => false,
            $valid,
        ],
    ];
}

// ---------------------------------------------------------------------------
// FingerprintGenerationContext — 6 形
// ---------------------------------------------------------------------------

test('正例: FingerprintGenerationContext は正しい組み合わせで構築できる', function (): void {
    $context = FingerprintGenerationContext::forRoot(
        root: '/tmp/t236-root',
        expectedTemplateLedgerSha256: str_repeat('a', 64),
        expectedSourceCommit: str_repeat('1', 40),
        adoptNewTemplateLedger: false,
        previousLedger: new FingerprintLedger(1, LedgerRole::App, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
    );

    expect($context->fingerprintOutputPath)->toBe('/tmp/t236-root/'.LedgerPins::FINGERPRINT_LEDGER_PATH)
        ->and($context->debtOutputPath)->toBe('/tmp/t236-root/'.AdoptionDebtInventory::INVENTORY_PATH);
});

test('負例: FingerprintGenerationContext が不正な入力条件を例外にする', function (callable $call): void {
    expect($call)->toThrow(RuntimeException::class);
})->with([
    '1: 期待 sha256 が 64 桁小文字 hex でない' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
        '/tmp/t236-root', 'DEADBEEF', str_repeat('1', 40), false, null,
    )],
    '2: 期待 source commit が 40 桁小文字 hex でない' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
        '/tmp/t236-root', str_repeat('a', 64), 'ABC', false, null,
    )],
    '3: 出力先 2 つが同一' => [fn (): FingerprintGenerationContext => new FingerprintGenerationContext(
        root: '/tmp/t236-root',
        expectedTemplateLedgerSha256: str_repeat('a', 64),
        expectedSourceCommit: str_repeat('1', 40),
        adoptNewTemplateLedger: false,
        previousLedger: null,
        fingerprintOutputPath: '/tmp/t236-root/docs/same.json',
        debtOutputPath: '/tmp/t236-root/docs/same.json',
    )],
    '4: 出力先が規定のパスでない' => [fn (): FingerprintGenerationContext => new FingerprintGenerationContext(
        root: '/tmp/t236-root',
        expectedTemplateLedgerSha256: str_repeat('a', 64),
        expectedSourceCommit: str_repeat('1', 40),
        adoptNewTemplateLedger: false,
        previousLedger: null,
        fingerprintOutputPath: '/tmp/elsewhere/fingerprints.json',
        debtOutputPath: '/tmp/t236-root/'.AdoptionDebtInventory::INVENTORY_PATH,
    )],
    '5: 前世代の台帳の role が template である' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
        '/tmp/t236-root', str_repeat('a', 64), str_repeat('1', 40), false,
        new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
    )],
    '6: 載せ替えでないのに前世代の commit が pin と違う' => [fn (): FingerprintGenerationContext => FingerprintGenerationContext::forRoot(
        '/tmp/t236-root', str_repeat('a', 64), str_repeat('1', 40), false,
        new FingerprintLedger(1, LedgerRole::App, str_repeat('2', 40), ['a.php' => str_repeat('0', 64)]),
    )],
]);

// ---------------------------------------------------------------------------
// FingerprintGenerationService — 拒否 4 経路 / 書き込み前失敗 / 部分更新
// ---------------------------------------------------------------------------

test('正例: service が両生成物を書き、3 つの pin 値を報告する', function (): void {
    $root = generatorTempRoot();

    $report = generatorRun(
        root: $root,
        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
    );

    expect($report['populationCount'])->toBe(2)
        ->and($report['adoptionDebtCount'])->toBe(1)
        ->and($report['divergenceEntryCount'])->toBe(32)
        ->and($report['matched'])->toBe(1)
        ->and($report['mismatched'])->toBe(1);

    $ledger = FingerprintLedger::fromJson((string) file_get_contents($root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH));
    $debt = AdoptionDebtInventory::parse((string) file_get_contents($root.'/'.AdoptionDebtInventory::INVENTORY_PATH));

    expect($ledger->role)->toBe(LedgerRole::App)
        ->and($ledger->entries)->toHaveCount(2)
        ->and($debt['entries'])->toBe(['b.php' => hash('sha256', 'CHANGED')])
        ->and($debt['templateLedgerCommit'])->toBe($ledger->generatedAtCommit);
});

test('負例: service の拒否 4 経路では生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
    $root = generatorTempRoot();

    // まず正常な生成物を作る (以後これが 1 バイトも変わらないことを見る)
    generatorRun(
        root: $root,
        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
    );

    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
    $ledgerBefore = (string) file_get_contents($ledgerPath);
    $debtBefore = (string) file_get_contents($debtPath);
    $previous = FingerprintLedger::fromJson($ledgerBefore);
    $io = generatorIo();

    $call = match ($case) {
        // 1: 既存台帳が role: template (CLI は先に exit 3 するが、型の側でも閉じる)
        'role' => fn (): mixed => FingerprintGenerationContext::forRoot(
            $root, str_repeat('a', 64), str_repeat('1', 40), false,
            new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
        ),
        // 2: 入力の sha256 が pin と違うのに載せ替えフラグが無い
        'sha' => function () use ($root, $previous, $io): mixed {
            $raw = generatorTemplateRaw(['a.php' => hash('sha256', 'A')]);

            return FingerprintGenerationService::generate(
                context: FingerprintGenerationContext::forRoot(
                    $root, str_repeat('a', 64), $previous->generatedAtCommit, false, $previous,
                ),
                templateLedgerRaw: $raw,
                trackedPaths: ['a.php'],
                hasher: static fn (string $p): string => hash('sha256', 'A'),
                registeredTargetPaths: [],
                divergenceEntryCount: 32,
                existingDebt: [],
                tempPathFactory: $io['tempPathFactory'],
                writer: $io['writer'],
                reader: $io['reader'],
                renamer: $io['renamer'],
                remover: $io['remover'],
            );
        },
        // 3: 債務へ新規パスを追加しようとした
        'debt' => fn (): mixed => generatorRun(
            root: $root,
            templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
            files: ['a.php' => 'MUTATED', 'b.php' => 'CHANGED'],
            existingDebt: ['b.php' => hash('sha256', 'CHANGED')],
            previousLedger: $previous,
            templateCommit: $previous->generatedAtCommit,
        ),
        // 4: 同じ正典入力のまま母集合を縮小しようとした
        'shrink' => fn (): mixed => generatorRun(
            root: $root,
            templateEntries: ['a.php' => hash('sha256', 'A')],
            files: ['a.php' => 'A'],
            previousLedger: $previous,
            templateCommit: $previous->generatedAtCommit,
        ),
    };

    expect($call)->toThrow(RuntimeException::class);

    expect(file_get_contents($ledgerPath))->toBe($ledgerBefore)
        ->and(file_get_contents($debtPath))->toBe($debtBefore);
})->with(['role', 'sha', 'debt', 'shrink']);

test('負例: 書き込み開始前の失敗では生成物が作られない', function (callable $call): void {
    expect($call)->toThrow(RuntimeException::class);
})->with([
    '入力の JSON が壊れている' => [function (): mixed {
        $root = generatorTempRoot();
        $io = generatorIo();
        $broken = '{ これは JSON ではない';

        return FingerprintGenerationService::generate(
            context: FingerprintGenerationContext::forRoot(
                $root, hash('sha256', $broken), str_repeat('1', 40), false, null,
            ),
            templateLedgerRaw: $broken,
            trackedPaths: ['a.php'],
            hasher: static fn (string $p): string => str_repeat('0', 64),
            registeredTargetPaths: [],
            divergenceEntryCount: 32,
            existingDebt: [],
            tempPathFactory: $io['tempPathFactory'],
            writer: $io['writer'],
            reader: $io['reader'],
            renamer: $io['renamer'],
            remover: $io['remover'],
        );
    }],
    '入力が正準形バイト一致でない' => [function (): mixed {
        $root = generatorTempRoot();
        $io = generatorIo();
        // 末尾改行を削って非正準にする (解釈はできるが正準形と 1 バイト違う)
        $raw = rtrim(generatorTemplateRaw(['a.php' => hash('sha256', 'A')]), "\n");

        return FingerprintGenerationService::generate(
            context: FingerprintGenerationContext::forRoot(
                $root, hash('sha256', $raw), FingerprintLedger::fromJson($raw)->generatedAtCommit, false, null,
            ),
            templateLedgerRaw: $raw,
            trackedPaths: ['a.php'],
            hasher: static fn (string $p): string => hash('sha256', 'A'),
            registeredTargetPaths: [],
            divergenceEntryCount: 32,
            existingDebt: [],
            tempPathFactory: $io['tempPathFactory'],
            writer: $io['writer'],
            reader: $io['reader'],
            renamer: $io['renamer'],
            remover: $io['remover'],
        );
    }],
    '母集合が 0 件' => [function (): mixed {
        $root = generatorTempRoot();

        return generatorRun(
            root: $root,
            templateEntries: ['only-in-template.php' => hash('sha256', 'A')],
            files: ['other.php' => 'X'],
        );
    }],
    '追跡ファイルが 0 件' => [function (): mixed {
        $root = generatorTempRoot();
        $io = generatorIo();
        $raw = generatorTemplateRaw(['a.php' => hash('sha256', 'A')]);

        return FingerprintGenerationService::generate(
            context: FingerprintGenerationContext::forRoot(
                $root, hash('sha256', $raw), FingerprintLedger::fromJson($raw)->generatedAtCommit, false, null,
            ),
            templateLedgerRaw: $raw,
            trackedPaths: [],
            hasher: static fn (string $p): string => hash('sha256', 'A'),
            registeredTargetPaths: [],
            divergenceEntryCount: 32,
            existingDebt: [],
            tempPathFactory: $io['tempPathFactory'],
            writer: $io['writer'],
            reader: $io['reader'],
            renamer: $io['renamer'],
            remover: $io['remover'],
        );
    }],
]);

test('負例: 指紋台帳の置換に失敗したら service は例外にする (戻り値を無視しない)', function (): void {
    $root = generatorTempRoot();

    expect(fn (): array => generatorRun(
        root: $root,
        templateEntries: ['a.php' => hash('sha256', 'A')],
        files: ['a.php' => 'A'],
        writer: static fn (string $path, string $data): int|false => false,
    ))->toThrow(RuntimeException::class);

    expect(is_file($root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH))->toBeFalse();
});

test('部分更新の 3 状態はいずれも世代識別子の突き合わせで赤になる', function (): void {
    $root = generatorTempRoot();
    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;

    // 第 1 世代
    generatorRun(
        root: $root,
        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
        templateCommit: generatorCommit('a'),
    );
    $firstLedger = (string) file_get_contents($ledgerPath);
    $firstDebt = (string) file_get_contents($debtPath);

    // (a) 指紋台帳だけが新世代になる状態を**失敗注入で**作る
    //     (債務一覧の書き込みだけが失敗する writer を渡す)
    $io = generatorIo();
    $failed = false;
    try {
        generatorRun(
            root: $root,
            templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
            files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
            existingDebt: ['b.php' => hash('sha256', 'CHANGED')],
            previousLedger: FingerprintLedger::fromJson($firstLedger),
            adopt: true,
            templateCommit: generatorCommit('b'),
            writer: static function (string $path, string $data) use ($io, $debtPath): int|false {
                if (str_contains($path, basename($debtPath))) {
                    return false;
                }

                return $io['writer']($path, $data);
            },
        );
    } catch (RuntimeException) {
        $failed = true;
    }

    expect($failed)->toBeTrue();

    $judge = static fn (): bool => AdoptionDebtInventory::parse((string) file_get_contents($debtPath))['templateLedgerCommit']
        === FingerprintLedger::fromJson((string) file_get_contents($ledgerPath))->generatedAtCommit;

    expect($judge())->toBeFalse(); // (a) 指紋台帳だけ新世代

    // (b) 債務一覧だけが新世代になる状態 (rename の順序では起こらないので直接作る)
    file_put_contents($ledgerPath, $firstLedger);
    file_put_contents($debtPath, AdoptionDebtInventory::render(generatorCommit('b'), [
        'b.php' => hash('sha256', 'CHANGED'),
    ]));

    expect($judge())->toBeFalse();

    // (c) 件数は同じで内容だけ違う部分更新 (世代が揃っていないことで落ちる)
    file_put_contents($debtPath, AdoptionDebtInventory::render(generatorCommit('c'), [
        'b.php' => hash('sha256', 'OTHER'),
    ]));

    expect($judge())->toBeFalse()
        // 件数だけを見ていたら緑になってしまうことを併せて示す
        ->and(AdoptionDebtInventory::parse((string) file_get_contents($debtPath))['entries'])
        ->toHaveCount(count(AdoptionDebtInventory::parse($firstDebt)['entries']));
});

// ---------------------------------------------------------------------------
// 実プロセス — 書き込み前に終了する経路だけ (本物の生成物には触れない)
// ---------------------------------------------------------------------------

test('負例: 生成器は引数が不正なら書き込み前に exit 1 して生成物を変えない', function (array $arguments): void {
    $ledgerPath = base_path(LedgerPins::FINGERPRINT_LEDGER_PATH);
    $debtPath = base_path(AdoptionDebtInventory::INVENTORY_PATH);
    $ledgerBefore = (string) file_get_contents($ledgerPath);
    $debtBefore = (string) file_get_contents($debtPath);

    $process = new Process(
        ['php', 'scripts/update-template-fingerprints.php', ...$arguments],
        base_path(),
    );
    $process->run();

    expect($process->getExitCode())->toBe(1, '標準エラー: '.$process->getErrorOutput())
        ->and(file_get_contents($ledgerPath))->toBe($ledgerBefore)
        ->and(file_get_contents($debtPath))->toBe($debtBefore);
})->with([
    '引数が無い' => [[]],
    '未知のオプション' => [['--template-ledger=/dev/null', '--unknown']],
    '--template-ledger の重複' => [['--template-ledger=/dev/null', '--template-ledger=/dev/null']],
    '--adopt-new-template-ledger の重複' => [[
        '--template-ledger=/dev/null', '--adopt-new-template-ledger', '--adopt-new-template-ledger',
    ]],
    '入力ファイルが存在しない' => [['--template-ledger=/tmp/t236-does-not-exist.json']],
    '--template-ledger の値が空' => [['--template-ledger=']],
]);
