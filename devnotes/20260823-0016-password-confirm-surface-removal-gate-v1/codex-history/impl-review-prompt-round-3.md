# Round 3: Round 2 の指摘への対応

Round 2 の全指摘 (Warning 2 / Suggestion 1) に対応した。

# 対応マトリクス: impl-review Round 2

## [Warning] 参照返しメソッドの宣言を検出できない

- 判断: **対応する**
- 根拠: 指摘どおり。PHP 8 は `&` を文脈で 3 通りにトークン化する。実測で確認した:
  `public static function &foo(): array` の `&` は素の文字トークンではなく
  **`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`** になる (引数の `array &$x` の側は
  `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`)。`isChar()` は `id === null` だけを認めるので、
  参照返しの宣言を**見逃していた** = fail-open。
- 対応内容:
  1. `isReturnByReferenceMarker()` を新設し、素の `&` と上記 2 つのトークン ID を
     すべて認めるようにした (保守的に両方を含める)。
  2. 見本 `positive-method-declaration-byref.php.txt` を追加し、専用テスト
     「参照返しのメソッド宣言も数える」で固定した。
  3. **fail-first 実測**: 修正を旧実装 (`isChar(…, '&')`) へ戻すと当該テストが
     「参照返しのメソッド宣言を検出できない」で赤くなることを確認した。

## [Suggestion] broken symlink は `population()` で共通関数を通っていない

- 判断: **対応する** (説明の訂正ではなく実経路を直す側を採る)
- 根拠: 指摘どおり。`is_file()` は壊れた symlink に false を返すため、順序が逆だと
  共通の純関数へ到達しない。結果は `unresolved` なので fail-open ではないが、
  「`population()` も自己検証も必ずこの関数を通る」という docblock の宣言と実経路が
  食い違う。**説明を弱めるより経路を直すほうが、自己検証と実母集団の同一性という
  本設計の趣旨に合う**。
- 対応内容: `population()` の判定順序を「symlink 判定 → `is_file()` 判定」へ入れ替え、
  クラス docblock の確定順序の記述も同じ順序へ直した。

## [Warning] 全体検証の完了条件がまだ満たされていない

- 判断: **対応する**
- 根拠: AGENTS.md の完了条件は全検証レーンの green であり、局所証拠では代替できない。
- 対応内容: Round 2 の修正をすべて入れ終えたあとに
  `composer test` / `pnpm test` / `pnpm test:packages` を全体で取り直し、
  結果を Round 3 のプロンプトへ実数で載せる。


## 参照返しトークンの実測 (指摘の裏取り)

```
$ php -r '$src = "<?php class A { public static function &foo(): array { return []; } public function bar(array &$x) {} }";
          foreach (token_get_all($src) as $t) ...'

T_FUNCTION => 'function'
T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG => '&'   ← 参照返しの & (素の文字トークンではない)
T_STRING => 'foo'
...
T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG => '&'       ← 引数の参照渡しの &
T_VARIABLE => '$x'
```

## 追加した見本

`tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration-byref.php.txt`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Manual;

final class AcceptedSourceDocumentTypes
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** @return array<string, bool> */
    public static function &imagesEnabled(): array
    {
        return self::$cache;
    }
}

```

## fail-first 実測 (今回の修正が効いていることの裏取り)

| 修正を戻した箇所 | 赤くなった検査 |
|---|---|
| `isReturnByReferenceMarker()` を旧実装 `isChar($tokens, $i + 1, '&')` へ戻す | 「参照返しのメソッド宣言も数える」が `参照返しのメソッド宣言を検出できない` で赤 |

## 全検証レーンの結果 (最終ツリーで取り直し済み)

| レーン | 結果 |
|---|---|
| `composer test` | **6460 tests / 6458 passed / 0 failed / 2 skipped / 5 risky** (Round 1 で報告した bug-hunt harness の flake も今回は green) |
| `composer phpstan` (level 10 / 1010 files) | **No errors** (widen / baseline / ignore 無し) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` | passed |
| `pnpm typecheck` | passed |
| `pnpm test` | **173 files / 2366 tests passed** |
| `pnpm build` | built |
| `pnpm typecheck:packages` | passed |
| `pnpm build:packages` | passed |
| `pnpm test:packages` | **10 files / 106 tests passed** |

触った gate 単独の再実行: `PasswordConfirmSurfaceAbsenceGateTest` 18 passed /
`OcrFeatureFlagAbsenceGateTest` 18 passed (参照返しの検査を 1 本追加) /
`PasswordConfirmMiddlewareAbsenceTest` 3 passed。

**AGENTS.md の検証コマンド 10 本すべてが green** であり、Round 2 の「全体検証の完了条件」は満たした。

## 差分 (Round 2 からの修正部分)

