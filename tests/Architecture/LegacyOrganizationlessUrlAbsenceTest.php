<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\LegacyUrl\LegacyUrlAllowance;
use Tests\Support\LegacyUrl\LegacyUrlAllowanceKind;
use Tests\Support\LegacyUrl\LegacyUrlExtractionMode;
use Tests\Support\LegacyUrl\LegacyUrlScannedFile;
use Tests\Support\LegacyUrl\LegacyUrlScanner;
use Tests\Support\LegacyUrl\LegacyUrlScanRoots;

/*
 * 組織を持たない**旧 URL** と**撤去した route 名**が、走査できた範囲に 1 件も残っていない
 * (家系裁定 AG-037 / 施策 10)。
 *
 * ## なぜ必要か
 *
 * 単位 B で業務 route を `/organizations/{organization:slug}/…` 配下へ移し、
 * **旧 URL からの転送を置かない**判断をした (思考原則 3: 後方互換の並走を残さない)。
 * したがってリポジトリ内に旧 URL が 1 つでも残っていれば、それは**壊れた導線**である
 * (画面のリンクなら 404、文書なら誤った案内)。
 *
 * ## 台帳は 2 つ (同じ台帳にしない)
 *
 * route 名は施策 5 で**維持**したので、検出対象は「URL 文字列」と「撤去 route 名」に分かれる。
 * 前者は `LegacyUrlScanner::legacyRoots()`、後者は `LegacyUrlScanner::removedRouteName()` が正本。
 *
 * ## 母集団と 4 分類
 *
 * 母集団は git 追跡下ファイル**全数**で、`LegacyUrlScanRoots` が
 * 「走査する / 走査しない (理由付き) / 自己検査専用 / 未分類」へ**排他的に**分ける。
 * **未分類が 1 件でもあれば赤**である (新しい置き場所・拡張子が黙って走査から外れない)。
 *
 * ## 自己検出の閉じ方
 *
 * 本 gate も走査器も**旧 URL 文字列を持たない** (断片を連結して組み立てる)。
 * 検出語をわざと持つ見本だけが「自己検査専用」へ名指しで入り、
 * その件数は `LegacyUrlSelfCheckPopulationTest` が完全一致で pin する。
 *
 * ## 検出力の主張は次の範囲に**狭める** (誇張しない)
 *
 * 「1 件も無い」と言えるのは、**次の形で書かれた旧 URL**に限る。
 * ここに挙げた形は `Tests\Support\SourceLiterals` と `LegacyUrlScanner` の限界そのものであり、
 * **主張から明示的に除く** (走査器共通規約 (b): 明記した構文の検出力は主張しない)。
 *
 *  - **相対 path として 1 つのリテラル / 1 行に収まって書かれたもの**だけを見る。
 *    実行時に連結する形 (`'/dash'.$suffix` / `'/' + name` / `${base}/x`) は**見えない**。
 *  - **scheme と host を伴う絶対 URL は対象外**である (`https://example.com/dashboard`)。
 *    外部サービスの URL と自アプリの URL を字面で区別できないためで、
 *    host の後ろの path は根の位置と見なさない。
 *  - **query (`?`) や hash (`#`) の中に置いた path は見ない** (`?next=/billing`)。
 *    正規化した path を取り出す層は持たない。
 *  - script の抽出は言語の構文解析ではない (正規表現リテラルの判定は発見的規則)。
 *    誤読すると引用符の対応がずれ、**見逃す方向にも倒れうる**。
 *  - リポジトリの外 (利用者のブックマーク・送信済みメール・ブラウザ履歴) は対象外である。
 */

/** 走査対象の抽出方式が 5 規則とも生きている (走査根が壊れても気付ける)。 */
test('母集団は空でなく、規則ごとに 1 件以上のファイルがある', function (): void {
    $population = LegacyUrlScanRoots::population();

    expect($population->scanned)->not->toBeEmpty();

    $counts = $population->scannedCountByRule();
    foreach ([
        LegacyUrlScanner::RULE_PHP_LITERAL,
        LegacyUrlScanner::RULE_SCRIPT_LITERAL,
        LegacyUrlScanner::RULE_BLADE_TEXT,
        LegacyUrlScanner::RULE_MARKDOWN_TEXT,
        LegacyUrlScanner::RULE_DATA_TEXT,
    ] as $rule) {
        expect($counts[$rule] ?? 0)->toBeGreaterThan(0, "規則 {$rule} の母集団が空です");
    }
});

