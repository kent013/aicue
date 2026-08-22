<?php

declare(strict_types=1);

use Illuminate\Auth\Middleware\RequirePassword;
use Tests\Support\SurfaceRemoval\ContentClassification;
use Tests\Support\SurfaceRemoval\MiddlewareReference;
use Tests\Support\SurfaceRemoval\MiddlewareReferenceKind;
use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
use Tests\Support\SurfaceRemoval\RemovedTerm;
use Tests\Support\SurfaceRemoval\ScannedFile;
use Tests\Support\SurfaceRemoval\ScanOutcome;
use Tests\Support\SurfaceRemoval\ScanPopulation;
use Tests\Support\SurfaceRemoval\TermMatchMode;

/*
 * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
 * **参照の再流入**を字句で止める gate (家系正典 surface-removal-absence-gate v1 の静的層)。
 *
 * ★走査対象: `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets` の走査根 8 本
 *   (`.github` / `app` / `bootstrap` / `config` / `lang` / `resources` / `routes` / `scripts`) の
 *   git 追跡下の全ファイル。`database/migrations` は含めない (理由は同クラスの docblock)。
 * ★検出対象は「撤去した middleware の**適用・登録を表す構文**」であり、
 *   文字列 `password.confirm` の全出現ではない。したがって `config/seo.php` の
 *   route 名対応表 (`app_titles`) は**母集団に入らず**、除外規則を持たない。
 *   **許可一覧は 0 個**である。
 * ★middleware 位置の定義 (M1〜M3) は
 *   `RemovedSurfaceScanner::scanMiddlewarePositions()` の docblock が正本。
 *
 * ★**保証しないもの (検出力を誇張しない)**:
 *   - 列挙した middleware 位置 (M1〜M3) の**外**は**静的層の保証外**である。
 *     実行時層 (`PasswordConfirmMiddlewareAbsenceTest`。解決済み middleware の全数走査、
 *     deny-by-default) が**テスト起動時に実体化した全 route について補完する**が、
 *     **環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは保証しない**。
 *   - middleware 位置の**変数・式** (`->middleware($alias)` /
 *     `->middleware('throttle:'.$limiter)`) はクラス参照でも文字列リテラルでもないため
 *     母集団に入らない。これは免除ではなく**規則段階の定義**であり、
 *     見本 `negative-dynamic-middleware-value.php.txt` が「沈黙すること」を固定している。
 *   - 分割連結・定数経由・動的組み立て・PHP のコメント内には沈黙する。
 *   - NUL を含むファイルは母集団に入らない (ただし `binaryExcluded === []` を要求する)。
 * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
 *   (見本: `tests/Architecture/fixtures/surface-removal/password-confirm/` と
 *   `tests/Architecture/fixtures/surface-removal/content/`)。
 */

/** 撤去した alias 名 (一致様式つき)。 */
function passwordConfirmRemovedTerm(): RemovedTerm
{
    return new RemovedTerm('password.confirm', TermMatchMode::ExactRun);
}

/**
 * 撤去した実体クラスの完全修飾名 (一致様式つき)。
 *
 * ★middleware は**クラス名の文字列**でも指定できる (`->middleware('Illuminate\\…\\RequirePassword')`)。
 *   また拡張子なしの PHP スクリプト・シェル・YAML など「PHP として扱わないファイル」からも
 *   実行可能な参照になり得るので、Tier 2 でもこの様式で 0 件固定する。
 */
function passwordConfirmRemovedClassTerm(): RemovedTerm
{
    return new RemovedTerm(RequirePassword::class, TermMatchMode::FqcnReference);
}

/** 実走査母集団 (プロセス内で 1 度だけ確定する)。 */
function passwordConfirmScanPopulation(): ScanPopulation
{
    return RemovedSurfaceScanTargets::population();
}

/** 見本ディレクトリ。 */
function passwordConfirmFixtureDirectory(): string
{
    return __DIR__.'/fixtures/surface-removal/password-confirm';
}

/**
 * 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**。拡張子から推定しない)。
 */
