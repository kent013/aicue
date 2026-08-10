# Round 3: Round 2 指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] 外部変数に切り出した relation callback が前提 2 を通過する

- 判断: **対応する**
- 根拠: 指摘は正しく、自分で入れた前提 2 (引数リスト内に `TakeStatus::Ready` / `'status'` が
  無いこと) の設計そのものが間違っていた。`$scope = fn ($t) => $t->where('status', ...);` を
  `whereHas('adoptedTake', $scope)` に渡す形は Eloquent のごく普通の書き方であり、
  引数リストだけを見る検査ではデータフロー解析なしに閉じられない。
  Codex 提示の「免除ファイルについて benign な参照形を狭く exact-fit で固定する」が、
  token 走査の範囲で唯一まっとうに閉じる方法である (思考原則: 仕組みが機能していない段階で
  値を弄らず、設計そのものを見直す)。
- 対応内容:
  - 前提 2 を**参照形そのものの exact-fit** に作り直した (`referenceShapeIsBenign()`)。
    免除ファイル内の `'adoptedTake'` の出現は**すべて**次の 4 条件を満たさなければならない:
    (a) 直前が `(` = 第 1 引数 (named argument の `relation:` 前置も弾く) /
    (b) その直前が `EXEMPT_BENIGN_CALLS` (現在は `doesntHave` のみ) のメソッド名 /
    (c) さらに直前が `->` / `?->` / `::` = メソッド呼び出し /
    (d) 直後が `)` = **引数はこれ 1 つだけ** (callback も第 2 引数も持てない)
  - 引数リスト走査版 (`hasCriterionInRelationArgument` と括弧対応ヘルパ 2 本) は削除した
    (後方互換の並走を残さない = 思考原則 3)
  - 自己検証テストを差し替え: benign (現行形) を true、直接 callback / **変数 callback** /
    `whereRelation` / 別メソッド (`whereDoesntHave`) / named argument を false と固定
  - **mutation で実証**: M10 (直接 callback) と **M11 (変数 callback)** の両方で ケース 8 が
    fail することを確認 (mutation-evidence.md)。M11 は引数リスト検査版では green だった =
    指摘どおり穴が実在した
  - ケース 8 のテスト名・失敗メッセージ・免除の根拠文面を新しい前提に合わせた

## [Warning] 「保証しないもの」が callback 切り出し形を説明していない

- 判断: **対応する (ただし記述ではなく実装で閉じた)**
- 根拠: Codex 自身が「記載だけでは不変条件は守れない」と述べているとおり、これは記述の問題ではなく
  検出境界の問題だった。実装で閉じた上で、残る本当の保証外だけを書き直す。
- 対応内容: 「保証しないもの」を実際の検出能力に合わせて書き換えた。
  - 削除: 「前提 2 が見るのは引数リストだけ」(検出境界が変わったので事実でなくなった)
  - 追加: 「前提 2 が固定するのは**免除ファイルの参照形**だけである。免除ファイルが relation を
    一切使わずに (`Take::query()` 等で) 採用テイクの status を判定する経路には沈黙する」
  - 前提 2 を形の固定にした理由 (callback 切り出しが引数リスト検査を素通りする) も冒頭コメントへ明記

## 括弧対応の妥当性について

Codex が「括弧解析そのものは妥当」と述べたヘルパ 2 本は、新しい前提 2 では不要になったため削除した
(使われないコードを残さない)。新実装は隣接 token の照合のみで括弧の対応取りをしない。


## 修正後の `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` (main からの累積差分)