test('未分類の置き場所・形式は 0 件 (分類漏れは赤)', function (): void {
    expect(LegacyUrlScanRoots::population()->unclassified)->toBe([]);
});

test('解決できないファイルは 0 件 (読めないまま黙って落とさない)', function (): void {
    expect(LegacyUrlScanRoots::population()->unresolved)->toBe([]);
});

test('走査しない分類と許可目録の理由はいずれも 30 文字以上', function (): void {
    $short = [];
    foreach (LegacyUrlScanRoots::notScannedPathReasons() as $path => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "not-scanned path {$path}";
        }
    }
    foreach (LegacyUrlScanRoots::notScannedExtensionReasons() as $extension => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "not-scanned extension {$extension}";
        }
    }
    foreach (LegacyUrlScanRoots::selfCheckOnlyReasons() as $path => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "self-check-only {$path}";
        }
    }
    foreach (LegacyUrlAllowance::entries() as $entry) {
        if (mb_strlen($entry['reason']) < 30) {
            $short[] = "allowance {$entry['path']}";
        }
    }

    expect($short)->toBe([]);
});

/**
 * 許可した出現の path 全体を、目録のキーごとに集める。
 *
 * @return array<string, list<string>>
 */
function legacyUrlObservedPathsByKey(): array
{
    $paths = [];
    foreach (LegacyUrlScanRoots::population()->scanned as $file) {
        foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
            $key = LegacyUrlAllowance::keyOf(
                $occurrence->relative, $occurrence->ruleId, $occurrence->matched, $occurrence->context,
            );
            $paths[$key][] = $occurrence->path;
        }
    }

    return $paths;
}

/** route 表の `capture.entry` の URI (先頭スラッシュつき)。 */
function legacyUrlCaptureEntryUri(): string
{
    $routes = Route::getRoutes();
    $routes->refreshNameLookups();

    return '/'.ltrim((string) $routes->getByName('capture.entry')?->uri(), '/');
}

test('許可目録の区分ごとの前提がすべて満たされている (区分を判定に使う)', function (): void {
    $captureEntryUri = legacyUrlCaptureEntryUri();
    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
    $observed = legacyUrlObservedPathsByKey();

    $violations = [];
    foreach (LegacyUrlAllowance::entries() as $entry) {
        $key = LegacyUrlAllowance::keyOf($entry['path'], $entry['rule'], $entry['matched'], $entry['context']);
        $violation = LegacyUrlAllowance::preconditionViolation(
            $entry,
            $repositoryRoot,
            $captureEntryUri,
            $observed[$key] ?? [],
        );
        if ($violation !== null) {
            $violations[] = "{$entry['path']} [{$entry['kind']->value}]: {$violation}";
        }
    }

    sort($violations);
    expect($violations)->toBe([]);
});

test('負例: 区分ごとの前提は成立しない入力を拒否する (5 区分の両方向)', function (): void {
    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
    $entryUri = LegacyUrlScanner::captureRoot();
    $subPath = $entryUri.'/'.LegacyUrlScanner::organizationSegment();

    $make = static fn (LegacyUrlAllowanceKind $kind, string $path, ?string $consumer = null, ?string $symbol = null): array => [
        'path' => $path,
        'rule' => LegacyUrlScanner::RULE_PHP_LITERAL,
        'matched' => LegacyUrlScanner::captureRoot(),
        'context' => LegacyUrlScanner::CONTEXT_EXPRESSION,
        'count' => 1,
        'kind' => $kind,
        'consumer' => $consumer,
        'symbol' => $symbol,
        'reason' => '負例のための合成登録であり、前提が成立しないことを確かめるためだけに使う',
    ];
    $check = static fn (array $entry, array $paths): ?string => LegacyUrlAllowance::preconditionViolation(
        $entry, $repositoryRoot, $entryUri, $paths,
    );

    // 出現が 1 件も無い登録は、どの区分でも拒否する
    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), []))->not->toBeNull();

    // CanonicalCaptureEntry: 入口そのものは通り、配下つきは落ちる
    expect($check($make(LegacyUrlAllowanceKind::CanonicalCaptureEntry, 'composer.json'), [$entryUri]))->toBeNull();
    expect($check($make(LegacyUrlAllowanceKind::CanonicalCaptureEntry, 'composer.json'), [$subPath]))->not->toBeNull();

    // FilesystemPath: 実在するディレクトリは通り、実在しないパスは落ちる
    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), [$entryUri]))->toBeNull();
    expect($check($make(LegacyUrlAllowanceKind::FilesystemPath, 'composer.json'), [$subPath]))->not->toBeNull();

    // StorageObjectKey: 鍵の印を持つファイルは通り、持たないファイルは落ちる
    expect($check($make(LegacyUrlAllowanceKind::StorageObjectKey, 'doc/09_詳細実装設計.md'), [$entryUri]))->toBeNull();
    expect($check($make(LegacyUrlAllowanceKind::StorageObjectKey, 'composer.json'), [$entryUri]))->not->toBeNull();

    // AbsenceAssertion: 撤去の語を持つファイルは通り、持たないファイルは落ちる
    expect($check($make(LegacyUrlAllowanceKind::AbsenceAssertion, 'docs/architecture.md'), [$entryUri]))->toBeNull();
    expect($check($make(LegacyUrlAllowanceKind::AbsenceAssertion, 'composer.json'), [$entryUri]))->not->toBeNull();

    // OrganizationRelativePath: 利用側と記号の両方が要る
    expect($check(
        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', 'resources/js/pages/Dashboard.svelte', 'BILLING_CALLOUTS'),
        [$entryUri],
    ))->toBeNull();
    expect($check(
        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', 'resources/js/pages/Dashboard.svelte', 'NoSuchSymbolInConsumer'),
        [$entryUri],
    ))->not->toBeNull();
    expect($check(
        $make(LegacyUrlAllowanceKind::OrganizationRelativePath, 'composer.json', null, null),
        [$entryUri],
    ))->not->toBeNull();
});