function passwordConfirmFixtureFile(string $name, bool $isPhp): ScannedFile
{
    $path = passwordConfirmFixtureDirectory().'/'.$name;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("見本を読めません: {$name}");
    }

    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
}

/**
 * 撤去した機構への参照を 3 つの検出対象へ分けて返す。
 *
 * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
 *
 * @param  list<ScannedFile>  $files
 * @return array{aliases: list<string>, classes: list<string>, texts: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
 */
function passwordConfirmSurfaceFindings(array $files): array
{
    $term = passwordConfirmRemovedTerm();
    $classTerm = passwordConfirmRemovedClassTerm();
    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));

    $middleware = RemovedSurfaceScanner::scanMiddlewarePositions($files);
    $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
    $fqcnText = RemovedSurfaceScanner::scanText($nonPhp, $classTerm);

    $aliases = [];
    $classes = [];
    /** @var array<string, string> $unresolved */
    $unresolved = [];
    foreach ($middleware->occurrences as $reference) {
        if (! $reference instanceof MiddlewareReference) {
            continue;
        }
        if ($reference->kind === MiddlewareReferenceKind::AliasString) {
            // D1: alias 文字列 (`password.confirm` / `password.confirm:web`)。
            //     判定は走査器と同じ 1 本のトークン一致を通す
            if (RemovedSurfaceScanner::textMatches($reference->value, $term)) {
                $aliases[] = $reference->describe();

                continue;
            }
            // D2b: middleware は**クラス名の文字列**でも指定できる。
            //      解決済みクラス参照と同じ扱いにする (`Illuminate\…\RequirePassword`)
            if (RemovedSurfaceScanner::textMatches($reference->value, $classTerm)) {
                $classes[] = $reference->describe();
            }

            continue;
        }
        // ★「解決済みのはず」を型で守れないので、null は**非該当ではなく未解決**にする
        //   (将来の退行が黙って通り抜ける fail-open を作らない)
        if ($reference->resolvedFqcn === null) {
            $unresolved[$reference->relative] = sprintf(
                'middleware 位置のクラス参照が解決済み完全修飾名を持たない (行 %d)',
                $reference->line,
            );

            continue;
        }
        // D2: 完全修飾名が撤去した実体クラスへ解決されるもの
        if (strtolower($reference->resolvedFqcn) === strtolower(RequirePassword::class)) {
            $classes[] = $reference->describe();
        }
    }

    return [
        'aliases' => $aliases,
        'classes' => $classes,
        'texts' => $text->descriptions(),          // D3: 非 PHP の生テキストの alias
        'fqcnTexts' => $fqcnText->descriptions(),  // D4: 非 PHP の生テキストの完全修飾クラス名
        'unresolved' => [
            ...ScanOutcome::mergeUnresolved([$middleware, $text, $fqcnText]),
            ...array_map(
                static fn (string $relative, string $reason): string => $relative.': '.$reason,
                array_keys($unresolved),
                array_values($unresolved),
            ),
        ],
    ];
}

/**
 * 見本の正例 (検出経路と、見本が壊れて空振りしないための**経路別の前提検査**)。
 *
 * ★一律の `str_contains($contents, $term)` は使わない — 大小違いの正例は canonical 表記を
 *   含まず、alias / group use の正例は参照位置に完全修飾名を持たないため成立しない。
 *
 * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
 */
