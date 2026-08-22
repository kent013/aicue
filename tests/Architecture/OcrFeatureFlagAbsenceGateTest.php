<?php

declare(strict_types=1);

use App\Support\Manual\AcceptedSourceDocumentTypes;
use Illuminate\Support\Arr;
use Tests\Support\SurfaceRemoval\MethodReference;
use Tests\Support\SurfaceRemoval\MiddlewareReference;
use Tests\Support\SurfaceRemoval\Occurrence;
use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
use Tests\Support\SurfaceRemoval\RemovedTerm;
use Tests\Support\SurfaceRemoval\ScannedFile;
use Tests\Support\SurfaceRemoval\ScanOutcome;
use Tests\Support\SurfaceRemoval\TermMatchMode;

/*
 * 撤去した OCR 機能フラグ (`manual.ocr_analysis_enabled` / `AcceptedSourceDocumentTypes::imagesEnabled()` /
 * props `imageSourceDocumentsEnabled`) の**不在**を固定する gate
 * (家系正典 surface-removal-absence-gate v1。実行時層 + 静的層 + 自己検証)。
 *
 * 画像・スキャン SOP の OCR 対応は**オーナー決定により常時有効**で、rollout gate は撤去済み。
 * フラグが復活すると「受理形式の唯一の情報源」が 2 つに割れ、FormRequest / Service /
 * Inertia Props の受理形式が食い違う (T242 で撤去したのはその割れそのもの)。
 *
 * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
 *   - route 名の不在 / メソッド×URI の不在 / 実 HTTP 404 … **該当なし** (設定値とクラスメソッドであり
 *     route を持たない)
 *   - クラス・表の不在 … **該当なし** (`AcceptedSourceDocumentTypes` は現役で、削除された表も無い)
 *   - 機構に対応する等価の実行時層 … 本ファイルの実行時層 2 本
 *     (設定木にキーが無いこと / メソッドが実行時に存在しないこと)
 *
 * ★**消しすぎていないことの確認は二重に持たない**。画像受理が現役であることは既存テストが担保する:
 *   - `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
 *     - `画像 (jpg/jpeg/png) を含む (常時有効)`
 *     - `前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)`
 *   - `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
 *     - `jpg/png アップロードが成功する`
 *     - `公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す`
 *
 * ★走査対象は `RemovedSurfaceScanTargets` の走査根 8 本の git 追跡下の全ファイル
 *   (`database/migrations` は含めない)。**許可形は全 Tier で 0 個**である。
 *
 * ★`imagesEnabled` を**素のトークン一致で見ない**理由: 一般名すぎて、将来 OCR と無関係な
 *   同名メソッドが必要になったときに全 production surface を止めてしまう。よって PHP 側は
 *   **対象クラスの完全修飾名を基準にした宣言形・静的呼び出し形だけ**を見る。
 *   非 PHP 側で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるには
 *   クラスの完全修飾名が要るからである (完全修飾の参照文字列のほうは 0 件固定する)。
 *
 * ★**trait 経由の混入 (v1 の役割分担。誇張しない)**: v1 は **trait-use graph を扱わない**。
 *   - trait 宣言そのものの `imagesEnabled` は**対象クラスの宣言として認識しない**
 *   - 対象クラスが trait を取り込んでいる形と、trait 内の `self` / `static` / `parent` を
 *     受け手にした `::imagesEnabled` 参照は**未解決として落とす** (fail-closed)
 *   - それでも trait 経由で実際に混入した場合は、**実行時層の `method_exists()` が検出する**
 *
 * ★**保証しないもの**の正本は `RemovedSurfaceScanner` の docblock
 *   (分割連結・定数経由・動的組み立て・PHP のコメント内・middleware 位置の変数式)。
 * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
 *   (見本: `tests/Architecture/fixtures/surface-removal/ocr-flag/`)。
 */

/** 撤去した対象クラスの完全修飾名 (静的層の基準)。 */
function ocrFeatureFlagTargetClass(): string
{
    return AcceptedSourceDocumentTypes::class;
}

/** 撤去したメソッド名。 */
function ocrFeatureFlagTargetMethod(): string
{
    return 'imagesEnabled';
}

/**
 * Tier 1 / Tier 2 に共通して 0 件固定する撤去語 (語ごとに一致様式を宣言する)。
 *
 * @return list<RemovedTerm>
 */
function ocrFeatureFlagRemovedTerms(): array
{
    return [
        // 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるため run の segment 一致
        new RemovedTerm('ocr_analysis_enabled', TermMatchMode::RunSegment),
        new RemovedTerm('OCR_ANALYSIS_ENABLED', TermMatchMode::ExactRun),
        new RemovedTerm('imageSourceDocumentsEnabled', TermMatchMode::ExactRun),
    ];
}

