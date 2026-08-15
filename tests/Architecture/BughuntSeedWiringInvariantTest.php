<?php

declare(strict_types=1);

use Database\Seeders\BughuntOAuthSeeder;
use Illuminate\Database\Seeder;
use Tests\Support\Bughunt\BughuntSeedRole;
use Tests\Support\Bughunt\BughuntSeedWiringInventory;
use Tests\Support\Bughunt\ShellFunctionWindow;

/*
 * Architecture invariant: bug-hunt の投入データ (seeder) の配線が目録と一致すること。
 *
 * 偽の外部サービスの配線は「登録漏れは無音で本物が動く」ことを理由に deny-by-default の
 * 実証 gate を持っているのに、**投入データ側は同じ理由が当てはまるのに検査が 1 つも無かった**。
 * 起きうる無音の事故は 3 つ (BughuntSeedWiringInventory の docblock に列挙)。
 *
 * SoT:
 *   - scripts/bug-hunt-shard.sh の cmd_provision / cmd_reseed (実際に流す列)
 *   - database/seeders/ の各 seeder (環境ガードの実体)
 *   - tests/Support/Bughunt/BughuntSeedWiringInventory.php (区分の目録)
 *
 * **保証範囲を誇張しない**: 見るのは静的な字面である。条件の論理 (かつ / または) は読めないため、
 * ガードを要求する区分には振る舞いテストを目録から紐づける (S-9)。
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * database/seeders 配下の Seeder クラス一覧 (実在するものだけ)。
 *
 * @return list<class-string>
 */
function bughuntSeedDeclaredSeederClasses(): array
{
    $root = base_path('database/seeders');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $classes = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'Database\\Seeders\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/** bug-hunt の shard スクリプト本体 (読み取り失敗は例外で落ちる) */
function bughuntSeedShardSource(): string
{
    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
    if (! is_string($source) || $source === '') {
        throw new RuntimeException('scripts/bug-hunt-shard.sh が読めない');
    }

    return $source;
}

/**
 * シェル関数の窓から `db:seed --class=<名前>` の列を出現順に取り出す。
 *
 * @return list<string> クラスの短い名前 (出現順)
 */
function bughuntSeedClassSequence(string $window): array
{
    $matches = [];
    preg_match_all('/db:seed\s+--class=([A-Za-z0-9_]+)/', $window, $matches);

    /** @var array{0: list<string>, 1: list<string>} $matches */
    return $matches[1];
}

/** クラスの短い名前 (FQCN の末尾セグメント) */
function bughuntSeedShortName(string $class): string
{
    $position = strrpos($class, '\\');

    return $position === false ? $class : substr($class, $position + 1);
}

/**
 * `run()` の最初の実効文の形を返す。
 *
 * - `first`: 最初の実効トークンの字句 (`if` なら 'if')
 * - `condition`: 最初の実効文が `if` のときの条件式の字句 (それ以外は空文字)
 * - `body`: 同じく `if` の本体の字句 (それ以外は空文字)
 *
 * @return array{first: string, condition: string, body: string}
 */
function bughuntSeedRunGuardShape(string $source): array
{
    /** @var list<array{id: int, text: string}> $tokens */
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = ['id' => $token[0], 'text' => $token[1]];

            continue;
        }
        $tokens[] = ['id' => -1, 'text' => $token];
    }

    $count = count($tokens);
    $signature = null;
    for ($i = 0; $i + 1 < $count; $i++) {
        if ($tokens[$i]['id'] === T_FUNCTION
            && $tokens[$i + 1]['id'] === T_STRING
            && $tokens[$i + 1]['text'] === 'run') {
            $signature = $i;
            break;
        }
    }

    if ($signature === null) {
        throw new RuntimeException('run() の宣言が見つからない');
    }

    // 引数リストを読み飛ばし、本体の `{` を見つける。
    $bodyStart = null;
    $depth = 0;
    for ($i = $signature; $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;
        } elseif ($text === '{' && $depth === 0) {
            $bodyStart = $i;
            break;
        }
    }

    if ($bodyStart === null) {
        throw new RuntimeException('run() の本体が見つからない');
    }

    $first = $tokens[$bodyStart + 1] ?? null;
    if ($first === null) {
        throw new RuntimeException('run() の本体が空である');
    }

    if ($first['id'] !== T_IF) {
        return ['first' => $first['text'], 'condition' => '', 'body' => ''];
    }

    // 条件式 (`if` の直後の括弧)
    $condition = '';
    $conditionEnd = null;
    $depth = 0;
    for ($i = $bodyStart + 2; $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '(') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                $conditionEnd = $i;
                break;
            }
        }
        $condition .= $text;
    }

    if ($conditionEnd === null) {
        throw new RuntimeException('if の条件式を読み取れない');
    }

    // 本体 (`{ … }`)
    $body = '';
    $depth = 0;
    for ($i = $conditionEnd + 1; $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if ($text === '{') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        $body .= $text.' ';
    }

    return ['first' => 'if', 'condition' => $condition, 'body' => $body];
}