function passwordConfirmPositiveFixtures(): array
{
    return [
        ['file' => 'positive-middleware-array.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
        ['file' => 'positive-middleware-arg.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
        ['file' => 'positive-middleware-param.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm:', 'middleware(']],
        ['file' => 'positive-middleware-class.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
        ['file' => 'positive-middleware-class-fqcn.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
        ['file' => 'positive-middleware-class-alias.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', ' as ']],
        ['file' => 'positive-middleware-class-groupuse.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', '{']],
        ['file' => 'positive-middleware-class-relative.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'namespace']],
        ['file' => 'positive-middleware-class-case.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
        ['file' => 'positive-config-management-middleware.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'management_middleware']],
        ['file' => 'positive-kernel-property.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', '$middlewareGroups']],
        ['file' => 'positive-alias-registration.php.txt', 'php' => true, 'buckets' => ['aliases', 'classes'], 'requires' => ['password.confirm', 'requirepassword', 'alias(']],
        ['file' => 'positive-middleware-class-string.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword', 'middleware(']],
        ['file' => 'positive-middleware-class-string-escaped.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', 'middleware(']],
        ['file' => 'positive-middleware-without.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'withoutMiddleware(']],
        ['file' => 'positive-middleware-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middlewareGroup(']],
        ['file' => 'positive-middleware-append-to-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'appendToGroup(']],
        ['file' => 'positive-middleware-prepend-to-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'prependToGroup(']],
        ['file' => 'positive-kernel-property-middleware.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', '$middleware ']],
        ['file' => 'positive-kernel-property-priority.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '$middlewarePriority']],
        ['file' => 'positive-multiple-namespaces.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', 'namespace app\\second']],
        ['file' => 'positive-fqcn-noext.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
        ['file' => 'positive-fqcn-shell.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
        ['file' => 'positive-fqcn-workflow.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
        ['file' => 'positive-css-id-selector.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-css-universal.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-ts-generator.ts.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-svelte-markup.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-svelte-script.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-svelte-style.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-shell.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-noext.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
        ['file' => 'positive-workflow.yaml.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
    ];
}

/**
 * 見本の負例 (反応してはならない。未解決にもならない)。
 *
 * @return list<array{file: string, php: bool}>
 */
function passwordConfirmNegativeFixtures(): array
{
    return [
        ['file' => 'negative-seo-title-map.php.txt', 'php' => true],
        ['file' => 'negative-route-name-usage.php.txt', 'php' => true],
        ['file' => 'negative-suffix.php.txt', 'php' => true],
        ['file' => 'negative-prefix.php.txt', 'php' => true],
        ['file' => 'negative-negated.php.txt', 'php' => true],
        ['file' => 'negative-session-key.php.txt', 'php' => true],
        ['file' => 'negative-php-comment.php.txt', 'php' => true],
        ['file' => 'negative-other-middleware-class.php.txt', 'php' => true],
        ['file' => 'negative-dynamic-middleware-value.php.txt', 'php' => true],
        ['file' => 'negative-multiple-namespaces.php.txt', 'php' => true],
        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
        ['file' => 'negative-fqcn-suffix.sh.txt', 'php' => false],
        ['file' => 'negative-fqcn-bare-shortname.sh.txt', 'php' => false],
    ];
}

test('走査根がすべて解決でき、実走査母集団が空でない', function (): void {
    $population = passwordConfirmScanPopulation();

    expect(RemovedSurfaceScanTargets::roots())->toHaveCount(8);

    foreach (array_keys(RemovedSurfaceScanTargets::roots()) as $root) {
        expect(count($population->inRoot($root)))->toBeGreaterThan(0, "走査根 {$root} の母集団が空");
    }

    expect(count($population->php()))->toBeGreaterThan(0);
    expect(count($population->nonPhp()))->toBeGreaterThan(0);
});

test('各走査根に代表パスが含まれる', function (): void {
    $paths = passwordConfirmScanPopulation()->relativePaths();

    foreach (RemovedSurfaceScanTargets::REPRESENTATIVE_PATHS as $root => $representative) {
        expect(in_array($representative, $paths, true))
            ->toBeTrue("走査根 {$root} の代表パス {$representative} が母集団に無い");
    }
});

test('scripts と .github の実走査母集団に期待する種別が含まれる', function (): void {
    $population = passwordConfirmScanPopulation();

    $scripts = $population->inRoot('scripts');
    $withoutExtension = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === null);
    $shell = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === 'sh');

    expect(count($withoutExtension))->toBeGreaterThan(0, 'scripts/ に拡張子なしの実行ファイルが 1 件も無い');
    expect(count($shell))->toBeGreaterThan(0, 'scripts/ に .sh が 1 件も無い');

    $workflows = array_filter(
        $population->inRoot('.github'),
        static fn (ScannedFile $f): bool => str_starts_with($f->relative, '.github/workflows/')
            && in_array($f->extension, ['yml', 'yaml'], true),
    );
    expect(count($workflows))->toBeGreaterThan(0, '.github/workflows/ に YAML が 1 件も無い');
});

test('母集団に未解決もバイナリ除外も無い', function (): void {
    $population = passwordConfirmScanPopulation();

    expect($population->unresolved)->toBe([]);
    // ★NUL を 1 つ入れて静的層を迂回する経路を塞ぐ
    expect($population->binaryExcluded)->toBe([]);
});

test('middleware 位置に password.confirm alias が 1 件も無い', function (): void {
    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);

    expect($findings['aliases'])->toBe(
        [],
        'password.confirm alias の再流入: '.implode(', ', $findings['aliases']),
    );
});

test('middleware 位置に RequirePassword の参照が 1 件も無い', function (): void {
    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);

    expect($findings['classes'])->toBe(
        [],
        'RequirePassword の再流入: '.implode(', ', $findings['classes']),
    );
});

test('非 PHP に password.confirm が 1 件も無い', function (): void {
    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);

    expect($findings['texts'])->toBe(
        [],
        '非 PHP への password.confirm 残留: '.implode(', ', $findings['texts']),
    );
});

test('非 PHP に RequirePassword の完全修飾参照が 1 件も無い', function (): void {
    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);

    expect($findings['fqcnTexts'])->toBe(
        [],
        '非 PHP への RequirePassword 完全修飾参照の残留: '.implode(', ', $findings['fqcnTexts']),
    );
});

test('走査で未解決が 1 件も出ていない', function (): void {
    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);

    expect($findings['unresolved'])->toBe(
        [],
        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
    );
});

test('検出器の自己検証: 正例をすべて検出する', function (): void {
    foreach (passwordConfirmPositiveFixtures() as $fixture) {
        $file = passwordConfirmFixtureFile($fixture['file'], $fixture['php']);

        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
        foreach ($fixture['requires'] as $needle) {
            expect(str_contains(strtolower($file->contents), strtolower($needle)))
                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
        }

        $findings = passwordConfirmSurfaceFindings([$file]);
        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");

        foreach ($fixture['buckets'] as $bucket) {
            expect(count($findings[$bucket]))
                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
        }
    }
});

test('検出器の自己検証: 負例に反応しない', function (): void {
    foreach (passwordConfirmNegativeFixtures() as $fixture) {
        $name = $fixture['file'];
        $findings = passwordConfirmSurfaceFindings([passwordConfirmFixtureFile($name, $fixture['php'])]);

        expect($findings['aliases'])->toBe([], "負例 {$name} に alias で反応した");
        expect($findings['classes'])->toBe([], "負例 {$name} に class で反応した");
        expect($findings['texts'])->toBe([], "負例 {$name} に text で反応した");
        expect($findings['fqcnTexts'])->toBe([], "負例 {$name} に fqcn で反応した");
        expect($findings['unresolved'])->toBe([], "負例 {$name} が未解決になった");
    }
});

test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
    $names = [
        'negative-same-shortname-import.php.txt',
        'negative-same-shortname-fqcn.php.txt',
        'negative-alias-to-target-shortname.php.txt',
    ];

    foreach ($names as $name) {
        $file = passwordConfirmFixtureFile($name, true);
        // 短名一致へ退行したら赤くなる見本であること (前提検査)
        expect(str_contains($file->contents, 'RequirePassword'))->toBeTrue();

        $findings = passwordConfirmSurfaceFindings([$file]);
        expect($findings['classes'])->toBe([], "同じ短名の別クラス {$name} に反応した");
        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$name} が未解決になった");
    }
});