/** 非 PHP に 0 件固定する完全修飾参照。 */
function ocrFeatureFlagFqcnTerm(): RemovedTerm
{
    return new RemovedTerm(
        ocrFeatureFlagTargetClass().'::'.ocrFeatureFlagTargetMethod(),
        TermMatchMode::FqcnMethodReference,
    );
}

/** 見本ディレクトリ。 */
function ocrFeatureFlagFixtureDirectory(): string
{
    return __DIR__.'/fixtures/surface-removal/ocr-flag';
}

/** 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**)。 */
function ocrFeatureFlagFixtureFile(string $name, bool $isPhp): ScannedFile
{
    $path = ocrFeatureFlagFixtureDirectory().'/'.$name;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("見本を読めません: {$name}");
    }

    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
}

/**
 * 撤去物への参照を 4 つの検出対象へ分けて返す。
 *
 * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
 *
 * @param  list<ScannedFile>  $files
 * @return array{lexemes: list<string>, texts: list<string>, methods: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
 */
function ocrFeatureFlagFindings(array $files): array
{
    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));

    $lexemes = [];
    $texts = [];
    /** @var list<ScanOutcome<Occurrence|MiddlewareReference|MethodReference>> $outcomes */
    $outcomes = [];

    foreach (ocrFeatureFlagRemovedTerms() as $term) {
        $php = RemovedSurfaceScanner::scanPhpLexemes($files, $term);
        $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
        $outcomes[] = $php;
        $outcomes[] = $text;
        $lexemes = [...$lexemes, ...$php->descriptions()];
        $texts = [...$texts, ...$text->descriptions()];
    }

    $methods = RemovedSurfaceScanner::scanMethodReferences(
        $files,
        ocrFeatureFlagTargetClass(),
        ocrFeatureFlagTargetMethod(),
    );
    $fqcnTexts = RemovedSurfaceScanner::scanText($nonPhp, ocrFeatureFlagFqcnTerm());
    $outcomes[] = $methods;
    $outcomes[] = $fqcnTexts;

    return [
        'lexemes' => $lexemes,
        'texts' => $texts,
        'methods' => $methods->descriptions(),
        'fqcnTexts' => $fqcnTexts->descriptions(),
        'unresolved' => ScanOutcome::mergeUnresolved($outcomes),
    ];
}

/**
 * 見本の正例 (検出経路と、経路別の前提検査)。
 *
 * ★一律の `str_contains($contents, $term)` は使わない — `self::imagesEnabled()` は対象の
 *   完全修飾名を含まず、大小違いの正例は canonical 表記を含まないため成立しない。
 *
 * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
 */