test('旧 URL と撤去 route 名は許可目録に登録したものを除いて 0 件', function (): void {
    $allowed = LegacyUrlAllowance::counts();
    $observed = [];
    $violations = [];

    foreach (LegacyUrlScanRoots::population()->scanned as $file) {
        foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
            $key = LegacyUrlAllowance::keyOf(
                $occurrence->relative, $occurrence->ruleId, $occurrence->matched, $occurrence->context,
            );
            $observed[$key] = ($observed[$key] ?? 0) + 1;
            if (! array_key_exists($key, $allowed)) {
                $violations[] = $occurrence->describe();
            }
        }
    }

    sort($violations);
    expect($violations)->toBe([]);

    // ★件数は完全一致 (増えても減っても赤 / 登録が実在しなくなっても赤)
    $mismatched = [];
    foreach ($allowed as $key => $count) {
        [$path, $rule, $matched, $context] = explode("\0", $key);
        $actual = $observed[$key] ?? 0;
        if ($actual !== $count) {
            $mismatched[] = "{$path} [{$rule}/{$context}] {$matched} 登録 {$count} 件 / 実測 {$actual} 件";
        }
    }
    expect($mismatched)->toBe([]);
});

test('負例: 種別ごとに見本の旧 URL を検出できる (検出力の裏取り)', function (
    string $fixture,
    LegacyUrlExtractionMode $mode,
    string $rule,
    string $relative,
    int $expected,
): void {
    $file = new LegacyUrlScannedFile(
        relative: $relative,
        contents: (string) file_get_contents(base_path('tests/Architecture/fixtures/legacy-url/'.$fixture)),
        mode: $mode,
        ruleId: $rule,
    );

    expect(LegacyUrlScanner::scanFile($file))->toHaveCount($expected);
})->with([
    // Markdown: 旧パス 11 件 + 撤去 route 名 1 件
    'markdown' => ['legacy-paths.md', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT, 'fixture.md', 13],
    // Markdown の負例: 新 URL・接頭辞/打ち消し/接尾辞・絶対 URL は 1 件も拾わない
    'markdown-negative' => ['allowed-paths.md', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT, 'fixture.md', 0],
    // PHP: リテラルの旧パス 2 件 (コメントは数えない) + 撤去 route 名 1 件
    'php' => ['legacy-php-source.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_PHP_LITERAL, 'fixture.php', 3],
    // script: 入口の引数・組織 prefix・コメント・正規表現リテラルを除いた 5 件
    // (直書き 1 / 接頭辞つき偽入口 2 / コメントの偽入口の次行 1 / メンバ呼びの偽入口 1)
    'script' => ['legacy-script-source.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 5],
    // script: 入口の module を取り込まずに同名関数を自前定義しても免除にならない
    'script-shadowed' => ['legacy-shadowed-builder.txt', LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL, 'fixture.ts', 1],
    // JSON: 値の旧パス 1 件 + 1 行に 2 個の撤去 route 名 (件数で数える)
    'data' => ['legacy-data-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT, 'fixture.json', 3],
    // Blade: 属性値の旧パス 1 件 (route helper 経由と組織 prefix つきは数えない)
    'blade' => ['legacy-blade-source.txt', LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT, 'fixture.blade.php', 1],
]);

test('種別ごとの割り当て: 拡張子は宣言した抽出方式と規則 ID へ 1:1 で写る', function (): void {
    // ★「どの種別をどう抽出するか」は分類表が唯一の正本である。ここが壊れると
    //   Blade / JSON / TOML が黙って別の方式で読まれ、検出力の裏取りが意味を失う。
    $expected = [
        'resources/views/app.blade.php' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT],
        'app/Models/User.php' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_PHP_LITERAL],
        'resources/js/lib/org-url.ts' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'resources/js/pages/Dashboard.svelte' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'docs/architecture.md' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT],
        'public/manifest.webmanifest' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
        '.claude/skills/app-bug-hunt/inventory/annotations.toml' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
    ];

    foreach ($expected as $path => [$mode, $rule]) {
        $classification = LegacyUrlScanRoots::classify($path);
        expect($classification)->not->toBeNull("{$path} が未分類です");
        expect($classification['mode'])->toBe($mode, "{$path} の抽出方式が宣言と違います");
        expect($classification['rule'])->toBe($rule, "{$path} の規則 ID が宣言と違います");
    }
});