/**
 * 前提テストの紐づけの違反一覧 (純関数 = 負のコントロールが同じ述語を通せる)。
 *
 * @param  class-string  $class  対象の seeder
 * @param  string|null  $premise  紐づけられた前提テストのパス (repo ルート相対)
 * @param  bool  $guardRequired  区分が環境ガードを要求するか
 * @return list<string> 違反一覧 (空 = 合格)
 */
function bughuntSeedPremiseViolations(string $class, ?string $premise, bool $guardRequired): array
{
    if (! $guardRequired) {
        return $premise === null ? [] : ["ガードを要求しない区分に前提テストが紐づいている: {$class}"];
    }

    if ($premise === null) {
        return ["前提テストが紐づいていない: {$class}"];
    }

    $violations = [];

    if (! str_starts_with($premise, 'tests/Feature/')) {
        $violations[] = "前提テストは tests/Feature/ 配下であること: {$premise}";
    }

    $path = base_path($premise);
    if (! is_file($path)) {
        return [...$violations, "前提テストが実在しない: {$premise}"];
    }

    $source = file_get_contents($path);
    if (! is_string($source)) {
        return [...$violations, "前提テストを読めない: {$premise}"];
    }

    if (! str_contains($source, bughuntSeedShortName($class))) {
        $violations[] = "前提テストが対象 seeder を参照していない: {$premise}";
    }

    return $violations;
}

test('S-1 目録のキー集合が database/seeders の Seeder クラス集合と過不足なく一致する', function (): void {
    $declared = bughuntSeedDeclaredSeederClasses();
    $registered = array_keys(BughuntSeedWiringInventory::entries());

    // 走査が壊れて「空母集団で緑」になるのを防ぐ (fail-closed)
    expect($declared)->not->toBeEmpty();

    sort($registered);

    expect($registered)->toBe($declared);
});

test('S-2 各 entry の理由が 30 文字以上である', function (): void {
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        expect(mb_strlen($entry['reason']))
            ->toBeGreaterThanOrEqual(30, "理由が短すぎる: {$class}");
    }
});

test('S-3 cmd_provision と cmd_reseed の投入列が順序込みで一致する', function (): void {
    $source = bughuntSeedShardSource();

    $provision = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision'));
    $reseed = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed'));

    // ★順序にも意味がある (ManualTestSeeder が先に走らないと BughuntOAuthSeeder は
    //   代表ユーザーを見つけられず skip する)。並べ替えたいときは 2 か所を同時に直すこと。
    expect($provision)->not->toBeEmpty()
        ->and($reseed)->toBe($provision);
});

test('S-4 投入列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する', function (): void {
    $sequence = bughuntSeedClassSequence(
        ShellFunctionWindow::ofCommand(bughuntSeedShardSource(), 'cmd_provision')
    );

    $expected = array_map(
        bughuntSeedShortName(...),
        BughuntSeedWiringInventory::seededInBughunt()
    );

    $actual = array_values(array_unique($sequence));
    sort($actual);
    sort($expected);

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected);
});

test('S-5 BughuntOnly 区分は DatabaseSeeder の呼び出し列に現れない', function (): void {
    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
    expect($source)->toBeString();
    /** @var string $source */
    $bughuntOnly = [];
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        if ($entry['role'] === BughuntSeedRole::BughuntOnly) {
            $bughuntOnly[] = bughuntSeedShortName($class);
        }
    }

    expect($bughuntOnly)->not->toBeEmpty();

    foreach ($bughuntOnly as $name) {
        // ★見るのは字面である (DatabaseSeeder のソースに名前が現れないこと)。
        //   通常経路へ載せる書き方は必ずクラス名を書くため、これで 3 つ目の事故を止められる。
        expect($source)->not->toContain($name);
    }
});