function ocrFeatureFlagPositiveFixtures(): array
{
    return [
        ['file' => 'positive-config-key.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['ocr_analysis_enabled']],
        ['file' => 'positive-config-path.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['manual.ocr_analysis_enabled']],
        ['file' => 'positive-class-const.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['OCR_ANALYSIS_ENABLED', 'const']],
        ['file' => 'positive-property.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$imageSourceDocumentsEnabled']],
        ['file' => 'positive-variable.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$ocr_analysis_enabled']],
        ['file' => 'positive-heredoc.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['imageSourceDocumentsEnabled', '<<<']],
        ['file' => 'positive-env.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['OCR_ANALYSIS_ENABLED']],
        ['file' => 'positive-prop.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['imageSourceDocumentsEnabled']],
        ['file' => 'positive-method-declaration.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
        ['file' => 'positive-method-declaration-bracketed.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
        ['file' => 'positive-method-declaration-byref.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'function &imagesenabled']],
        ['file' => 'positive-static-call-use.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
        ['file' => 'positive-static-call-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', ' as ']],
        ['file' => 'positive-static-call-groupuse-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '{']],
        ['file' => 'positive-static-call-fqcn.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
        ['file' => 'positive-static-call-relative.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace\\']],
        ['file' => 'positive-static-call-same-namespace.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace']],
        ['file' => 'positive-self-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'self::']],
        ['file' => 'positive-static-keyword-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'static::']],
        ['file' => 'positive-case-insensitive.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
        ['file' => 'positive-parent-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'parent::', 'extends']],
        ['file' => 'positive-mixed-group-use-function.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use app\\other\\{function']],
        ['file' => 'positive-use-function-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use function']],
        ['file' => 'positive-use-const-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use const']],
        ['file' => 'positive-multiple-namespaces.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace app\\support\\manual']],
        ['file' => 'positive-fqcn-in-text.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
        ['file' => 'positive-fqcn-leading-backslash.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
        ['file' => 'positive-fqcn-case.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
    ];
}

/**
 * 見本の負例 (反応してはならない。未解決にもならない)。
 *
 * @return list<array{file: string, php: bool}>
 */
function ocrFeatureFlagNegativeFixtures(): array
{
    return [
        ['file' => 'negative-other-class-declaration.php.txt', 'php' => true],
        ['file' => 'negative-other-class-static-call.php.txt', 'php' => true],
        ['file' => 'negative-self-in-other-class.php.txt', 'php' => true],
        ['file' => 'negative-target-other-method.php.txt', 'php' => true],
        ['file' => 'negative-method-suffix.php.txt', 'php' => true],
        ['file' => 'negative-dynamic-call.php.txt', 'php' => true],
        ['file' => 'negative-nested-function-declaration.php.txt', 'php' => true],
        ['file' => 'negative-anonymous-class-method.php.txt', 'php' => true],
        ['file' => 'negative-suffix.php.txt', 'php' => true],
        ['file' => 'negative-prefix.php.txt', 'php' => true],
        ['file' => 'negative-negated.php.txt', 'php' => true],
        ['file' => 'negative-php-comment.php.txt', 'php' => true],
        ['file' => 'negative-bare-imagesenabled.sh.txt', 'php' => false],
    ];
}

test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
    $manual = config('manual');
    // ★ is_array で絞り込む (expect()->toBeArray() は PHPStan の型を絞らない)
    if (! is_array($manual)) {
        throw new RuntimeException('設定木 manual を配列として解決できない');
    }

    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();

    // ★母集団が空なのに緑になる形を作らない (設定木そのものが読めていることの確認)
    expect(Arr::has($manual, 'source_document_mimes'))->toBeTrue();
});

test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
    // ★クラス自体は現役である (消しすぎていないことの最小確認)
    expect(method_exists(AcceptedSourceDocumentTypes::class, 'extensions'))->toBeTrue();
});

test('母集団に未解決もバイナリ除外も無い', function (): void {
    $population = RemovedSurfaceScanTargets::population();

    expect($population->unresolved)->toBe([]);
    expect($population->binaryExcluded)->toBe([]);
    expect(count($population->files))->toBeGreaterThan(0);
});

test('撤去した 3 語が走査根の PHP lexeme に 1 件も無い', function (): void {
    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);

    expect($findings['lexemes'])->toBe(
        [],
        'PHP lexeme への撤去語の再流入: '.implode(', ', $findings['lexemes']),
    );
});

test('撤去した 3 語が走査根の非 PHP に 1 件も無い', function (): void {
    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);

    expect($findings['texts'])->toBe(
        [],
        '非 PHP への撤去語の再流入: '.implode(', ', $findings['texts']),
    );
});

test('imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い', function (): void {
    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);

    expect($findings['methods'])->toBe(
        [],
        'imagesEnabled の再流入: '.implode(', ', $findings['methods']),
    );
});

test('非 PHP に完全修飾の imagesEnabled 参照が 1 件も無い', function (): void {
    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);

    expect($findings['fqcnTexts'])->toBe(
        [],
        '非 PHP への完全修飾参照の再流入: '.implode(', ', $findings['fqcnTexts']),
    );
});

test('走査で未解決が 1 件も出ていない', function (): void {
    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);

    expect($findings['unresolved'])->toBe(
        [],
        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
    );
});

test('検出器の自己検証: 正例をすべて検出する', function (): void {
    foreach (ocrFeatureFlagPositiveFixtures() as $fixture) {
        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);

        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
        foreach ($fixture['requires'] as $needle) {
            expect(str_contains(strtolower($file->contents), strtolower($needle)))
                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
        }

        $findings = ocrFeatureFlagFindings([$file]);
        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");

        foreach ($fixture['buckets'] as $bucket) {
            expect(count($findings[$bucket]))
                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
        }
    }
});

test('検出器の自己検証: 負例に反応しない', function (): void {
    foreach (ocrFeatureFlagNegativeFixtures() as $fixture) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php'])]);

        expect($findings['lexemes'])->toBe([], "負例 {$fixture['file']} に lexeme で反応した");
        expect($findings['texts'])->toBe([], "負例 {$fixture['file']} に text で反応した");
        expect($findings['methods'])->toBe([], "負例 {$fixture['file']} に method で反応した");
        expect($findings['fqcnTexts'])->toBe([], "負例 {$fixture['file']} に fqcn で反応した");
        expect($findings['unresolved'])->toBe([], "負例 {$fixture['file']} が未解決になった");
    }
});

test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
    $fixtures = [
        ['file' => 'negative-same-shortname-declaration.php.txt', 'php' => true],
        ['file' => 'negative-same-shortname-static-call.php.txt', 'php' => true],
        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
    ];

    foreach ($fixtures as $fixture) {
        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
        // 短名一致へ退行したら赤くなる見本であること (前提検査)
        expect(str_contains($file->contents, 'AcceptedSourceDocumentTypes'))->toBeTrue();

        $findings = ocrFeatureFlagFindings([$file]);
        expect($findings['methods'])->toBe([], "同じ短名の別クラス {$fixture['file']} に反応した");
        expect($findings['fqcnTexts'])->toBe([], "同じ短名の別クラス {$fixture['file']} に fqcn で反応した");
        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$fixture['file']} が未解決になった");
    }
});