test('負例: routes/ では撤去 route 名が名前づけの引数でも検出される', function (): void {
    // ★`->name()` を除外集合へ入れると、撤去 route 名の台帳が routes/ の中で丸ごと効かなくなる。
    $source = "<?php\nRoute::get('/x', H::class)->name('".LegacyUrlScanner::removedRouteName()."');\n";

    $file = new LegacyUrlScannedFile(
        relative: 'routes/web.php',
        contents: $source,
        mode: LegacyUrlExtractionMode::SourceLiteral,
        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
    );

    expect(array_map(
        static fn (object $occurrence): string => (string) $occurrence->ruleId,
        LegacyUrlScanner::scanFile($file),
    ))->toBe([LegacyUrlScanner::RULE_REMOVED_ROUTE_NAME]);
});

test('負例: 同じ path でも構文位置を移すと許可のキーが変わる (構文文脈を判定に使う)', function (): void {
    // ★指摘された迂回 (manifest の start_url を別の鍵へ移す / 呼び出しの引数を別の呼びへ移す)。
    //   構文文脈がキーに入っているので、path と件数が同じでも別のキーになり未登録として赤くなる。
    $entry = LegacyUrlScanner::captureRoot();

    expect(LegacyUrlScanner::plainTextContext('  "start_url": "'.$entry.'"', 15))
        ->toBe('key:start_url');
    expect(LegacyUrlScanner::plainTextContext('  "unrelated": "'.$entry.'"', 16))
        ->not->toBe('key:start_url');

    $masked = "\$this->get('".$entry."');";
    expect(LegacyUrlScanner::sourceLiteralContext($masked, (int) strpos($masked, "'")))
        ->toBe('call:get');
    $moved = "\$unused = '".$entry."';";
    expect(LegacyUrlScanner::sourceLiteralContext($moved, (int) strpos($moved, "'")))
        ->toBe(LegacyUrlScanner::CONTEXT_EXPRESSION);

    // 文脈が違えば目録のキーも違う (件数が同じでも通らない)
    expect(LegacyUrlAllowance::keyOf('x', LegacyUrlScanner::RULE_DATA_TEXT, $entry, 'key:start_url'))
        ->not->toBe(LegacyUrlAllowance::keyOf('x', LegacyUrlScanner::RULE_DATA_TEXT, $entry, 'key:unrelated'));
});

