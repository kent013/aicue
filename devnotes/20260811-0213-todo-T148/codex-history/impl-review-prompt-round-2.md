# Round 2: Round 1 指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] 検出 B の名指し免除が DB クエリ形の判定を素通しする

- 判断: **対応する**
- 根拠: 指摘は正しい。免除の前提は「`->adoptedTake` のプロパティフェッチを持たない」だけだったため、
  免除ファイル内に `whereHas('adoptedTake', fn ($q) => $q->where('status', TakeStatus::Ready->value))` と
  書けば gate を素通りできた。これは動的アクセスのような特殊形ではなく Eloquent で最も普通に書ける形で、
  「判定式を書いてよいのは Canonical 1 ファイル」という不変条件を実質的に弱めていた。
  Codex 提示の選択肢 1 (PipelineSmokeCommand の分割) は、bug-hunt 用の開発コマンドを gate の都合で
  分解することになり本末転倒 (思考原則 2「今必要なものだけ作る」に反する) なので、選択肢 2 を採った。
- 対応内容:
  - 免除の前提を **2 層**にした (`criterionExemptionPremiseHolds()`)。
    - 前提 1 (in-memory 形): 検出 A' (`->adoptedTake` / `?->adoptedTake`) を持たない
    - 前提 2 (DB クエリ形): `'adoptedTake'` を引数に取る**呼び出しの引数リストの中**に
      `TakeStatus::Ready` も `'status'` も現れない
      (`hasCriterionInRelationArgument()` が括弧の対応を取って引数リストを切り出して判定する。
      `whereHas` のクロージャ形も `whereRelation('adoptedTake', 'status', 'ready')` も捕まる)
  - scanner 自己検証テストを 1 件追加 (クロージャ形 / whereRelation 形を true、
    素の `doesntHave('adoptedTake')->count()` と「relation 引数の外にある ready 参照」を false)
  - ケース 8 のテスト名と失敗メッセージを 2 層前提に合わせて更新
  - **mutation で実証**: M10 (免除ファイルの `doesntHave` を DB クエリ形の判定に変える) を実施し、
    前提 2 追加前は green、追加後は ケース 8 が fail することを確認した (mutation-evidence.md M10)
  - 「保証しないもの」も更新 (前提 2 が見るのは `'adoptedTake'` を含む呼び出しの引数リストだけで、
    relation の id を別クエリで取り出して後段で status を判定する形には沈黙する)

## [Warning] `AdoptedTakeReferenceInventory` の PipelineSmokeCommand の根拠が誤解を招く

- 判断: **対応する**
- 根拠: 指摘のとおり。「ready 状態は見ず」は同ファイルに `TakeStatus::Ready` が実在する事実と
  読み手の中で衝突し、免除判断の材料として誤解を招く。
- 対応内容: 根拠を「**adoptedTake 参照側は** ready を見ない (別の `TakeStatus::Ready` 参照は
  登録直後のテイク自身の確認であって採用テイクの充足判定ではない)」へ書き換えた。
  併せて `COOCCURRENCE_EXEMPT` 側の根拠も「in-memory 形も DB クエリ形も持たないことを
  `criterionExemptionPremiseHolds()` が機械検査する」と、何が機械保証なのかを明示する文面にした。

## その他

Codex は他の全ファイルを OK と判定し、`playbackJobId` → `playbackJob` の追随、
`placeholder_cut_count` の manifest 由来記録、preview を 422 にしない非対称、ボタン非 disabled 方針、
`null` と `0` の扱い、`coverage` を project_member に返す点についても設計と一致していると確認した。
これらは変更していない。


## 修正後の該当ファイル全体 (Round 1 からの差分ではなく、main からの累積差分)