test('検出器の自己検証: 解決できない middleware クラス参照は未解決になる', function (): void {
    $findings = passwordConfirmSurfaceFindings([
        passwordConfirmFixtureFile('unresolved-dynamic-middleware-class.php.txt', true),
    ]);

    expect(count($findings['unresolved']))->toBeGreaterThan(0);
});

test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
    $findings = passwordConfirmSurfaceFindings([
        passwordConfirmFixtureFile('unresolved-broken-php.php.txt', true),
    ]);

    expect(count($findings['unresolved']))->toBeGreaterThan(0);
});

test('検出器の自己検証: 内容分類が効く', function (): void {
    $directory = __DIR__.'/fixtures/surface-removal/content';

    $decode = static function (string $name) use ($directory): string {
        $hex = file_get_contents($directory.'/'.$name);
        if ($hex === false) {
            throw new RuntimeException("見本を読めません: {$name}");
        }
        $bytes = @hex2bin((string) preg_replace('/\s+/', '', $hex));
        if ($bytes === false) {
            throw new RuntimeException("見本の hex を復号できません (見本の破損): {$name}");
        }

        return $bytes;
    };

    $plain = file_get_contents($directory.'/text-plain.txt');
    expect($plain)->toBeString();

    // ★population() と**同じ関数**を通す (自己検証と実母集団の経路が切れないこと)
    expect(RemovedSurfaceScanTargets::classifyContents($decode('binary-with-nul.hex.txt')))
        ->toBe(ContentClassification::Binary);
    expect(RemovedSurfaceScanTargets::classifyContents($decode('invalid-utf8.hex.txt')))
        ->toBe(ContentClassification::InvalidUtf8);
    expect(RemovedSurfaceScanTargets::classifyContents((string) $plain))
        ->toBe(ContentClassification::Text);
});