```diff
diff --git a/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
new file mode 100644
index 0000000..4075df9
--- /dev/null
+++ b/tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php
@@ -0,0 +1,421 @@
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
+ *   前提 2 (参照形の exact-fit): `'adoptedTake'` の出現が**すべて**
+ *     `->doesntHave('adoptedTake')` の単独引数形である = callback も第 2 引数も持てないため
+ *     `whereHas('adoptedTake', $scope)` の形で DB 側の判定を書きようがない
+ *
+ * 前提 2 を「引数リスト内に status 判定が無いこと」ではなく**形そのものの固定**にしたのは、
+ * callback を変数へ切り出す形が引数リスト検査を素通りするためである
+ * (Codex impl-review Round 2 の指摘。データフロー解析を始めずに現在の免除理由だけを許可する)。
+ *
+ * 保証しないもの (誇張しない):
+ * - 静的走査であり、文字列変数経由の relation 名 (`$rel = 'adopted'.'Take'`)・動的プロパティ
+ *   アクセス・`Take::query()->where('status', ...)` の別経路には**沈黙する**
+ * - 検出 B は「同一ファイル内に TakeStatus::Ready が出現するか」という近似であり、
+ *   別ファイルへ切り出して同じ判定を書く経路は検出できない
+ * - 前提 2 が固定するのは**免除ファイルの参照形**だけである。免除ファイルが relation を
+ *   一切使わずに (`Take::query()` 等で) 採用テイクの status を判定する経路には沈黙する
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
+            .'in-memory 形 (プロパティフェッチ) を持たず、参照形が doesntHave の単独引数だけで'
+            .'あることを criterionExemptionPremiseHolds() が exact-fit で機械検査する。',
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
+     * 免除ファイルに許す `'adoptedTake'` の参照形 (メソッド名の allowlist)。
+     * **現に存在する benign な形だけ**を列挙する (増やすときは免除の再審査を伴う)。
+     */
+    public const EXEMPT_BENIGN_CALLS = ['doesntHave'];
+
+    /**
+     * 免除の前提 (2 層。どちらか一方でも崩れたら免除は無効):
+     *
+     * 1. **in-memory 形**: そのファイルは relation の実体を一度も参照しない
+     *    (`->adoptedTake` が無い = `$take->status !== TakeStatus::Ready` を書きようがない)
+     * 2. **参照形の exact-fit**: `'adoptedTake'` の出現が**すべて**
+     *    `->doesntHave('adoptedTake')` の形 (EXEMPT_BENIGN_CALLS のメソッド名・
+     *    第 1 引数が文字列リテラル・**引数はそれ 1 つだけ**) である。
+     *
+     * 前提 2 を「引数リスト内に status 判定が無いこと」ではなく**形そのものの固定**にしたのは、
+     * callback を変数やローカル scope へ切り出す形 (`whereHas('adoptedTake', $scope)`) が
+     * 引数リスト検査を素通りするためである (Codex impl-review Round 2 の指摘)。
+     * token 走査でデータフロー解析を始めずに、現在の免除理由だけを許可する。
+     */
+    public static function criterionExemptionPremiseHolds(string $relative): bool
+    {
+        if (in_array($relative, self::propertyFetchFiles(), true)) {
+            return false;
+        }
+
+        return ! in_array($relative, self::nonBenignReferenceShapeFiles(), true);
+    }
+
+    /** @return list<string> 前提 2 に違反する (benign 形以外の relation 参照を持つ) app/ 相対パス */
+    public static function nonBenignReferenceShapeFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => ! self::referenceShapeIsBenign($tokens));
+    }
+
+    /**
+     * `'adoptedTake'` の出現がすべて `->{benign}('adoptedTake')` の単独引数形であるか。
+     *
+     * 検査するのは 4 点:
+     *   (a) 直前が `(` である (= 第 1 引数。named argument の `relation:` 前置も弾く)
+     *   (b) その `(` の直前が EXEMPT_BENIGN_CALLS のメソッド名である
+     *   (c) さらにその直前が `->` / `?->` / `::` である (関数呼び出しではなくメソッド呼び出し)
+     *   (d) 直後が `)` である (= 引数はこれ 1 つだけ。callback も第 2 引数も持てない)
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function referenceShapeIsBenign(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_CONSTANT_ENCAPSED_STRING
+                || trim($tokens[$i]['text'], "'\"") !== 'adoptedTake') {
+                continue;
+            }
+
+            // (a) 直前が `(`
+            if ($i < 1 || $tokens[$i - 1]['id'] !== null || $tokens[$i - 1]['text'] !== '(') {
+                return false;
+            }
+            // (b) `(` の直前が benign メソッド名
+            if ($i < 2 || $tokens[$i - 2]['id'] !== T_STRING
+                || ! in_array($tokens[$i - 2]['text'], self::EXEMPT_BENIGN_CALLS, true)) {
+                return false;
+            }
+            // (c) メソッド呼び出しの形である
+            if ($i < 3 || ! in_array(
+                $tokens[$i - 3]['id'],
+                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
+                true,
+            )) {
+                return false;
+            }
+            // (d) 直後が `)` = 単独引数
+            if ($i + 1 >= $count || $tokens[$i + 1]['id'] !== null || $tokens[$i + 1]['text'] !== ')') {
+                return false;
+            }
+        }
+
+        return true;
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
+test('ケース 8: 検出 B の免除は in-memory 形を持たず参照形が benign な単独引数だけである前提を満たす', function (): void {
+    $criterion = AdoptedTakeCriterionScanner::criterionFiles();
+
+    foreach (AdoptedTakeCriterionScanner::COOCCURRENCE_EXEMPT as $relative => $rationale) {
+        // stale な免除を残さない (免除対象が検出 B から外れたら免除ごと消す)
+        expect(in_array($relative, $criterion, true))->toBeTrue(
+            "{$relative} は検出 B に該当しません。免除エントリを削除してください");
+        expect(AdoptedTakeCriterionScanner::criterionExemptionPremiseHolds($relative))->toBeTrue(
+            "{$relative} の adoptedTake 参照が benign 形 (プロパティフェッチ無し + doesntHave の単独引数) "
+            .'から外れました。免除の前提が崩れています');
+    }
+});
+
+test('scanner 自己検証: 免除の参照形は doesntHave の単独引数だけを benign とする', function (): void {
+    // benign: 現に免除ファイルが持つ形
+    $benign = PhpTokenScan::normalize(<<<'PHP'
+    <?php
+    $count = $manual->cuts()->doesntHave('adoptedTake')->count();
+    $ok = $result->take->status === TakeStatus::Ready;
+    PHP);
+    // 非 benign: 直接 callback / 変数 callback / whereRelation / 別メソッド / named argument
+    $closureForm = PhpTokenScan::normalize(
+        "<?php \$q->whereHas('adoptedTake', fn (\$t) => \$t->where('status', TakeStatus::Ready->value));",
+    );
+    $variableCallback = PhpTokenScan::normalize(<<<'PHP'
+    <?php
+    $scope = fn ($t) => $t->where('status', TakeStatus::Ready->value);
+    $q->whereHas('adoptedTake', $scope);
+    PHP);
+    $whereRelationForm = PhpTokenScan::normalize(
+        "<?php \$q->whereRelation('adoptedTake', 'status', 'ready');",
+    );
+    $otherMethod = PhpTokenScan::normalize("<?php \$q->whereDoesntHave('adoptedTake');");
+    $namedArgument = PhpTokenScan::normalize("<?php \$q->doesntHave(relation: 'adoptedTake');");
+
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($benign))->toBeTrue();
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($closureForm))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($variableCallback))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($whereRelationForm))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($otherMethod))->toBeFalse();
+    expect(AdoptedTakeCriterionScanner::referenceShapeIsBenign($namedArgument))->toBeFalse();
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
- `composer phpstan` : No errors (level 10)
- `vendor/bin/pest tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` : 11 tests / 11 passed / 47 assertions
- mutation (免除ファイル `PipelineSmokeCommand.php` に対して):
  - **M10 直接 callback 形**: `whereDoesntHave('adoptedTake', fn ($take) => $take->where('status', TakeStatus::Ready->value))`
    → ケース 8 が fail
  - **M11 変数 callback 形**: `$scope = fn ($take) => $take->where('status', TakeStatus::Ready->value);`
    + `whereHas('adoptedTake', $scope)` → ケース 8 が fail
    (**引数リスト検査版では green だった** = Round 2 の指摘どおり穴が実在した)
  - どちらも変異を戻すと 11 tests 全 green

## 確認してほしいこと

1. [Critical] が閉じたか (免除ファイルが「Canonical だけが判定式を持つ」を実質的に破れないか)。
2. 「保証しないもの」が実際の検出能力を過大にも過小にも言っていないか。
3. 前提 2 の 4 条件 (a)〜(d) に見落としがないか (免除ファイルの現行コードだけが通り、
   判定を書ける形はすべて弾けているか)。