test('検出器の自己検証: FQCN 様式の境界', function (): void {
    $shouldMatch = [
        'positive-fqcn-in-text.sh.txt',           // 先頭 `\` 無し
        'positive-fqcn-leading-backslash.sh.txt', // 先頭 `\` あり
        'positive-fqcn-case.yaml.txt',            // ASCII 大小違い
    ];
    $shouldNotMatch = [
        'negative-fqcn-other-namespace.sh.txt',  // 同じ短名の別 namespace
        'negative-fqcn-other-method.sh.txt',     // 対象クラスだが別メソッド
        'negative-fqcn-method-suffix.sh.txt',    // メソッド名の接尾辞つき
        'negative-bare-imagesenabled.sh.txt',    // 裸のメソッド名 (完全修飾でない)
    ];

    foreach ($shouldMatch as $name) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
        expect(count($findings['fqcnTexts']))->toBeGreaterThan(0, "FQCN 正例 {$name} を検出できない");
    }

    foreach ($shouldNotMatch as $name) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
        expect($findings['fqcnTexts'])->toBe([], "FQCN 負例 {$name} に反応した");
    }
});

test('検出器の自己検証: 解決できないクラス参照は未解決になる', function (): void {
    foreach (['unresolved-dynamic-class-static-call.php.txt', 'unresolved-parent-without-extends.php.txt'] as $name) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);

        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
        expect($findings['methods'])->toBe([], "{$name} を解決済みの違反として数えた");
    }
});

test('検出器の自己検証: 関数・定数の取り込みが同名クラスの解決へ影響しない', function (): void {
    // PHP は関数・定数とクラスの取り込み空間が別。`use function A\B\X` があっても
    // クラス `X` は現在 namespace のものへ解決される (印だけ読み飛ばすと別 namespace へ誤解決する)
    $names = [
        'positive-mixed-group-use-function.php.txt',
        'positive-use-function-same-name.php.txt',
        'positive-use-const-same-name.php.txt',
    ];

    foreach ($names as $name) {
        $file = ocrFeatureFlagFixtureFile($name, true);
        // 別 namespace の同名を取り込んでいる見本であること (前提検査)
        expect(str_contains($file->contents, 'App\\Other\\'))->toBeTrue();

        $findings = ocrFeatureFlagFindings([$file]);
        expect(count($findings['methods']))->toBeGreaterThan(0, "{$name} を検出できない (誤解決)");
        expect($findings['unresolved'])->toBe([]);
    }
});

test('検出器の自己検証: 参照返しのメソッド宣言も数える', function (): void {
    // PHP 8 は `function &foo()` の `&` を素の文字トークンにしない
    // (T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)。文字トークンだけを見ると見逃す
    $file = ocrFeatureFlagFixtureFile('positive-method-declaration-byref.php.txt', true);
    expect(str_contains(strtolower($file->contents), 'function &imagesenabled'))->toBeTrue();

    $findings = ocrFeatureFlagFindings([$file]);

    expect(count($findings['methods']))->toBeGreaterThan(0, '参照返しのメソッド宣言を検出できない');
    expect($findings['unresolved'])->toBe([]);
});

test('検出器の自己検証: メソッド宣言は型の本体の直下だけを数える', function (): void {
    // メソッドの中の名前付き関数 / 型の中の無名クラスのメソッドは宣言として数えない
    foreach (['negative-nested-function-declaration.php.txt', 'negative-anonymous-class-method.php.txt'] as $name) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);

        expect($findings['methods'])->toBe([], "{$name} をメソッド宣言として誤検出した");
        expect($findings['unresolved'])->toBe([]);
    }
});

test('検出器の自己検証: trait 内の self/static/parent と対象クラスの trait 取り込みは未解決になる', function (): void {
    $names = [
        'unresolved-trait-self-call.php.txt',
        'unresolved-trait-static-call.php.txt',
        'unresolved-trait-parent-call.php.txt',
        'unresolved-trait-used-by-target.php.txt',
    ];

    foreach ($names as $name) {
        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);

        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
        // ★誤って「解決済みの違反」として数えていないこと (fail-open でも fail-loud でもない形を防ぐ)
        expect($findings['methods'])->toBe([]);
    }
});

test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
    $findings = ocrFeatureFlagFindings([
        ocrFeatureFlagFixtureFile('unresolved-broken-php.php.txt', true),
    ]);

    expect(count($findings['unresolved']))->toBeGreaterThan(0);
});