test('S-6 ガードを要求する区分は run() の最初の実効文が if で、判定語と早期 return を持つ', function (): void {
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        $markers = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']);
        if ($markers === []) {
            continue;
        }

        $file = (new ReflectionClass($class))->getFileName();
        expect($file)->toBeString();
        /** @var string $file */
        $source = file_get_contents($file);
        expect($source)->toBeString();
        /** @var string $source */
        $shape = bughuntSeedRunGuardShape($source);

        expect($shape['first'])->toBe('if', "{$class} の run() の最初の実効文が if でない");

        foreach ($markers as $marker) {
            // ★`toContain()` は可変長の needle を取るため、失敗メッセージは
            //   `toBeTrue()` 側へ渡す (第 2 引数を message と誤用しない)。
            expect(str_contains($shape['condition'], $marker))
                ->toBeTrue("{$class} のガード条件に {$marker} が無い");
        }

        expect(str_contains($shape['body'], 'return'))
            ->toBeTrue("{$class} のガードに早期 return が無い");
    }
});

test('S-7 fail-closed: 投入列も目録も空でないこと', function (): void {
    $source = bughuntSeedShardSource();

    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision')))->not->toBeEmpty()
        ->and(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed')))->not->toBeEmpty()
        ->and(BughuntSeedWiringInventory::entries())->not->toBeEmpty()
        ->and(BughuntSeedWiringInventory::seededInBughunt())->not->toBeEmpty();
});

test('S-8 負のコントロール: 投入列の欠落 / 並べ替え / ガードの後退を検出する', function (): void {
    // (a) reseed から 1 行落とす
    $dropped = <<<'SH'
    cmd_provision() {
        artisan_for_shard db:seed --class=ManualTestSeeder --force
        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
    }
    cmd_reseed() {
        artisan_for_shard db:seed --class=ManualTestSeeder --force
    }
    SH;
    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_reseed')))
        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_provision')));

    // (b) 並びを入れ替える
    $reordered = <<<'SH'
    cmd_provision() {
        artisan_for_shard db:seed --class=ManualTestSeeder --force
        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
    }
    cmd_reseed() {
        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
        artisan_for_shard db:seed --class=ManualTestSeeder --force
    }
    SH;
    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_reseed')))
        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_provision')));

    // (c) ガードの前に 1 文入れる
    $beforeGuard = "<?php\nclass X {\n public function run(): void {\n"
        ."  \$this->command->info('start');\n"
        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { return; }\n"
        ." }\n}\n";
    expect(bughuntSeedRunGuardShape($beforeGuard)['first'])->not->toBe('if');

    // (d) ガードの中に早期 return が無い
    $noReturn = "<?php\nclass X {\n public function run(): void {\n"
        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { \$this->command->warn('skip'); }\n"
        ." }\n}\n";
    $shape = bughuntSeedRunGuardShape($noReturn);
    expect($shape['first'])->toBe('if')
        ->and($shape['body'])->not->toContain('return');

    // (e) 正のコントロール: 判定語を落とすと条件照合が落ちる
    expect($shape['condition'])->not->toContain('isBughuntDatabase');
});

test('S-9 ガードを要求する区分は対象 seeder を参照する前提テストを持つ', function (): void {
    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
        $guardRequired = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']) !== [];

        expect(bughuntSeedPremiseViolations($class, $entry['guardPremiseTest'], $guardRequired))
            ->toBe([], "前提テストの紐づけが不正: {$class}");
    }
});

test('S-10 負のコントロール: 前提テストの差し替え・不在・区分違いを検出する', function (): void {
    // ★S-9 と**同じ述語**を通す (別の式で確かめると gate 本体の退行を映さない)。
    $class = BughuntOAuthSeeder::class;

    // (a) 実在するが対象 seeder を参照しない別のテストへ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/ManualTestSeederTest.php', true))
        ->not->toBe([]);

    // (b) 実在しないパスへ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/DoesNotExistTest.php', true))
        ->not->toBe([]);

    // (c) tests/Feature/ の外へ差し替える
    expect(bughuntSeedPremiseViolations($class, 'tests/Architecture/BughuntSeedWiringInvariantTest.php', true))
        ->not->toBe([]);

    // (d) ガードを要求する区分なのに紐づけを外す
    expect(bughuntSeedPremiseViolations($class, null, true))->not->toBe([]);

    // (e) ガードを要求しない区分に紐づけを足す
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', false))
        ->not->toBe([]);

    // (f) 正のコントロール: 正しい紐づけは違反 0 件 (同じ述語であることの確認)
    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', true))
        ->toBe([]);
});

test('S-11 ShellFunctionWindow は cmd_ 以外の名前と不在を例外にする', function (): void {
    $source = bughuntSeedShardSource();

    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'require_orchestrator'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'cmd_does_not_exist'))
        ->toThrow(RuntimeException::class);
});