```diff
diff --git a/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
new file mode 100644
index 00000000..63590288
--- /dev/null
+++ b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
@@ -0,0 +1,448 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Manual\AcceptedSourceDocumentTypes;
+use Illuminate\Support\Arr;
+use Tests\Support\SurfaceRemoval\MethodReference;
+use Tests\Support\SurfaceRemoval\MiddlewareReference;
+use Tests\Support\SurfaceRemoval\Occurrence;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
+use Tests\Support\SurfaceRemoval\RemovedTerm;
+use Tests\Support\SurfaceRemoval\ScannedFile;
+use Tests\Support\SurfaceRemoval\ScanOutcome;
+use Tests\Support\SurfaceRemoval\TermMatchMode;
+
+/*
+ * 撤去した OCR 機能フラグ (`manual.ocr_analysis_enabled` / `AcceptedSourceDocumentTypes::imagesEnabled()` /
+ * props `imageSourceDocumentsEnabled`) の**不在**を固定する gate
+ * (家系正典 surface-removal-absence-gate v1。実行時層 + 静的層 + 自己検証)。
+ *
+ * 画像・スキャン SOP の OCR 対応は**オーナー決定により常時有効**で、rollout gate は撤去済み。
+ * フラグが復活すると「受理形式の唯一の情報源」が 2 つに割れ、FormRequest / Service /
+ * Inertia Props の受理形式が食い違う (T242 で撤去したのはその割れそのもの)。
+ *
+ * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
+ *   - route 名の不在 / メソッド×URI の不在 / 実 HTTP 404 … **該当なし** (設定値とクラスメソッドであり
+ *     route を持たない)
+ *   - クラス・表の不在 … **該当なし** (`AcceptedSourceDocumentTypes` は現役で、削除された表も無い)
+ *   - 機構に対応する等価の実行時層 … 本ファイルの実行時層 2 本
+ *     (設定木にキーが無いこと / メソッドが実行時に存在しないこと)
+ *
+ * ★**消しすぎていないことの確認は二重に持たない**。画像受理が現役であることは既存テストが担保する:
+ *   - `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
+ *     - `画像 (jpg/jpeg/png) を含む (常時有効)`
+ *     - `前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)`
+ *   - `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
+ *     - `jpg/png アップロードが成功する`
+ *     - `公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す`
+ *
+ * ★走査対象は `RemovedSurfaceScanTargets` の走査根 8 本の git 追跡下の全ファイル
+ *   (`database/migrations` は含めない)。**許可形は全 Tier で 0 個**である。
+ *
+ * ★`imagesEnabled` を**素のトークン一致で見ない**理由: 一般名すぎて、将来 OCR と無関係な
+ *   同名メソッドが必要になったときに全 production surface を止めてしまう。よって PHP 側は
+ *   **対象クラスの完全修飾名を基準にした宣言形・静的呼び出し形だけ**を見る。
+ *   非 PHP 側で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるには
+ *   クラスの完全修飾名が要るからである (完全修飾の参照文字列のほうは 0 件固定する)。
+ *
+ * ★**trait 経由の混入 (v1 の役割分担。誇張しない)**: v1 は **trait-use graph を扱わない**。
+ *   - trait 宣言そのものの `imagesEnabled` は**対象クラスの宣言として認識しない**
+ *   - 対象クラスが trait を取り込んでいる形と、trait 内の `self` / `static` / `parent` を
+ *     受け手にした `::imagesEnabled` 参照は**未解決として落とす** (fail-closed)
+ *   - それでも trait 経由で実際に混入した場合は、**実行時層の `method_exists()` が検出する**
+ *
+ * ★**保証しないもの**の正本は `RemovedSurfaceScanner` の docblock
+ *   (分割連結・定数経由・動的組み立て・PHP のコメント内・middleware 位置の変数式)。
+ * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
+ *   (見本: `tests/Architecture/fixtures/surface-removal/ocr-flag/`)。
+ */
+
+/** 撤去した対象クラスの完全修飾名 (静的層の基準)。 */
+function ocrFeatureFlagTargetClass(): string
+{
+    return AcceptedSourceDocumentTypes::class;
+}
+
+/** 撤去したメソッド名。 */
+function ocrFeatureFlagTargetMethod(): string
+{
+    return 'imagesEnabled';
+}
+
+/**
+ * Tier 1 / Tier 2 に共通して 0 件固定する撤去語 (語ごとに一致様式を宣言する)。
+ *
+ * @return list<RemovedTerm>
+ */
+function ocrFeatureFlagRemovedTerms(): array
+{
+    return [
+        // 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるため run の segment 一致
+        new RemovedTerm('ocr_analysis_enabled', TermMatchMode::RunSegment),
+        new RemovedTerm('OCR_ANALYSIS_ENABLED', TermMatchMode::ExactRun),
+        new RemovedTerm('imageSourceDocumentsEnabled', TermMatchMode::ExactRun),
+    ];
+}
+
+/** 非 PHP に 0 件固定する完全修飾参照。 */
+function ocrFeatureFlagFqcnTerm(): RemovedTerm
+{
+    return new RemovedTerm(
+        ocrFeatureFlagTargetClass().'::'.ocrFeatureFlagTargetMethod(),
+        TermMatchMode::FqcnMethodReference,
+    );
+}
+
+/** 見本ディレクトリ。 */
+function ocrFeatureFlagFixtureDirectory(): string
+{
+    return __DIR__.'/fixtures/surface-removal/ocr-flag';
+}
+
+/** 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**)。 */
+function ocrFeatureFlagFixtureFile(string $name, bool $isPhp): ScannedFile
+{
+    $path = ocrFeatureFlagFixtureDirectory().'/'.$name;
+    $contents = file_get_contents($path);
+    if ($contents === false) {
+        throw new RuntimeException("見本を読めません: {$name}");
+    }
+
+    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
+}
+
+/**
+ * 撤去物への参照を 4 つの検出対象へ分けて返す。
+ *
+ * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
+ *
+ * @param  list<ScannedFile>  $files
+ * @return array{lexemes: list<string>, texts: list<string>, methods: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
+ */
+function ocrFeatureFlagFindings(array $files): array
+{
+    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));
+
+    $lexemes = [];
+    $texts = [];
+    /** @var list<ScanOutcome<Occurrence|MiddlewareReference|MethodReference>> $outcomes */
+    $outcomes = [];
+
+    foreach (ocrFeatureFlagRemovedTerms() as $term) {
+        $php = RemovedSurfaceScanner::scanPhpLexemes($files, $term);
+        $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
+        $outcomes[] = $php;
+        $outcomes[] = $text;
+        $lexemes = [...$lexemes, ...$php->descriptions()];
+        $texts = [...$texts, ...$text->descriptions()];
+    }
+
+    $methods = RemovedSurfaceScanner::scanMethodReferences(
+        $files,
+        ocrFeatureFlagTargetClass(),
+        ocrFeatureFlagTargetMethod(),
+    );
+    $fqcnTexts = RemovedSurfaceScanner::scanText($nonPhp, ocrFeatureFlagFqcnTerm());
+    $outcomes[] = $methods;
+    $outcomes[] = $fqcnTexts;
+
+    return [
+        'lexemes' => $lexemes,
+        'texts' => $texts,
+        'methods' => $methods->descriptions(),
+        'fqcnTexts' => $fqcnTexts->descriptions(),
+        'unresolved' => ScanOutcome::mergeUnresolved($outcomes),
+    ];
+}
+
+/**
+ * 見本の正例 (検出経路と、経路別の前提検査)。
+ *
+ * ★一律の `str_contains($contents, $term)` は使わない — `self::imagesEnabled()` は対象の
+ *   完全修飾名を含まず、大小違いの正例は canonical 表記を含まないため成立しない。
+ *
+ * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
+ */
+function ocrFeatureFlagPositiveFixtures(): array
+{
+    return [
+        ['file' => 'positive-config-key.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['ocr_analysis_enabled']],
+        ['file' => 'positive-config-path.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['manual.ocr_analysis_enabled']],
+        ['file' => 'positive-class-const.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['OCR_ANALYSIS_ENABLED', 'const']],
+        ['file' => 'positive-property.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$imageSourceDocumentsEnabled']],
+        ['file' => 'positive-variable.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$ocr_analysis_enabled']],
+        ['file' => 'positive-heredoc.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['imageSourceDocumentsEnabled', '<<<']],
+        ['file' => 'positive-env.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['OCR_ANALYSIS_ENABLED']],
+        ['file' => 'positive-prop.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['imageSourceDocumentsEnabled']],
+        ['file' => 'positive-method-declaration.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-method-declaration-bracketed.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-method-declaration-byref.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'function &imagesenabled']],
+        ['file' => 'positive-static-call-use.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', ' as ']],
+        ['file' => 'positive-static-call-groupuse-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '{']],
+        ['file' => 'positive-static-call-fqcn.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-relative.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace\\']],
+        ['file' => 'positive-static-call-same-namespace.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace']],
+        ['file' => 'positive-self-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'self::']],
+        ['file' => 'positive-static-keyword-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'static::']],
+        ['file' => 'positive-case-insensitive.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-parent-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'parent::', 'extends']],
+        ['file' => 'positive-mixed-group-use-function.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use app\\other\\{function']],
+        ['file' => 'positive-use-function-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use function']],
+        ['file' => 'positive-use-const-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use const']],
+        ['file' => 'positive-multiple-namespaces.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace app\\support\\manual']],
+        ['file' => 'positive-fqcn-in-text.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-leading-backslash.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-case.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+    ];
+}
+
+/**
+ * 見本の負例 (反応してはならない。未解決にもならない)。
+ *
+ * @return list<array{file: string, php: bool}>
+ */
+function ocrFeatureFlagNegativeFixtures(): array
+{
+    return [
+        ['file' => 'negative-other-class-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-other-class-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-self-in-other-class.php.txt', 'php' => true],
+        ['file' => 'negative-target-other-method.php.txt', 'php' => true],
+        ['file' => 'negative-method-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-dynamic-call.php.txt', 'php' => true],
+        ['file' => 'negative-nested-function-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-anonymous-class-method.php.txt', 'php' => true],
+        ['file' => 'negative-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-prefix.php.txt', 'php' => true],
+        ['file' => 'negative-negated.php.txt', 'php' => true],
+        ['file' => 'negative-php-comment.php.txt', 'php' => true],
+        ['file' => 'negative-bare-imagesenabled.sh.txt', 'php' => false],
+    ];
+}
+
+test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
+    $manual = config('manual');
+    // ★ is_array で絞り込む (expect()->toBeArray() は PHPStan の型を絞らない)
+    if (! is_array($manual)) {
+        throw new RuntimeException('設定木 manual を配列として解決できない');
+    }
+
+    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
+    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();
+
+    // ★母集団が空なのに緑になる形を作らない (設定木そのものが読めていることの確認)
+    expect(Arr::has($manual, 'source_document_mimes'))->toBeTrue();
+});
+
+test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
+    // ★クラス自体は現役である (消しすぎていないことの最小確認)
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'extensions'))->toBeTrue();
+});
+
+test('母集団に未解決もバイナリ除外も無い', function (): void {
+    $population = RemovedSurfaceScanTargets::population();
+
+    expect($population->unresolved)->toBe([]);
+    expect($population->binaryExcluded)->toBe([]);
+    expect(count($population->files))->toBeGreaterThan(0);
+});
+
+test('撤去した 3 語が走査根の PHP lexeme に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['lexemes'])->toBe(
+        [],
+        'PHP lexeme への撤去語の再流入: '.implode(', ', $findings['lexemes']),
+    );
+});
+
+test('撤去した 3 語が走査根の非 PHP に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['texts'])->toBe(
+        [],
+        '非 PHP への撤去語の再流入: '.implode(', ', $findings['texts']),
+    );
+});
+
+test('imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['methods'])->toBe(
+        [],
+        'imagesEnabled の再流入: '.implode(', ', $findings['methods']),
+    );
+});
+
+test('非 PHP に完全修飾の imagesEnabled 参照が 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['fqcnTexts'])->toBe(
+        [],
+        '非 PHP への完全修飾参照の再流入: '.implode(', ', $findings['fqcnTexts']),
+    );
+});
+
+test('走査で未解決が 1 件も出ていない', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['unresolved'])->toBe(
+        [],
+        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
+    );
+});
+
+test('検出器の自己検証: 正例をすべて検出する', function (): void {
+    foreach (ocrFeatureFlagPositiveFixtures() as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+
+        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
+        foreach ($fixture['requires'] as $needle) {
+            expect(str_contains(strtolower($file->contents), strtolower($needle)))
+                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
+        }
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");
+
+        foreach ($fixture['buckets'] as $bucket) {
+            expect(count($findings[$bucket]))
+                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
+        }
+    }
+});
+
+test('検出器の自己検証: 負例に反応しない', function (): void {
+    foreach (ocrFeatureFlagNegativeFixtures() as $fixture) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php'])]);
+
+        expect($findings['lexemes'])->toBe([], "負例 {$fixture['file']} に lexeme で反応した");
+        expect($findings['texts'])->toBe([], "負例 {$fixture['file']} に text で反応した");
+        expect($findings['methods'])->toBe([], "負例 {$fixture['file']} に method で反応した");
+        expect($findings['fqcnTexts'])->toBe([], "負例 {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "負例 {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
+    $fixtures = [
+        ['file' => 'negative-same-shortname-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-same-shortname-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
+    ];
+
+    foreach ($fixtures as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+        // 短名一致へ退行したら赤くなる見本であること (前提検査)
+        expect(str_contains($file->contents, 'AcceptedSourceDocumentTypes'))->toBeTrue();
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['methods'])->toBe([], "同じ短名の別クラス {$fixture['file']} に反応した");
+        expect($findings['fqcnTexts'])->toBe([], "同じ短名の別クラス {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: FQCN 様式の境界', function (): void {
+    $shouldMatch = [
+        'positive-fqcn-in-text.sh.txt',           // 先頭 `\` 無し
+        'positive-fqcn-leading-backslash.sh.txt', // 先頭 `\` あり
+        'positive-fqcn-case.yaml.txt',            // ASCII 大小違い
+    ];
+    $shouldNotMatch = [
+        'negative-fqcn-other-namespace.sh.txt',  // 同じ短名の別 namespace
+        'negative-fqcn-other-method.sh.txt',     // 対象クラスだが別メソッド
+        'negative-fqcn-method-suffix.sh.txt',    // メソッド名の接尾辞つき
+        'negative-bare-imagesenabled.sh.txt',    // 裸のメソッド名 (完全修飾でない)
+    ];
+
+    foreach ($shouldMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect(count($findings['fqcnTexts']))->toBeGreaterThan(0, "FQCN 正例 {$name} を検出できない");
+    }
+
+    foreach ($shouldNotMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect($findings['fqcnTexts'])->toBe([], "FQCN 負例 {$name} に反応した");
+    }
+});
+
+test('検出器の自己検証: 解決できないクラス参照は未解決になる', function (): void {
+    foreach (['unresolved-dynamic-class-static-call.php.txt', 'unresolved-parent-without-extends.php.txt'] as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
+        expect($findings['methods'])->toBe([], "{$name} を解決済みの違反として数えた");
+    }
+});
+
+test('検出器の自己検証: 関数・定数の取り込みが同名クラスの解決へ影響しない', function (): void {
+    // PHP は関数・定数とクラスの取り込み空間が別。`use function A\B\X` があっても
+    // クラス `X` は現在 namespace のものへ解決される (印だけ読み飛ばすと別 namespace へ誤解決する)
+    $names = [
+        'positive-mixed-group-use-function.php.txt',
+        'positive-use-function-same-name.php.txt',
+        'positive-use-const-same-name.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $file = ocrFeatureFlagFixtureFile($name, true);
+        // 別 namespace の同名を取り込んでいる見本であること (前提検査)
+        expect(str_contains($file->contents, 'App\\Other\\'))->toBeTrue();
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect(count($findings['methods']))->toBeGreaterThan(0, "{$name} を検出できない (誤解決)");
+        expect($findings['unresolved'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: 参照返しのメソッド宣言も数える', function (): void {
+    // PHP 8 は `function &foo()` の `&` を素の文字トークンにしない
+    // (T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)。文字トークンだけを見ると見逃す
+    $file = ocrFeatureFlagFixtureFile('positive-method-declaration-byref.php.txt', true);
+    expect(str_contains(strtolower($file->contents), 'function &imagesenabled'))->toBeTrue();
+
+    $findings = ocrFeatureFlagFindings([$file]);
+
+    expect(count($findings['methods']))->toBeGreaterThan(0, '参照返しのメソッド宣言を検出できない');
+    expect($findings['unresolved'])->toBe([]);
+});
+
+test('検出器の自己検証: メソッド宣言は型の本体の直下だけを数える', function (): void {
+    // メソッドの中の名前付き関数 / 型の中の無名クラスのメソッドは宣言として数えない
+    foreach (['negative-nested-function-declaration.php.txt', 'negative-anonymous-class-method.php.txt'] as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect($findings['methods'])->toBe([], "{$name} をメソッド宣言として誤検出した");
+        expect($findings['unresolved'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: trait 内の self/static/parent と対象クラスの trait 取り込みは未解決になる', function (): void {
+    $names = [
+        'unresolved-trait-self-call.php.txt',
+        'unresolved-trait-static-call.php.txt',
+        'unresolved-trait-parent-call.php.txt',
+        'unresolved-trait-used-by-target.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
+        // ★誤って「解決済みの違反」として数えていないこと (fail-open でも fail-loud でもない形を防ぐ)
+        expect($findings['methods'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
+    $findings = ocrFeatureFlagFindings([
+        ocrFeatureFlagFixtureFile('unresolved-broken-php.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
new file mode 100644
index 00000000..6ee92212
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
@@ -0,0 +1,265 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 撤去物の不在 gate が共有する**走査根と実走査母集団**の単一出典。
+ *
+ * ★走査根 (8 本): `.github` / `app` / `bootstrap` / `config` / `lang` / `resources` /
+ *   `routes` / `scripts`。`.github` と `scripts` は家系の正典 v1 が**必須**にしている
+ *   (撤去直後に CI 設定へ参照が残り CI ジョブが全滅した実測事故の教訓)。
+ * ★`database/migrations` は**含めない**。撤去した表の名前は移行履歴に必ず残るため、
+ *   含めると原理的に赤くなる (正典 v1 の明文)。
+ * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが実在し、
+ *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
+ * ★確定は**この 1 経路だけ**で行う (順序を固定する):
+ *     git 追跡下の列挙 → symlink が解決でき解決先がリポジトリ内か (壊れている / 外なら unresolved)
+ *     → 通常ファイルとして読めるか (失敗は unresolved)
+ *     → NUL 判定 (含むなら binaryExcluded) → UTF-8 検証 (不正は unresolved)
+ *     → 実走査母集団へ登録
+ *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
+ * ★**fail-open を作らない**: git 追跡下にあるのに通常ファイルとして読めないパスを
+ *   `continue` で捨てない (削除途中 / 壊れた symlink に撤去語があると検査から消えるため)。
+ *   必ず `unresolved` へ理由つきで登録する。
+ * ★**バイナリ除外は無言で許容しない**: 利用側 gate は `binaryExcluded === []` を
+ *   不変条件にする (NUL を 1 つ入れて静的層を迂回する経路を塞ぐ)。
+ * ★**保証しないもの**: git 未追跡のファイルは列挙しない
+ *   (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
+ *   走査根の外 (`tests/` / `docs/` / `database/` 等) は見ない。
+ * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った
+ *   リポジトリ全体の全数列挙で、本クラスは**同じ作法 (`git ls-files`) で母集団を
+ *   全ファイルへ広げ、走査根を 8 本へ絞った兄弟**である。列挙を 2 本持つのではなく
+ *   対象の定義が違う。
+ */
+final class RemovedSurfaceScanTargets
+{
+    /** @var list<string> 走査根 (リポジトリルート相対)。 */
+    private const array ROOT_DIRECTORIES = [
+        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
+    ];
+
+    /**
+     * 各根に必ず含まれる代表パス (root 割当 / パス計算の誤りを検出する pin)。
+     *
+     * @var array<string, string>
+     */
+    public const array REPRESENTATIVE_PATHS = [
+        '.github' => '.github/workflows/ci.yml',
+        'app' => 'app/Providers/FortifyServiceProvider.php',
+        'bootstrap' => 'bootstrap/app.php',
+        'config' => 'config/seo.php',
+        'lang' => 'lang/ja/validation.php',
+        'resources' => 'resources/js/pages/Settings/Security.svelte',
+        'routes' => 'routes/web.php',
+        'scripts' => 'scripts/ci/drop-test-db.php',
+    ];
+
+    /**
+     * 確定済みの実走査母集団 (プロセス内で 1 度だけ確定する)。
+     *
+     * ★2 つの gate が同じ母集団を共有するためのメモ化であり、判定を持たない。
+     */
+    private static ?ScanPopulation $memoizedPopulation = null;
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /** リポジトリルート (テスト実行時の base path)。 */
+    public static function repositoryRoot(): string
+    {
+        $root = realpath(__DIR__.'/../../..');
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリルートを解決できません');
+        }
+
+        return $root;
+    }
+
+    /**
+     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
+     *
+     * @return array<string, string>
+     */
+    public static function roots(): array
+    {
+        $repositoryRoot = self::repositoryRoot();
+        $roots = [];
+        foreach (self::ROOT_DIRECTORIES as $relative) {
+            $absolute = realpath($repositoryRoot.'/'.$relative);
+            if (! is_string($absolute)) {
+                throw new RuntimeException("走査根を解決できません: {$relative}");
+            }
+            $roots[$relative] = $absolute;
+        }
+
+        return $roots;
+    }
+
+    /**
+     * 解決済みの絶対パスがリポジトリルート配下かどうか (純関数。自己検証の seam)。
+     *
+     * ★`population()` も自己検証も必ずこの関数を通す。symlink 判定を `population()` 内へ
+     *   閉じ込めると、`git ls-files` の母集団外から確かめる手立てが無くなる。
+     */
+    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
+    {
+        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
+    }
+
+    /**
+     * symlink の解決結果の判定 (**`population()` も自己検証も必ずここを通る**)。
+     *
+     * ★symlink でなければ null。解決できない (壊れた symlink) か、解決先がリポジトリ外なら理由を返す。
+     *   リポジトリ外のファイルを黙って走査対象へ引き込まず、走査対象からも逃がさない。
+     * ★判定は純関数 `isPathInsideRepository()` を通す (`git ls-files` の母集団の外からも
+     *   同じ経路で確かめられるようにするため)。
+     */
+    public static function symlinkUnresolvedReason(string $repositoryRoot, string $absolute): ?string
+    {
+        if (! is_link($absolute)) {
+            return null;
+        }
+
+        $target = realpath($absolute);
+        if ($target === false) {
+            return 'symlink の解決に失敗 (壊れた symlink)';
+        }
+        if (! self::isPathInsideRepository($repositoryRoot, $target)) {
+            return 'symlink がリポジトリ外へ解決される';
+        }
+
+        return null;
+    }
+
+    /**
+     * 内容の分類 (純関数。**`population()` も自己検証も必ずここを通る**)。
+     *
+     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
+     *   見本 (走査根の外に置く) からも実母集団からも同じ経路で確かめられる。
+     */
+    public static function classifyContents(string $contents): ContentClassification
+    {
+        if (str_contains($contents, "\0")) {
+            return ContentClassification::Binary;
+        }
+        if (! mb_check_encoding($contents, 'UTF-8')) {
+            return ContentClassification::InvalidUtf8;
+        }
+
+        return ContentClassification::Text;
+    }
+
+    /** 実走査母集団を確定する (唯一の経路)。 */
+    public static function population(): ScanPopulation
+    {
+        if (self::$memoizedPopulation instanceof ScanPopulation) {
+            return self::$memoizedPopulation;
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $files = [];
+        $unresolved = [];
+        $binaryExcluded = [];
+
+        foreach (array_keys(self::roots()) as $root) {
+            foreach (self::trackedPaths($repositoryRoot, $root) as $relative) {
+                $absolute = $repositoryRoot.'/'.$relative;
+
+                // ★ symlink の判定を先に通す (壊れた symlink は is_file() が false になるため、
+                //   順序を逆にすると共通の純関数を通らず、自己検証と実母集団の経路が切れる)
+                $symlinkReason = self::symlinkUnresolvedReason($repositoryRoot, $absolute);
+                if ($symlinkReason !== null) {
+                    $unresolved[$relative] = $symlinkReason;
+
+                    continue;
+                }
+
+                if (! is_file($absolute)) {
+                    // ★ git 追跡下なのに通常ファイルとして無い = 無言で捨てない
+                    $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';
+
+                    continue;
+                }
+
+                $contents = @file_get_contents($absolute);
+                if ($contents === false) {
+                    $unresolved[$relative] = 'ファイルの読み取りに失敗';
+
+                    continue;
+                }
+
+                // ★分類は必ず classifyContents() を通す (自己検証と同じ経路)
+                $classification = self::classifyContents($contents);
+                if ($classification === ContentClassification::Binary) {
+                    $binaryExcluded[] = $relative;
+
+                    continue;
+                }
+                if ($classification === ContentClassification::InvalidUtf8) {
+                    $unresolved[$relative] = 'UTF-8 として不正';
+
+                    continue;
+                }
+
+                $files[] = new ScannedFile(
+                    root: $root,
+                    relative: $relative,
+                    contents: $contents,
+                    isPhp: str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php'),
+                    extension: self::extensionOf($relative),
+                );
+            }
+        }
+
+        return self::$memoizedPopulation = new ScanPopulation($files, $unresolved, $binaryExcluded);
+    }
+
+    /**
+     * 拡張子 (小文字)。拡張子なしは null。
+     *
+     * ★`.github/workflows/ci.yml` → `yml` / `scripts/codex` → null。
+     *   ドットで始まるだけのファイル (`.gitignore`) は拡張子なしとして扱う。
+     */
+    public static function extensionOf(string $relative): ?string
+    {
+        $basename = basename($relative);
+        $position = strrpos($basename, '.');
+        if ($position === false || $position === 0) {
+            return null;
+        }
+
+        return strtolower(substr($basename, $position + 1));
+    }
+
+    /**
+     * git 追跡下の相対パス (root 配下)。
+     *
+     * ★`is_file()` 判定はここでは**行わない** (捨てずに `unresolved` へ入れるため
+     *   `population()` 側の責務にする)。
+     *
+     * @return list<string>
+     */
+    private static function trackedPaths(string $repositoryRoot, string $root): array
+    {
+        $process = new Process(['git', 'ls-files', '-z', '--', $root], $repositoryRoot);
+        $process->run();
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
+        }
+
+        $paths = [];
+        foreach (explode("\0", $process->getOutput()) as $relative) {
+            if ($relative === '') {
+                continue;
+            }
+            $paths[] = $relative;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
new file mode 100644
index 00000000..b8ec25be
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
@@ -0,0 +1,716 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use ParseError;
+use Tests\Support\PhpTokenScan;
+
+/**
+ * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
+ *
+ * ★語彙一致は `TOKEN_CHARACTERS` で分割した run のトークン完全一致で判定する
+ *   (正規表現の語境界にも素の部分文字列一致にも頼らない。AGENTS.md「静的検査の共通規約」(e))。
+ *   区切りは**宣言した文字集合の外のすべてのバイト**であり、UTF-8 の多バイト文字は
+ *   すべて区切りになる (ASCII 以外はトークン文字に入れていない)。
+ * ★クラス参照は完全修飾名 (ASCII 大小無視) で突き合わせる (同 (a))。解決は `PhpNameResolver`。
+ * ★PHP は「文字列リテラル」ではなく **lexeme** を見る。文字列リテラルだけに限ると
+ *   `public bool $imageSourceDocumentsEnabled;` や `const OCR_ANALYSIS_ENABLED = true;` での
+ *   復活を検出できない。
+ * ★PHP は**構文検証を先に行い**、`ParseError` を投げるファイルは未解決にする (fail-closed)。
+ *   捕まえるのは `ParseError` **だけ**である (親型 `\Error` まで捕まえると、予期しない実行時障害まで
+ *   「解析未解決」へ変換してしまい、本来テストを落とすべき異常が別の意味に化ける)。
+ *   正規化は既存の単一出典 `Tests\Support\PhpTokenScan::normalize()` を使う (挙動は変えない)。
+ *
+ * ★**保証しないもの (検出力を誇張しない)**:
+ *   - 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には沈黙する。
+ *   - PHP のコメント / docblock の中では沈黙する (`normalize()` が落とすため)。
+ *   - **middleware 位置に現れる変数・式** (`->middleware($alias)` /
+ *     `->middleware('throttle:'.$limiter)`) は**クラス参照でも文字列リテラルでもない**ため
+ *     母集団に入らない。これは許可一覧ではなく**規則の段階での定義**である
+ *     (`X::class` 構文だけをクラス参照として扱い、受け手が名前でないものは未解決にする)。
+ *     実体化した route については実行時層 (`PasswordConfirmMiddlewareAbsenceTest`) が補完する。
+ *   - `FqcnMethodReference` は `クラス部::メソッド名` が**空白を挟まず**並んでいる形だけを見る。
+ *   - NUL を含むファイルは母集団に入らない (`RemovedSurfaceScanTargets`。利用側は 0 件を要求する)。
+ * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。利用側 gate は必ず
+ *   `ScanOutcome::mergeUnresolved()` で空を要求すること。
+ */
+final class RemovedSurfaceScanner
+{
+    /**
+     * トークン文字の集合。**これ以外のバイトはすべて区切り**である。
+     * 生テキストはこの集合の**最長の連なり (run)** へ分割される。
+     */
+    private const string TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';
+
+    /**
+     * 完全修飾参照専用のトークン文字集合 (`\` を含み `.` `-` を含まない)。
+     *
+     * `TOKEN_CHARACTERS` では `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
+     * 原理的に一致しない。専用の集合でクラス部とメソッド部を構文的に切り出す。
+     */
+    private const string FQCN_TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';
+
+    /**
+     * M1: middleware 位置を作る呼び出し名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_CALL_NAMES = [
+        'middleware', 'withoutmiddleware', 'middlewaregroup', 'appendtogroup', 'prependtogroup', 'alias',
+    ];
+
+    /**
+     * M3: middleware 位置を作るプロパティ名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_PROPERTY_NAMES = [
+        '$middleware', '$middlewaregroups', '$middlewarepriority',
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * Tier 2: 生テキストを run へ分割してトークン完全一致で走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanText(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+
+        foreach ($files as $file) {
+            if ($term->mode === TermMatchMode::FqcnMethodReference) {
+                foreach (self::fqcnMethodOccurrences($file, $term) as $occurrence) {
+                    $occurrences[] = $occurrence;
+                }
+
+                continue;
+            }
+
+            if ($term->mode === TermMatchMode::FqcnReference) {
+                foreach (self::fqcnOccurrences($file, $term) as $occurrence) {
+                    $occurrences[] = $occurrence;
+                }
+
+                continue;
+            }
+
+            foreach (self::runs($file->contents, self::TOKEN_CHARACTERS) as $run) {
+                if (! self::runMatches($run['text'], $term)) {
+                    continue;
+                }
+                $occurrences[] = new Occurrence(
+                    $file->relative,
+                    self::lineAt($file->contents, $run['offset']),
+                    $run['text'],
+                );
+            }
+        }
+
+        return new ScanOutcome($occurrences, []);
+    }
+
+    /**
+     * Tier 1: PHP の lexeme (識別子・変数・定数・文字列・heredoc・名前) を走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanPhpLexemes(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+
+            foreach ($tokens as $token) {
+                $lexeme = self::lexemeOf($token);
+                if ($lexeme === null) {
+                    continue;
+                }
+                foreach (self::runs($lexeme, self::TOKEN_CHARACTERS) as $run) {
+                    if (! self::runMatches($run['text'], $term)) {
+                        continue;
+                    }
+                    $occurrences[] = new Occurrence($file->relative, $token['line'], $run['text']);
+                }
+            }
+        }
+
+        return new ScanOutcome($occurrences, $unresolved);
+    }
+
+    /**
+     * Tier 1: **middleware 位置**に現れる alias 文字列 / クラス参照を返す。
+     *
+     * middleware 位置の定義 (有限。これ以外は母集団に入らない):
+     *   M1 呼び出し名が `middleware` / `withoutMiddleware` / `middlewareGroup` /
+     *      `appendToGroup` / `prependToGroup` / `alias` の引数領域
+     *   M2 キー名が `middleware` を部分文字列として含む (ASCII 大小無視) 配列要素の値の領域
+     *   M3 プロパティ `$middleware` / `$middlewareGroups` / `$middlewarePriority` の初期化式の領域
+     *
+     * 領域からは **`X::class` 構文のクラス参照**と**文字列リテラル**だけを取り出す。
+     * 受け手が名前でない `X::class` (`$cls::class`) は未解決にする。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MiddlewareReference>
+     */
+    public static function scanMiddlewarePositions(array $files): ScanOutcome
+    {
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            /** @var array<int, bool> $marks */
+            $marks = [];
+            for ($i = 0; $i < $count; $i++) {
+                $id = $tokens[$i]['id'];
+                $text = $tokens[$i]['text'];
+
+                if ($id === T_STRING
+                    && in_array(strtolower($text), self::MIDDLEWARE_CALL_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '(')) {
+                    $close = self::matchingBracket($tokens, $i + 1);
+                    if ($close === null) {
+                        $unresolved[$file->relative] = 'middleware 呼び出しの括弧の対応を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $close - 1);
+
+                    continue;
+                }
+
+                if ($id === T_CONSTANT_ENCAPSED_STRING
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_DOUBLE_ARROW
+                    && str_contains(strtolower(self::unquote($text)), 'middleware')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware キーの値の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+
+                    continue;
+                }
+
+                if ($id === T_VARIABLE
+                    && in_array(strtolower($text), self::MIDDLEWARE_PROPERTY_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '=')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware プロパティの初期化式の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                if (! isset($marks[$i])) {
+                    continue;
+                }
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::AliasString,
+                        self::unquote($token['text']),
+                        null,
+                    );
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON && isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === T_CLASS) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            'middleware 位置のクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::ClassReference,
+                        $tokens[$i - 1]['text'],
+                        ltrim($resolved, '\\'),
+                    );
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
+     *
+     * ★対象クラスの宣言が trait を取り込んでいたら**未解決**にする (v1 は trait-use graph を
+     *   扱わないため、メソッドが混入しているかを静的に判定できない)。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MethodReference>
+     */
+    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome
+    {
+        $targetFqcn = strtolower(ltrim($fqcn, '\\'));
+        $targetMethod = strtolower($method);
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            foreach ($resolver->typeDeclarationsOf($fqcn) as $declaration) {
+                if ($declaration['usesTraits']) {
+                    $unresolved[$file->relative] =
+                        '対象クラスが trait を取り込んでおり、メソッドの混入を静的に判定できない';
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_FUNCTION) {
+                    $nameIndex = self::isReturnByReferenceMarker($tokens, $i + 1) ? $i + 2 : $i + 1;
+                    if (isset($tokens[$nameIndex])
+                        && $tokens[$nameIndex]['id'] === T_STRING
+                        && strtolower($tokens[$nameIndex]['text']) === $targetMethod) {
+                        $type = $resolver->typeAt($i);
+                        // ★型の**本体の直下**にある宣言だけをメソッド宣言と見なす。
+                        //   メソッドの中で宣言された名前付き関数や、型の中に置いた無名クラスの
+                        //   メソッドは深さが違うので誤検出しない。
+                        if ($type !== null
+                            && strtolower($type['fqcn']) === $targetFqcn
+                            && $resolver->depthAt($i) === $type['bodyDepth']) {
+                            $references[] = new MethodReference(
+                                $file->relative,
+                                $token['line'],
+                                MethodReferenceKind::Declaration,
+                            );
+                        }
+                    }
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_STRING
+                    && strtolower($tokens[$i + 1]['text']) === $targetMethod) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            '`::%s` を伴うクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $method,
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    if (strtolower(ltrim($resolved, '\\')) === $targetFqcn) {
+                        $references[] = new MethodReference(
+                            $file->relative,
+                            $token['line'],
+                            MethodReferenceKind::StaticCall,
+                        );
+                    }
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * 生テキストに撤去語と一致する run が含まれるか。
+     *
+     * ★利用側 gate が「middleware 位置の alias 文字列」のような**値**を絞り込むための入口で、
+     *   判定は `scanText()` / `scanPhpLexemes()` と**同じ 1 本のトークン一致**を通る
+     *   (同じ判定を 2 本持たない)。
+     */
+    public static function textMatches(string $text, RemovedTerm $term): bool
+    {
+        if ($term->mode === TermMatchMode::FqcnMethodReference) {
+            return self::fqcnMethodOccurrences(
+                new ScannedFile('memory', 'memory', $text, false, null),
+                $term,
+            ) !== [];
+        }
+
+        if ($term->mode === TermMatchMode::FqcnReference) {
+            return self::fqcnOccurrences(
+                new ScannedFile('memory', 'memory', $text, false, null),
+                $term,
+            ) !== [];
+        }
+
+        foreach (self::runs($text, self::TOKEN_CHARACTERS) as $run) {
+            if (self::runMatches($run['text'], $term)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 生テキストを宣言した文字集合の最長連なり (run) へ分割する。
+     *
+     * @return list<array{text: string, offset: int}>
+     */
+    private static function runs(string $text, string $tokenCharacters): array
+    {
+        $runs = [];
+        $length = strlen($text);
+        $start = null;
+
+        for ($i = 0; $i < $length; $i++) {
+            if (str_contains($tokenCharacters, $text[$i])) {
+                if ($start === null) {
+                    $start = $i;
+                }
+
+                continue;
+            }
+            if ($start !== null) {
+                $runs[] = ['text' => substr($text, $start, $i - $start), 'offset' => $start];
+                $start = null;
+            }
+        }
+        if ($start !== null) {
+            $runs[] = ['text' => substr($text, $start), 'offset' => $start];
+        }
+
+        return $runs;
+    }
+
+    /** run が撤去語と一致するか (様式ごとの完全一致)。 */
+    private static function runMatches(string $run, RemovedTerm $term): bool
+    {
+        return match ($term->mode) {
+            TermMatchMode::ExactRun => $run === $term->term,
+            TermMatchMode::RunSegment => in_array($term->term, explode('.', $run), true),
+            // 完全修飾参照は専用のトークン文字集合で判定する
+            // (fqcnMethodOccurrences / fqcnOccurrences が担当する)
+            TermMatchMode::FqcnMethodReference, TermMatchMode::FqcnReference => false,
+        };
+    }
+
+    /**
+     * `クラス部::メソッド名` の完全一致 (ASCII 大小無視・先頭 `\` は落として正規化)。
+     *
+     * @return list<Occurrence>
+     */
+    private static function fqcnMethodOccurrences(ScannedFile $file, RemovedTerm $term): array
+    {
+        $parts = explode('::', $term->term, 2);
+        if (count($parts) !== 2) {
+            return [];
+        }
+        $targetClass = self::normalizeFqcn($parts[0]);
+        $targetMethod = strtolower($parts[1]);
+
+        /** @var array<int, string> $endingAt */
+        $endingAt = [];
+        /** @var array<int, string> $startingAt */
+        $startingAt = [];
+        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
+            $startingAt[$run['offset']] = $run['text'];
+            $endingAt[$run['offset'] + strlen($run['text'])] = $run['text'];
+        }
+
+        $occurrences = [];
+        $offset = 0;
+        while (($position = strpos($file->contents, '::', $offset)) !== false) {
+            $offset = $position + 2;
+            if (! isset($endingAt[$position], $startingAt[$position + 2])) {
+                continue;
+            }
+            $class = self::normalizeFqcn($endingAt[$position]);
+            $method = strtolower($startingAt[$position + 2]);
+            if ($class !== $targetClass || $method !== $targetMethod) {
+                continue;
+            }
+            $occurrences[] = new Occurrence(
+                $file->relative,
+                self::lineAt($file->contents, $position),
+                $endingAt[$position].'::'.$startingAt[$position + 2],
+            );
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * 完全修飾クラス名そのものの完全一致 (メソッド名を伴わない)。
+     *
+     * @return list<Occurrence>
+     */
+    private static function fqcnOccurrences(ScannedFile $file, RemovedTerm $term): array
+    {
+        $target = self::normalizeFqcn($term->term);
+
+        $occurrences = [];
+        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
+            if (self::normalizeFqcn($run['text']) !== $target) {
+                continue;
+            }
+            $occurrences[] = new Occurrence(
+                $file->relative,
+                self::lineAt($file->contents, $run['offset']),
+                $run['text'],
+            );
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * 完全修飾名の正規化 (先頭の逆斜線を落とし、連続する逆斜線を 1 つへ畳み、ASCII 小文字化)。
+     *
+     * ★連続の畳み込みは二重引用符の文字列リテラルのエスケープ表記を吸収するためで、
+     *   **拾いすぎる方向**の正規化である (見逃す方向へは倒れない)。
+     */
+    private static function normalizeFqcn(string $name): string
+    {
+        $collapsed = preg_replace('/\\\\+/', '\\', $name);
+
+        return strtolower(ltrim($collapsed ?? $name, '\\'));
+    }
+
+    /**
+     * PHP を構文検証してから正規化トークン列を返す。`ParseError` は未解決。
+     *
+     * @param  array<string, string>  $unresolved
+     * @return list<array{id: int|null, text: string, line: int}>|null
+     */
+    private static function tokenize(ScannedFile $file, array &$unresolved): ?array
+    {
+        try {
+            token_get_all($file->contents, TOKEN_PARSE); // ★構文検証のみ (結果は捨てる)
+        } catch (ParseError $error) {                    // ★ParseError だけを捕まえる
+            $unresolved[$file->relative] = 'PHP のトークン化に失敗: '.$error->getMessage();
+
+            return null;
+        }
+
+        return PhpTokenScan::normalize($file->contents);
+    }
+
+    /**
+     * 撤去語と突き合わせる lexeme (対象外のトークンは null)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function lexemeOf(array $token): ?string
+    {
+        return match ($token['id']) {
+            T_VARIABLE => substr($token['text'], 1),
+            T_CONSTANT_ENCAPSED_STRING => self::unquote($token['text']),
+            T_STRING, T_ENCAPSED_AND_WHITESPACE,
+            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => $token['text'],
+            default => null,
+        };
+    }
+
+    /** 文字列リテラルの引用符を落とす (エスケープの復元はしない)。 */
+    private static function unquote(string $literal): string
+    {
+        $value = $literal;
+        if ($value !== '' && (strtolower($value[0]) === 'b')) {
+            $value = substr($value, 1);
+        }
+        if (strlen($value) >= 2) {
+            $first = $value[0];
+            $last = $value[strlen($value) - 1];
+            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
+                $value = substr($value, 1, -1);
+            }
+        }
+
+        return $value;
+    }
+
+    /** バイト位置の行番号 (1 起点)。 */
+    private static function lineAt(string $contents, int $offset): int
+    {
+        return substr_count($contents, "\n", 0, $offset) + 1;
+    }
+
+    /**
+     * 参照返しの印 (`function &foo()` の `&`) かどうか。
+     *
+     * ★PHP 8 は `&` を文脈で 3 通りにトークン化する
+     *   (素の `&` / `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` /
+     *   `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`)。素の文字トークンだけを見ると
+     *   `public static function &foo()` の宣言を**見逃す** (fail-open)。3 通りとも認める。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isReturnByReferenceMarker(array $tokens, int $index): bool
+    {
+        if (! isset($tokens[$index])) {
+            return false;
+        }
+        if (self::isChar($tokens, $index, '&')) {
+            return true;
+        }
+
+        return in_array(
+            $tokens[$index]['id'],
+            [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG],
+            true,
+        );
+    }
+
+    /**
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isChar(array $tokens, int $index, string $char): bool
+    {
+        return isset($tokens[$index]) && $tokens[$index]['id'] === null && $tokens[$index]['text'] === $char;
+    }
+
+    /**
+     * 開き括弧に対応する閉じ括弧の位置 (対応が取れなければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingBracket(array $tokens, int $openIndex): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $openIndex; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                $depth--;
+                if ($depth === 0) {
+                    return $k;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 値の式が終わる位置 (配列リテラルなら閉じ括弧、単一式なら深さ 0 の区切りの手前)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function valueEnd(array $tokens, int $from): ?int
+    {
+        if (! isset($tokens[$from])) {
+            return null;
+        }
+        if (self::isChar($tokens, $from, '[')) {
+            return self::matchingBracket($tokens, $from);
+        }
+        if ($tokens[$from]['id'] === T_ARRAY && self::isChar($tokens, $from + 1, '(')) {
+            return self::matchingBracket($tokens, $from + 1);
+        }
+
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                if ($depth === 0) {
+                    return $k - 1;
+                }
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $tokens[$k]['id'] === null && in_array($tokens[$k]['text'], [',', ';'], true)) {
+                return $k - 1;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 括弧の深さの増減 (文字列補間が開く `{` と属性の `#[` を開き括弧として数える)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function bracketDelta(array $token): int
+    {
+        if ($token['id'] === null) {
+            if (in_array($token['text'], ['(', '[', '{'], true)) {
+                return 1;
+            }
+            if (in_array($token['text'], [')', ']', '}'], true)) {
+                return -1;
+            }
+
+            return 0;
+        }
+
+        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) ? 1 : 0;
+    }
+
+    /**
+     * @param  array<int, bool>  $marks
+     */
+    private static function markRange(array &$marks, int $from, int $to): void
+    {
+        for ($i = $from; $i <= $to; $i++) {
+            $marks[$i] = true;
+        }
+    }
+}

```

上記を踏まえて再レビューし、最後に全体判定 (`APPROVED` / `CHANGES_REQUESTED`) を 1 語で明記せよ。
これが合議の最終ラウンド (Round 3 / 上限 3) である。