test('負例: 入口の取り込みは「取り込み元の名前」と「文字列の外」の両方を要求する', function (): void {
    $roots = LegacyUrlScanner::legacyRoots();
    $legacy = $roots[5];

    $scan = static fn (string $source): int => count(LegacyUrlScanner::scanFile(new LegacyUrlScannedFile(
        relative: 'fixture.ts',
        contents: $source,
        mode: LegacyUrlExtractionMode::SourceLiteral,
        ruleId: LegacyUrlScanner::RULE_SCRIPT_LITERAL,
    )));

    $module = LegacyUrlScanner::ORGANIZATION_URL_MODULE;
    $builder = LegacyUrlScanner::ORGANIZATION_URL_BUILDERS[0];

    // 正例: 取り込み元が入口の名前なら (別名つきでも) 免除される
    expect($scan("import {{$builder} as u} from \"{$module}\";\nconst ok = u(slug, \"{$legacy}\");\n"))->toBe(0);
    // 負例 1: 同じ module の別の export を別名で取り込んでも入口にはならない
    expect($scan("import {currentOrganizationSlug as u} from \"{$module}\";\nconst t = u(\"{$legacy}\");\n"))->toBe(1);
    // 負例 2: 文字列の中に書いた偽の import 宣言では前提を満たせない
    expect($scan("const e = 'import {{$builder}} from \"{$module}\"';\nconst t = {$builder}(slug, \"{$legacy}\");\n"))->toBe(1);
});

test('負例: 未知の拡張子は未分類として落ちる (fail-closed)', function (): void {
    expect(LegacyUrlScanRoots::classify('resources/js/app.unknownext'))->toBeNull();
    expect(LegacyUrlScanRoots::classify('app/Models/User.php'))->not->toBeNull();
});

test('負例: 走査対象に分類したのに読めない内容は未解決になる (fail-closed)', function (): void {
    // ★母集団の unresolved が 0 件であることは「異常入力を未解決へ送れる」ことの裏取りにならない。
    //   判定を純関数へ切り出してあるので、合成した内容で両方向を固定する。
    expect(LegacyUrlScanRoots::contentsUnresolvedReason("ふつうの本文\n"))->toBeNull();
    expect(LegacyUrlScanRoots::contentsUnresolvedReason("bin\0ary"))->not->toBeNull();
    expect(LegacyUrlScanRoots::contentsUnresolvedReason("\xff\xfe invalid"))->not->toBeNull();
});

test('負例: symlink の解決は壊れている / リポジトリ外を未解決にする (fail-closed)', function (): void {
    $repositoryRoot = LegacyUrlScanRoots::repositoryRoot();
    $directory = sys_get_temp_dir().'/legacy-url-symlink-'.bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        // 通常ファイルは symlink ではないので理由なし
        $plain = $directory.'/plain.txt';
        file_put_contents($plain, 'x');
        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $plain))->toBeNull();

        // 壊れた symlink
        $broken = $directory.'/broken';
        symlink($directory.'/does-not-exist', $broken);
        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $broken))->not->toBeNull();

        // リポジトリ外へ解決される symlink
        $outside = $directory.'/outside';
        symlink($plain, $outside);
        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $outside))->not->toBeNull();

        // リポジトリ内へ解決される symlink は理由なし
        $inside = $directory.'/inside';
        symlink($repositoryRoot.'/composer.json', $inside);
        expect(LegacyUrlScanRoots::symlinkUnresolvedReason($repositoryRoot, $inside))->toBeNull();
    } finally {
        foreach (['broken', 'outside', 'inside'] as $link) {
            @unlink($directory.'/'.$link);
        }
        @unlink($directory.'/plain.txt');
        @rmdir($directory);
    }
});

test('負例: routes/ は route 定義の URI だけを外し、他のリテラルは検出する', function (): void {
    // ★ファイルごと外すと、そこが旧 URL の抜け道になる。外すのは URI 引数 1 つだけである。
    // ★合成入力の旧 URL は**走査器が組み立てた根**から作る (この gate 自身が旧 URL 文字列を持たない)。
    $roots = LegacyUrlScanner::legacyRoots();
    $definitionUri = $roots[4];   // route 定義の URI 引数 (外れる側)
    $inlineRedirect = $roots[5];  // route 定義以外のリテラル (残る側)
    $source = "<?php\n"
        ."Route::prefix('/x')->group(function (): void {\n"
        ."    Route::get('{$definitionUri}', DashboardController::class)->name('x');\n"
        ."    Route::get('/legacy', fn () => redirect('{$inlineRedirect}'));\n"
        ."});\n";

    $file = new LegacyUrlScannedFile(
        relative: 'routes/web.php',
        contents: $source,
        mode: LegacyUrlExtractionMode::SourceLiteral,
        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
    );

    $matched = array_map(
        static fn (object $occurrence): string => (string) $occurrence->matched,
        LegacyUrlScanner::scanFile($file),
    );

    // route 定義の URI は外れ、redirect の直書きだけが残る
    expect($matched)->toBe([$inlineRedirect]);
});