```diff
diff --git a/app/Support/Security/AdoptedTakeReferenceInventory.php b/app/Support/Security/AdoptedTakeReferenceInventory.php
new file mode 100644
index 0000000..7f33c86
--- /dev/null
+++ b/app/Support/Security/AdoptedTakeReferenceInventory.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Security;
+
+use App\Enums\Security\AdoptedTakeReferenceKind;
+
+/**
+ * `adoptedTake` relation を参照する app/ 配下ファイルの目録 (deny-by-default。T148)。
+ *
+ * 守る不変条件:
+ *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
+ *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
+ *
+ * 強制は `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`
+ * (exact-fit: 未登録の参照も、参照実体を失った stale entry も fail させる)。
+ */
+final class AdoptedTakeReferenceInventory
+{
+    /**
+     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
+     *
+     * @return array<string, array{kind: AdoptedTakeReferenceKind, rationale: string}>
+     */
+    public static function entries(): array
+    {
+        return [
+            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
+                'kind' => AdoptedTakeReferenceKind::Canonical,
+                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
+                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
+            ],
+            'Services/Manual/CutSequencer.php' => [
+                'kind' => AdoptedTakeReferenceKind::RelationWiring,
+                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
+                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
+            ],
+            'Services/Manual/RenderJobService.php' => [
+                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
+                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
+                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
+            ],
+            'Services/Manual/RenderPipeline.php' => [
+                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
+                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
+                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
+            ],
+            'Models/Cut.php' => [
+                'kind' => AdoptedTakeReferenceKind::RelationWiring,
+                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
+                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
+            ],
+            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
+                    .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。',
+            ],
+            'Http/Controllers/Capture/CaptureManualController.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
+                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
+            ],
+            'Services/Dashboard/DashboardService.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'whereDoesntHave(adoptedTake) による撮影待ち件数の集計。'
+                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
+            ],
+            'Console/Commands/Development/PipelineSmokeCommand.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'bug-hunt のパイプライン通し確認で未採用カット件数を数えるだけの'
+                    .'開発用コマンド。adoptedTake 参照側は ready を見ない (別の TakeStatus::Ready '
+                    .'参照は登録直後のテイク自身の確認であって採用テイクの充足判定ではない)。',
+            ],
+        ];
+    }
+}
diff --git a/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
new file mode 100644
index 0000000..6cd0732
--- /dev/null
+++ b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
@@ -0,0 +1,450 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\AdoptedTakeReferenceKind;
+use App\Support\Security\AdoptedTakeReferenceInventory;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * 採用テイク充足判定の単一化 (T148 / bug-hunt F-1-01) の deny-by-default 目録。
+ *
+ * 不変条件:
+ *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
+ *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
+ *   `adoptedTake` に触れる app/ 配下のファイルは、区分と 30 文字以上の根拠を付けて
+ *   `AdoptedTakeReferenceInventory` へ登録しなければならない。
+ *
+ * 走査は PhpTokenScan::normalize() ベース (コメント / docblock 内の出現は数えない)。
+ *
+ * 検出 A (参照の母集団): 識別子 `adoptedTake` (プロパティフェッチ) または
+ *   文字列リテラル 'adoptedTake' (with / whereHas / whereDoesntHave / doesntHave の引数) を含む .php
+ * 検出 A' (プロパティフェッチ形): `->adoptedTake` / `?->adoptedTake` のみ
+ * 検出 B (判定式の同居): 検出 A に該当し、**かつ** `TakeStatus::Ready` を含むファイル
+ *
+ * 検出 B の期待集合は Canonical 1 ファイル + 名指し免除だけである。免除には
+ * **機械検査される 2 層の前提**が付く (どちらか一方でも崩れたら免除は無効):
+ *   前提 1 (in-memory 形): 検出 A' を持たない = relation の実体を一度も参照しないため
+ *     `$take->status !== TakeStatus::Ready` を書きようがない
+ *   前提 2 (DB クエリ形): `'adoptedTake'` を引数に取る呼び出しの**引数リストの中**に
+ *     `TakeStatus::Ready` も `'status'` も現れない =
+ *     `whereHas('adoptedTake', fn ($q) => $q->where('status', ...))` を書きようがない
+ *
+ * 保証しないもの (誇張しない):
+ * - 静的走査であり、文字列変数経由の relation 名 (`$rel = 'adopted'.'Take'`)・動的プロパティ
+ *   アクセス・`Take::query()->where('status', ...)` の別経路には**沈黙する**
+ * - 検出 B は「同一ファイル内に TakeStatus::Ready が出現するか」という近似であり、
+ *   別ファイルへ切り出して同じ判定を書く経路は検出できない
+ * - 前提 2 が見るのは `'adoptedTake'` を含む**呼び出しの引数リスト**だけである。免除ファイルが
+ *   relation の id を別クエリで取り出して後段で status を判定する形には沈黙する
+ */
+final class AdoptedTakeCriterionScanner
+{
+    /**
+     * 検出 B の名指し免除 (app/ 相対パス => 30 文字以上の根拠)。
+     *
+     * 「同一ファイル内に adoptedTake 参照と TakeStatus::Ready が同居する」だけの近似では
+     * 拾ってしまう既存の無関係な同居を、前提付きで明示的に許す枠。
+     * `criterionExemptionPremiseHolds()` が機械的に前提を検査する。
+     */
+    public const COOCCURRENCE_EXEMPT = [
+        'Console/Commands/Development/PipelineSmokeCommand.php' => '未採用カット数の集計 (doesntHave の文字列リテラル) と、撮影段で登録した'
+            .'テイク自身の ready 確認が同一ファイルに並ぶだけで、両者は同じ式ではない。'
+            .'in-memory 形 (プロパティフェッチ) も DB クエリ形 (relation 引数内の status 判定) も'
+            .'持たないことを criterionExemptionPremiseHolds() が機械検査する。',
+    ];
+
+    /** @return list<string> 検出 A に該当する app/ 相対パス (昇順) */
+    public static function referencingFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasAnyReference($tokens));
+    }
+
+    /** @return list<string> 検出 A' (プロパティフェッチ形) に該当する app/ 相対パス */
+    public static function propertyFetchFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasPropertyFetch($tokens));
+    }
+
+    /** @return list<string> 検出 B (判定式の同居) に該当する app/ 相対パス */
+    public static function criterionFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasAnyReference($tokens)
+            && self::hasReadyStatusReference($tokens));
+    }
+
+    /**
+     * 免除の前提 (2 層。どちらか一方でも崩れたら免除は無効):
+     *
+     * 1. **in-memory 形**: そのファイルは relation の実体を一度も参照しない
+     *    (`->adoptedTake` が無い = `$take->status !== TakeStatus::Ready` を書きようがない)
+     * 2. **DB クエリ形**: `'adoptedTake'` を引数に取る呼び出し
+     *    (`whereHas` / `doesntHave` / `whereRelation` 等) の**引数リストの中**に
+     *    `TakeStatus::Ready` も `'status'` も現れない
+     *    (= `whereHas('adoptedTake', fn ($q) => $q->where('status', ...))` を書けない)
+     */
+    public static function criterionExemptionPremiseHolds(string $relative): bool
+    {
+        if (in_array($relative, self::propertyFetchFiles(), true)) {
+            return false;
+        }
+
+        return ! in_array($relative, self::relationArgumentCriterionFiles(), true);
+    }
+
+    /** @return list<string> 前提 2 に違反する (relation 引数の中で status 判定をしている) app/ 相対パス */
+    public static function relationArgumentCriterionFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasCriterionInRelationArgument($tokens));
+    }
+
+    /**
+     * `'adoptedTake'` を含む呼び出しの引数リスト内に `TakeStatus::Ready` または `'status'` が
+     * 同居するか (DB クエリ形の判定の検出)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasCriterionInRelationArgument(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING
+                || trim($tokens[$i]['text'], "'\"") !== 'adoptedTake') {
+                continue;
+            }
+
+            $open = self::enclosingOpenParen($tokens, $i);
+            if ($open === null) {
+                continue;
+            }
+            $close = self::matchingCloseParen($tokens, $open);
+            if ($close === null) {
+                continue;
+            }
+
+            /** @var list<array{id: int|null, text: string, line: int}> $arguments */
+            $arguments = array_values(array_slice($tokens, $open + 1, $close - $open - 1));
+            if (self::hasReadyStatusReference($arguments)) {
+                return true;
+            }
+            foreach ($arguments as $argument) {
+                if ($argument['id'] === T_CONSTANT_ENCAPSED_STRING
+                    && trim($argument['text'], "'\"") === 'status') {
+                    return true;
+                }
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * $index を囲む (未閉じの) 直近の `(` の位置。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function enclosingOpenParen(array $tokens, int $index): ?int
+    {
+        $depth = 0;
+        for ($i = $index - 1; $i >= 0; $i--) {
+            if ($tokens[$i]['id'] !== null) {
+                continue;
+            }
+            if ($tokens[$i]['text'] === ')') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$i]['text'] === '(') {
+                if ($depth === 0) {
+                    return $i;
+                }
+                $depth--;
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * $open に対応する `)` の位置。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingCloseParen(array $tokens, int $open): ?int
+    {
+        $count = count($tokens);
+        $depth = 0;
+        for ($i = $open + 1; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== null) {
+                continue;
+            }
+            if ($tokens[$i]['text'] === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$i]['text'] === ')') {
+                if ($depth === 0) {
+                    return $i;
+                }
+                $depth--;
+            }
+        }
+
+        return null;
+    }
+
+    /** @param  list<array{id: int|null, text: string, line: int}>  $tokens */
+    public static function hasAnyReference(array $tokens): bool
+    {
+        foreach ($tokens as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === 'adoptedTake') {
+                return true;
+            }
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING
+                && trim($token['text'], "'\"") === 'adoptedTake') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** @param  list<array{id: int|null, text: string, line: int}>  $tokens */
+    public static function hasPropertyFetch(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 1; $i++) {
+            $operator = $tokens[$i]['id'];
+            if ($operator !== T_OBJECT_OPERATOR && $operator !== T_NULLSAFE_OBJECT_OPERATOR) {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] === T_STRING && $tokens[$i + 1]['text'] === 'adoptedTake') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * `TakeStatus::Ready` の参照 (部分修飾・完全修飾も末尾セグメントで判定する)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasReadyStatusReference(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 2; $i++) {
+            $token = $tokens[$i];
+            if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+                continue;
+            }
+            $segments = explode('\\', ltrim($token['text'], '\\'));
+            if (end($segments) !== 'TakeStatus') {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
+                continue;
+            }
+            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Ready') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * @param  callable(list<array{id: int|null, text: string, line: int}>): bool  $matches
+     * @return list<string>
+     */
+    private static function scan(callable $matches): array
+    {
+        $appDir = self::appDir();
+        $found = [];
+        foreach (self::phpFiles($appDir) as $path) {
+            $source = file_get_contents($path);
+            if ($source === false) {
+                throw new RuntimeException("Failed to read PHP source: {$path}");
+            }
+            if ($matches(PhpTokenScan::normalize($source))) {
+                $found[] = substr($path, strlen($appDir) + 1);
+            }
+        }
+        sort($found);
+
+        return $found;
+    }
+
+    public static function appDir(): string
+    {
+        $appDir = realpath(__DIR__.'/../../app');
+        if (! is_string($appDir)) {
+            throw new RuntimeException('app/ ディレクトリを解決できません');
+        }
+
+        return $appDir;
+    }
+
+    /** @return list<string> */
+    public static function phpFiles(string $dir): array
+    {
+        $files = [];
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
+        );
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if ($file->isFile() && $file->getExtension() === 'php') {
+                $files[] = $file->getPathname();
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+
+    /** @return list<string> Canonical 区分に登録された app/ 相対パス */
+    public static function canonicalFiles(): array
+    {
+        $files = [];
+        foreach (AdoptedTakeReferenceInventory::entries() as $relative => $entry) {
+            if ($entry['kind'] === AdoptedTakeReferenceKind::Canonical) {
+                $files[] = $relative;
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+}
+
+test('ケース 1: adoptedTake を参照する app/ のファイルはすべて目録に登録されている', function (): void {
+    $registered = array_keys(AdoptedTakeReferenceInventory::entries());
+    $unregistered = array_values(array_diff(AdoptedTakeCriterionScanner::referencingFiles(), $registered));
+
+    expect($unregistered)->toBe([],
+        'adoptedTake を参照する新しいファイルは AdoptedTakeReferenceInventory へ区分 + 根拠付きで'
+        .'登録してください (deny-by-default): '.implode(', ', $unregistered));
+});
+
+test('ケース 2: 目録の全エントリが実在の参照を持つ (exact-fit)', function (): void {
+    $referencing = AdoptedTakeCriterionScanner::referencingFiles();
+    $stale = array_values(array_diff(array_keys(AdoptedTakeReferenceInventory::entries()), $referencing));
+
+    expect($stale)->toBe([],
+        '参照実体を失った目録エントリは削除してください (残置すると gate が常時緑になる): '
+        .implode(', ', $stale));
+});
+
+test('ケース 3: 走査母集団が空でない (負のコントロール)', function (): void {
+    expect(AdoptedTakeCriterionScanner::phpFiles(AdoptedTakeCriterionScanner::appDir()))->not->toBeEmpty();
+    expect(count(AdoptedTakeCriterionScanner::referencingFiles()))
+        ->toBeGreaterThanOrEqual(5, '走査が壊れて母集団が縮んでいます (規則が空振りしている)');
+});
+
+test('ケース 4: ready 判定を同居させてよいのは Canonical と名指し免除だけである', function (): void {
+    $allowed = array_merge(
+        AdoptedTakeCriterionScanner::canonicalFiles(),
+        array_keys(AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT),
+    );
+    $violations = array_values(array_diff(AdoptedTakeCriterionScanner::criterionFiles(), $allowed));
+
+    expect($violations)->toBe([],
+        '「採用済みかつ ready のテイクを持つか」の判定式は AdoptedReadyTakeCoverage だけが持てます。'
+        .'判定は isMissing() へ委譲してください: '.implode(', ', $violations));
+});
+
+test('ケース 5: Canonical ファイルは実際に判定式を持つ (規則の空振り防止)', function (): void {
+    $criterion = AdoptedTakeCriterionScanner::criterionFiles();
+
+    expect($criterion)->not->toBeEmpty();
+    foreach (AdoptedTakeCriterionScanner::canonicalFiles() as $canonical) {
+        expect(in_array($canonical, $criterion, true))->toBeTrue(
+            "Canonical 登録の {$canonical} に判定式がありません (検出規則が空振りしています)");
+    }
+});
+
+test('ケース 6: 目録の根拠は 30 文字以上ある', function (): void {
+    foreach (AdoptedTakeReferenceInventory::entries() as $relative => $entry) {
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30,
+            "{$relative} の根拠が短すぎます (30 文字以上)");
+    }
+    foreach (AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT as $relative => $rationale) {
+        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30,
+            "{$relative} の免除根拠が短すぎます (30 文字以上)");
+    }
+});
+
+test('ケース 7: Canonical 区分の登録は 1 件だけである', function (): void {
+    expect(AdoptedTakeCriterionScanner::canonicalFiles())
+        ->toBe(['Services/Manual/AdoptedReadyTakeCoverage.php']);
+});
+
+test('ケース 8: 検出 B の免除は in-memory 形・DB クエリ形いずれの判定も持たない前提を満たす', function (): void {
+    $criterion = AdoptedTakeCriterionScanner::criterionFiles();
+
+    foreach (AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT as $relative => $rationale) {
+        // stale な免除を残さない (免除対象が検出 B から外れたら免除ごと消す)
+        expect(in_array($relative, $criterion, true))->toBeTrue(
+            "{$relative} は検出 B に該当しません。免除エントリを削除してください");
+        expect(AdoptedTakeCriterionScanner::criterionExemptionPremiseHolds($relative))->toBeTrue(
+            "{$relative} が採用テイクの ready 判定 (プロパティフェッチ形 / relation 引数内の status 形) を"
+            .'持ち始めました。免除の前提が崩れています');
+    }
+});
+
+test('scanner 自己検証: DB クエリ形の判定 (relation 引数内の status) を検出する', function (): void {
+    $closureForm = PhpTokenScan::normalize(<<<'PHP'
+    <?php
+    $q->whereHas('adoptedTake', fn ($take) => $take->where('status', TakeStatus::Ready->value));
+    PHP);
+    $whereRelationForm = PhpTokenScan::normalize(
+        "<?php \$q->whereRelation('adoptedTake', 'status', 'ready');",
+    );
+    $benignCount = PhpTokenScan::normalize("<?php \$q->doesntHave('adoptedTake')->count();");
+    // 別の呼び出しでの ready 参照は relation 引数の外なので前提 2 には触れない
+    $unrelated = PhpTokenScan::normalize(<<<'PHP'
+    <?php
+    $q->doesntHave('adoptedTake')->count();
+    $ok = $result->take->status === TakeStatus::Ready;
+    PHP);
+
+    expect(AdoptedTakeCriterionScanner::hasCriterionInRelationArgument($closureForm))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasCriterionInRelationArgument($whereRelationForm))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasCriterionInRelationArgument($benignCount))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasCriterionInRelationArgument($unrelated))->toBeFalse();
+});
+
+test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
+    $source = <<<'PHP'
+<?php
+// $cut->adoptedTake と TakeStatus::Ready はコメント
+/** 'adoptedTake' も docblock */
+class Example {}
+PHP;
+    $tokens = PhpTokenScan::normalize($source);
+
+    expect(AdoptedTakeCriterionScanner::hasAnyReference($tokens))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($tokens))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($tokens))->toBeFalse();
+});
+
+test('scanner 自己検証: プロパティフェッチ / 文字列リテラル / ready 参照を検出する', function (): void {
+    $fetch = PhpTokenScan::normalize('<?php $take = $cut->adoptedTake;');
+    $nullsafe = PhpTokenScan::normalize('<?php $s = $cut?->adoptedTake?->status;');
+    $literal = PhpTokenScan::normalize("<?php \$q->whereDoesntHave('adoptedTake');");
+    $ready = PhpTokenScan::normalize('<?php $b = $t->status !== TakeStatus::Ready;');
+    $qualified = PhpTokenScan::normalize('<?php $b = \App\Enums\Manual\TakeStatus::Ready;');
+    $otherCase = PhpTokenScan::normalize('<?php $b = TakeStatus::Failed;');
+
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($fetch))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($nullsafe))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasPropertyFetch($literal))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::hasAnyReference($literal))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($ready))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($qualified))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::hasReadyStatusReference($otherCase))->toBeFalse();
+});

```

## 再実行した検証

- `composer fix` (Pint) : passed
- `vendor/bin/pest tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` : 11 tests / 11 passed
- `composer phpstan` : No errors (level 10)
- mutation M10 (免除ファイル内で `whereDoesntHave('adoptedTake', fn ($take) => $take->where('status', TakeStatus::Ready->value))` へ変える):
  **前提 2 を足す前は green (= 指摘どおり穴が実在した)、足した後は ケース 8 が
  「免除の前提が崩れています」で fail**。変異を戻すと 11 tests 全 green。

## 確認してほしいこと

1. [Critical] が閉じたか (免除の 2 層前提が、設計の「Canonical 1 ファイルだけが判定式を持つ」を
   実質的に守れているか)。
2. [Warning] の rationale 修正が、免除判断の材料として正確になったか。
3. 追加した token 走査 (`hasCriterionInRelationArgument` / `enclosingOpenParen` /
   `matchingCloseParen`) に誤検出・見落としの穴がないか。
4. 「保証しないもの」の記述が、実際の検出能力を過大にも過小にも言っていないか。