test('検出器の自己検証: リポジトリ内外の判定が効く', function (): void {
    $root = '/repo';

    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo/app/X.php'))->toBeTrue();
    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/elsewhere/X.php'))->toBeFalse();
    // 接頭辞が偶然一致するだけのパスは配下ではない
    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo-other/X.php'))->toBeFalse();
});

test('検出器の自己検証: 取り込み表が namespace 区間を跨いで漏れない', function (): void {
    // 1 ファイル内の 2 namespace。撤去クラスを取り込んだのは 2 つ目だけなので**ちょうど 1 件**
    $findings = passwordConfirmSurfaceFindings([
        passwordConfirmFixtureFile('positive-multiple-namespaces.php.txt', true),
    ]);

    expect($findings['classes'])->toHaveCount(1);
    expect($findings['unresolved'])->toBe([]);

    // 逆向き: どちらの namespace も別クラスなので 0 件
    $negative = passwordConfirmSurfaceFindings([
        passwordConfirmFixtureFile('negative-multiple-namespaces.php.txt', true),
    ]);

    expect($negative['classes'])->toBe([]);
    expect($negative['unresolved'])->toBe([]);
});

test('検出器の自己検証: symlink の解決判定が効く', function (): void {
    $root = sys_get_temp_dir().'/surface-removal-symlink-'.bin2hex(random_bytes(6));
    $outside = sys_get_temp_dir().'/surface-removal-outside-'.bin2hex(random_bytes(6));

    mkdir($root, 0o700, true);
    mkdir($outside, 0o700, true);

    try {
        file_put_contents($root.'/real.txt', 'x');
        file_put_contents($outside.'/target.txt', 'x');
        symlink($root.'/real.txt', $root.'/inside.link');
        symlink($outside.'/target.txt', $root.'/outside.link');
        symlink($root.'/missing.txt', $root.'/broken.link');

        // symlink でない実ファイルは理由を持たない
        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/real.txt'))->toBeNull();
        // リポジトリ配下へ解決される symlink も理由を持たない
        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/inside.link'))->toBeNull();
        // 外へ出る symlink と壊れた symlink は理由を返す (population() は unresolved へ入れる)
        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/outside.link'))->toBeString();
        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/broken.link'))->toBeString();
    } finally {
        foreach (['inside.link', 'outside.link', 'broken.link', 'real.txt'] as $name) {
            @unlink($root.'/'.$name);
        }
        @unlink($outside.'/target.txt');
        @rmdir($root);
        @rmdir($outside);
    }
});
