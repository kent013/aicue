# 対応マトリクス: impl-review Round 4

## [Critical] 一覧の削除経路が非 regular な残置と読み取り失敗を「不在」と誤認する

- 判断: **対応する**
- 根拠: 指摘のとおりで、これは **Round 3 の私の修正が持ち込んだ fail-open** である。
  `$reader(...) !== false` を存在判定に使ったのが誤りで、production の reader は
  `is_file($path) ? file_get_contents($path) : false` なので、
  壊れた symlink・ディレクトリ・読み取り不能な通常ファイルはすべて false を返す
  = 「不在」と誤認して削除せずに成功する。さらに元から不在でも
  `debtInventoryRemoved = true` になっていた。
  引退後の安定状態を作るための修正が、逆に**残置を成功扱いにする経路**を開けていた。
- 対応内容: 読み取り結果を存在判定に使うのをやめ、指摘された 3 段へ分けた。
  1. 削除前の `InventoryPresence` を取る
  2. 存在する場合だけ削除を試み、削除器が false を返したら例外
  3. **削除後に `InventoryPresence` を取り直し、`Absent` でなければ例外** (fail-closed)
  `debtInventoryRemoved` は**実際に存在したパスを削除したときだけ** true にする。
  presence の解決と削除は注入できるようにし、負例で 6 形を固定した (下記)。

## [Warning] 新しい削除分岐の負例が足りない

- 判断: **対応する**
- 根拠: 正常な通常ファイルの削除と安定した不在しか見ていなかった。
  指摘のとおり、現在の実装 (Round 4 以前) では壊れた symlink とディレクトリが
  残ったまま成功する負例が実際に再現できる。
- 対応内容: dataset を 6 形にした —
  通常ファイル (削除される) / symlink (リンクだけ消えて Absent になる) /
  壊れた symlink (残るので例外) / ディレクトリ (残るので例外) /
  削除器が false を返す (例外) / 元から不在 (削除しないので `debtInventoryRemoved` は false)。
  **一時 root の実ファイルシステム上に本物の symlink・ディレクトリを作って**再現する
  (作り物の presence を渡すのではなく、production の削除器の実際の挙動を通す)。

## [Warning] `RegularFileReader` の「読み取りが失敗した」分岐に負例が無い

- 判断: **対応する**
- 根拠: 指摘が正しい。symlink と壊れた symlink はどちらも最初の分岐へ入るので、
  `file_get_contents()` が false を返す最後の分岐は 1 度も通っていなかった。
  docblock が保証対象に挙げている分岐なので (c) の対象である。
- 対応内容: 読み取り関数を注入できる形にした
  (`read(string $path, string $label, ?callable $reader = null)`。既定は `file_get_contents`)。
  regular file の判定を通った後に読み取りが false を返す負例を独立して足した。
  既定の読み取り器が使われることも正例で押さえた。

## [Warning] `LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID` の docblock が旧設計のまま

- 判断: **対応する**
- 根拠: 「債務が 0 件になったらこの登録ごと消す」は Round 4 で確定した設計
  (「登録は一覧クラスの説明として残す」) と**正反対**である。
- 対応内容: 「引退時に外すのは対象パスの 1 行だけで、登録そのものは残る」へ書き換えた。

## [Warning] gate 側にも旧設計の記述が残っている

- 判断: **対応する**
- 根拠: `fingerprintDebtRetired()` の docblock (「引退後は一覧ファイルも登録も
  残っていてはならない」) と F12 のコメント (「どちらも残っていてはならない」) が
  実装と逆である。保証範囲の説明がコードと逆向きなのは最も避けたい形である。
- 対応内容: どちらも「一覧のパスと対象パスの 1 行は消えるが、登録そのものは残る」へ直した。

## [Suggestion] `retired` / `debtInventoryRemoved` / `newlyRetired` を分ける

- 判断: **対応する**
- 根拠: 指摘のとおり `retired` は「生成結果が 0 件」なので、安定した引退状態で
  再実行するたびに「0 件になった」と案内が出る。`debtInventoryRemoved` も
  常に true だったので「生成器が取り除いた」と嘘を表示していた。
- 対応内容: 報告を 3 つに分けた。
  `retired` = 生成結果が 0 件 / `debtInventoryRemoved` = 今回実際に削除した /
  `newlyRetired` = 既存債務が非空から 0 件へ遷移した。
  CLI の案内 (pin と対象パスを直せ) は **`newlyRetired` のときだけ**出し、
  「生成器が取り除いた」の 1 行は `debtInventoryRemoved` のときだけ出す。

---

# Round 5: 修正内容

Round 4 の指摘 (Critical 1 / Warning 4 / Suggestion 1) は**すべて対応した**
(反論・見送りは 1 件も無い)。上の対応マトリクスが判断の記録である。

## 主要な変更

1. **削除経路の fail-open を塞いだ** (Critical)。読み取り結果を存在判定に使うのをやめ、
   `InventoryPresence` で在り方を見る → 存在する場合だけ削除する →
   **削除後に取り直して `Absent` でなければ例外**の 3 段にした。
   `debtInventoryRemoved` は実際に存在したパスを削除したときだけ true になる。
   presence の解決は注入できる (既定は `InventoryPresence::fromPath()`)。

2. **削除分岐の負例を 6 形にした** (Warning)。**一時 root の実ファイルシステム上に
   本物の symlink・壊れた symlink・ディレクトリを作って**再現している
   (作り物の presence を渡すのではなく、production の削除器の実際の挙動を通す):
   通常ファイル (削除される) / symlink (リンクが消えて Absent) /
   壊れた symlink (例外) / ディレクトリ (例外) / 削除器が false (例外) /
   元から不在 (`debtInventoryRemoved` は false)。
   例外側は**残置が残ったままであること**も併せて検査している。

3. **`RegularFileReader` の読み取り失敗分岐を独立して裏取りした** (Warning)。
   指摘のとおり symlink と壊れた symlink は手前の分岐へ入るので、実ファイルだけでは
   最後の分岐に到達できない。読み取り器を注入できる形にして、
   regular file 判定を通った後に false を返す負例と、注入された結果を返す正例を足した。

4. **`LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID` の docblock を直した** (Warning)。
   「この登録ごと消す」→「外すのは対象パスの 1 行だけで登録そのものは残る」。

5. **gate 側の旧設計の記述を直した** (Warning)。
   `fingerprintDebtRetired()` の docblock と F12 のコメントを
   「一覧のパスと対象パスの 1 行は消えるが、登録そのものは残る」へ揃えた。

6. **報告を 3 つに分けた** (Suggestion)。
   `retired` (生成結果が 0 件) / `newlyRetired` (非空から 0 件へ遷移した) /
   `debtInventoryRemoved` (今回実際に削除した)。
   CLI の案内は `newlyRetired` のときだけ、「取り除いた」の 1 行は
   `debtInventoryRemoved` のときだけ出す。3 つが混ざらないことをテストで固定した
   (第 1 世代 = 3 つとも偽 / 遷移した回 = 3 つとも真 / 安定した再実行 = retired だけ真)。

## テストファーストの実測 (今回の修正で 1 件赤を踏んだ)

6 の「3 つの事実」テストを書いたとき、遷移の回に `previousLedger: null` と
非空の `existingDebt` を渡してしまい、**Round 2 で入れた seeding ガードが正しく発火して**
`GenerationRefused` になった (テスト側の誤りであり実装は正しい)。
第 1 世代を実際に走らせて前世代の台帳を作る形へ直した。
ガードが意図どおり効いていることの偶発的な裏取りにもなった。

## 検証結果 (修正後・10 本すべて green)

```
composer test              : 6340 tests, 6338 passed, 0 failed, 2 skipped, 5 risky (30267 assertions)
                             ※ 6296 (R1) → 6308 (R2) → 6320 (R3) → 6331 (R4) → 6340 (R5)
composer phpstan           : No errors
vendor/bin/pint --test     : passed
pnpm lint                  : passed
pnpm typecheck             : passed
pnpm test                  : 169 files, 2283 tests passed
pnpm build                 : built
pnpm typecheck:packages    : passed
pnpm build:packages        : passed
pnpm test:packages         : 10 files, 106 tests passed
```

生成器の再実行で pin は **281 / 174 / 33** のまま、生成物 2 本は **byte 不変**である。

## 差分の読み方

Round 1 で提示した index からの累積差分である。今回新しいのは上の 1〜6 で、
対象ファイルは 7 本である。

```diff
diff --git a/scripts/update-template-fingerprints.php b/scripts/update-template-fingerprints.php
index 684fd282..8a7698b0 100644
--- a/scripts/update-template-fingerprints.php
+++ b/scripts/update-template-fingerprints.php
@@ -40,7 +40,7 @@
 use Tests\Support\TemplateDivergence\FingerprintLedger;
 use Tests\Support\TemplateDivergence\GenerationRefused;
 use Tests\Support\TemplateDivergence\LedgerPins;
-use Tests\Support\TemplateDivergence\LedgerRole;
+use Tests\Support\TemplateDivergence\RegularFileReader;
 use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
 
 $root = dirname(__DIR__);
@@ -105,26 +105,10 @@
 // --- role ガード (最も現実的な無効化経路を正規経路の側で塞ぐ) ---
 // 既存のアプリ側台帳が role: template なら、子アプリで正典側の生成を走らせている。
 // これは逸脱検出そのものを消すので**拒否 (3)** で止める。
+// 拒否は GenerationRefused で表し、終了コードへの写像は下の catch 1 か所に集約する
+// (拒否 = 3 / 実行不能 = 1。型を使わずに直接 exit すると型の説明が実装と食い違う)。
 $previousLedger = null;
 $fingerprintPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
-if (is_file($fingerprintPath)) {
-    $existingRaw = file_get_contents($fingerprintPath);
-    if ($existingRaw === false) {
-        $fail("既存の指紋台帳を読めない: {$fingerprintPath}");
-    }
-
-    try {
-        $previousLedger = FingerprintLedger::fromJson($existingRaw);
-    } catch (RuntimeException $e) {
-        $fail('既存の指紋台帳を解釈できない: '.$e->getMessage());
-    }
-
-    if ($previousLedger->role !== LedgerRole::App) {
-        fwrite(STDERR, 'refused: 既存の指紋台帳の role が app でない。'
-            ."本リポジトリはテンプレートの受け手なので、正典側の生成器を走らせてはならない。\n");
-        exit(3);
-    }
-}
 
 // --- 登録簿と既存の債務一覧 ---
 $ledgerMarkdown = file_get_contents($root.'/docs/template-divergence.md');
@@ -165,6 +149,17 @@
 
 // --- 生成 ---
 try {
+    if (file_exists($fingerprintPath) || is_link($fingerprintPath)) {
+        // 指紋台帳の読み取り口は 1 つに寄せる (gate / 一覧 / 生成器が同じ判定を通る)。
+        // symlink を追跡すると母集合ごと差し替えられるため受理しない。
+        $previousLedger = FingerprintLedger::fromJson(
+            RegularFileReader::read($fingerprintPath, '既存の指紋台帳'),
+        );
+
+        // 判定本体は service 側に置く (CLI とテストが同じ処理を呼ぶ = 両方向を裏取りできる)
+        FingerprintGenerationService::assertAppLedgerRole($previousLedger);
+    }
+
     $context = FingerprintGenerationContext::forRoot(
         root: $root,
         expectedTemplateLedgerSha256: LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256,
@@ -230,4 +225,20 @@
     $report['templateLedgerCommit'],
 ));
 
+// 案内は**遷移したときだけ**出す (安定した引退状態で再実行するたびに出すと嘘になる)
+if ($report['newlyRetired']) {
+    fwrite(STDOUT,
+        "採用時債務が 0 件になった。同じ変更で次の 2 つを行うこと:\n"
+        ."  1. LedgerPins::ADOPTION_DEBT_COUNT を 0 にする\n"
+        .'  2. docs/template-divergence.md の対象パスから '
+            .AdoptionDebtInventory::INVENTORY_PATH." の 1 行を外す\n"
+        ."     (登録そのものは一覧クラスの説明として残す)\n",
+    );
+}
+
+// 「取り除いた」は実際に削除したときだけ言う
+if ($report['debtInventoryRemoved']) {
+    fwrite(STDOUT, '一覧ファイルを取り除いた: '.AdoptionDebtInventory::INVENTORY_PATH."\n");
+}
+
 exit(0);
diff --git a/tests/Architecture/TemplateDivergenceFingerprintTest.php b/tests/Architecture/TemplateDivergenceFingerprintTest.php
index 2ae3f05f..080b62a9 100644
--- a/tests/Architecture/TemplateDivergenceFingerprintTest.php
+++ b/tests/Architecture/TemplateDivergenceFingerprintTest.php
@@ -7,11 +7,13 @@
 use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
 use Tests\Support\TemplateDivergence\FingerprintLedger;
 use Tests\Support\TemplateDivergence\FingerprintReconciler;
+use Tests\Support\TemplateDivergence\InventoryPresence;
 use Tests\Support\TemplateDivergence\LedgerPins;
 use Tests\Support\TemplateDivergence\LedgerRole;
 use Tests\Support\TemplateDivergence\ParsedLedger;
 use Tests\Support\TemplateDivergence\PathObservation;
 use Tests\Support\TemplateDivergence\ReconciliationResult;
+use Tests\Support\TemplateDivergence\RegularFileReader;
 use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
 
 /*
@@ -84,15 +86,15 @@ function fingerprintRequiredMembers(): array
     ];
 }
 
-/** 指紋台帳の生バイト列 (読めないことは不合格)。 */
+/**
+ * 指紋台帳の生バイト列 (読めないことは不合格)。
+ *
+ * ★**symlink を受理しない**。`file_get_contents()` はリンク先を読むので、リンクを差し替えると
+ *   母集合ごと入れ替えられる。母集合を決める正本なので債務一覧と同じ強さで守る。
+ */
 function fingerprintLedgerRaw(): string
 {
-    $raw = file_get_contents(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH));
-    if ($raw === false) {
-        throw new RuntimeException('指紋台帳 '.LedgerPins::FINGERPRINT_LEDGER_PATH.' を読めない');
-    }
-
-    return $raw;
+    return RegularFileReader::read(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH), '指紋台帳');
 }
 
 /** 指紋台帳の DTO。 */
@@ -101,17 +103,59 @@ function fingerprintLedger(): FingerprintLedger
     return FingerprintLedger::fromJson(fingerprintLedgerRaw());
 }
 
+/**
+ * 採用時債務が引退済みか (件数の pin が 0)。
+ *
+ * 引退後は**一覧ファイルが無く、対象パスからも一覧パスの 1 行が外れている**のが正しい状態で、
+ * **登録そのものは一覧クラスの説明として残る**。gate は pin を状態の軸にして、
+ * 一覧を読まずに空の債務集合を突合へ渡す。判定の両方向は
+ * `AdoptionDebtInventory::retirementViolations()` が持つ。
+ */
+function fingerprintDebtRetired(): bool
+{
+    return LedgerPins::ADOPTION_DEBT_COUNT === 0;
+}
+
+/**
+ * 一覧のパスの在り方 (3 値)。
+ *
+ * 「残っているか」と「regular file か」を 2 つの真偽値で持つと
+ * 「存在しないが regular file」という矛盾した組み合わせを作れてしまうので、
+ * 写像を `InventoryPresence` の 1 か所へ閉じる。
+ */
+function fingerprintDebtInventoryPresence(): InventoryPresence
+{
+    return InventoryPresence::fromPath(base_path(AdoptionDebtInventory::INVENTORY_PATH));
+}
+
+/** 採用時債務一覧を説明する登録 (D34) が登録簿に存在するか (番号で同定する)。 */
+function fingerprintDebtDivergenceEntryExists(): bool
+{
+    foreach (fingerprintParsedDivergenceLedger()->entries as $entry) {
+        if ($entry->id === LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
 /**
  * 採用時債務一覧。
  *
- * @return array{templateLedgerCommit: string, entries: array<string, string>}
+ * 引退後 (pin が 0) は一覧ファイルを読まず、空の債務集合を返す。
+ * 「0 件だから一覧ファイルが無い」ことは F11 / F12 が両方向で固定する。
+ *
+ * @return array{templateLedgerCommit: ?string, entries: array<string, string>}
  */
 function fingerprintDebt(): array
 {
     static $cache = null;
 
     if ($cache === null) {
-        $cache = AdoptionDebtInventory::read(base_path());
+        $cache = fingerprintDebtRetired()
+            ? ['templateLedgerCommit' => null, 'entries' => []]
+            : AdoptionDebtInventory::read(base_path());
     }
 
     return $cache;
@@ -254,8 +298,15 @@ function fingerprintReconciliation(): ReconciliationResult
 
 test('F0: 指紋台帳・登録簿・債務一覧が実在して読めること (読み取り失敗は不合格)', function (): void {
     expect(trim(fingerprintLedgerRaw()))->not->toBe('')
-        ->and(fingerprintDebt())->toHaveKey('templateLedgerCommit')
-        ->and(is_file(base_path('docs/template-divergence.md')))->toBeTrue();
+        ->and(fingerprintDebt())->toHaveKey('entries')
+        ->and(is_file(base_path('docs/template-divergence.md')))->toBeTrue()
+        // 母集合の正本は regular file であること (symlink 差し替えで母集合ごと入れ替えられない)
+        ->and(is_link(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH)))->toBeFalse();
+
+    // 引退前は一覧ファイルも regular file として実在すること
+    if (! fingerprintDebtRetired()) {
+        expect(fingerprintDebtInventoryPresence())->toBe(InventoryPresence::RegularFile);
+    }
 
     // 負のコントロール: 読めない入力が黙って空へ潰れず例外になること
     expect(fn (): array => AdoptionDebtInventory::read(base_path('storage/framework/t236-absent')))
@@ -357,20 +408,22 @@ function fingerprintReconciliation(): ReconciliationResult
     expect(fingerprintDebt()['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT);
 });
 
-test('F12: 債務が非空の間は債務一覧のファイルが登録簿に登録されていること', function (): void {
-    $debt = fingerprintDebt()['entries'];
-    if ($debt === []) {
-        // 0 件になったら一覧ファイルと登録を同じ変更で消す (D34 の再判定の条件)
-        expect(true)->toBeTrue();
-
-        return;
-    }
-
+test('F12: 債務の引退の掃除が両方向で済んでいること', function (): void {
+    // pin が 1 件以上 → 一覧ファイルが regular file で実在し対象パスに含まれる /
+    // pin が 0 件 → 一覧のパスも対象パスの 1 行も残っていてはならない
+    // (**登録そのものは pin の値に関わらず残る** — 一覧クラスは 0 件でも残るため)。
+    // **0 件を無条件で合格にしない** (ヘッダだけの一覧や残った対象パスを緑にしないため)。
     $registeredPaths = array_column(fingerprintRegisteredPaths(), 'path');
 
-    expect(in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true))->toBeTrue(
-        '債務が残っている間は '.AdoptionDebtInventory::INVENTORY_PATH.' を登録簿へ登録しておくこと',
+    $violations = AdoptionDebtInventory::retirementViolations(
+        pinnedCount: LedgerPins::ADOPTION_DEBT_COUNT,
+        presence: fingerprintDebtInventoryPresence(),
+        isRegisteredAsTargetPath: in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true),
+        // **登録の存在は番号で同定する** (対象パスだけを見ると中途半端な掃除が緑になる)
+        divergenceEntryExists: fingerprintDebtDivergenceEntryExists(),
     );
+
+    expect($violations)->toBe([], '採用時債務の掃除漏れ:'.PHP_EOL.implode(PHP_EOL, $violations));
 });
 
 test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
@@ -389,6 +442,13 @@ function fingerprintReconciliation(): ReconciliationResult
     $ledger = fingerprintLedger();
     $debt = fingerprintDebt();
 
+    if (fingerprintDebtRetired()) {
+        // 引退後はヘッダが存在しないので世代の突き合わせへ進めない (掃除は F12 が見る)
+        expect($debt['entries'])->toBe([]);
+
+        return;
+    }
+
     // 片方だけが更新された状態を落とす (件数 pin だけでは増減が相殺されて緑になり得る)
     expect($debt['templateLedgerCommit'])->toBe(
         $ledger->generatedAtCommit,
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationService.php b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
index b80f34a5..db8930c0 100644
--- a/tests/Support/TemplateDivergence/FingerprintGenerationService.php
+++ b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
@@ -24,6 +24,11 @@
  * ★**`AtomicLedgerWriter::replace()` の戻り値を無視しない**。非 null は即座に例外にする
  *   (戻り値を無視すると fail-open になる)。この配線は単体テストが固定する。
  *
+ * ★**引退 (債務 0 件) は安定状態である**。債務が 0 件になったら一覧を書かず、
+ *   既存の一覧ファイルを取り除く。「ヘッダだけの一覧」を書き続けると、台帳を更新するたびに
+ *   一覧が再作成されて突合 gate が赤くなり、引退が安定しない。
+ *   逆に新しい債務が生じれば一覧は再作成される (そのときは件数 pin と登録の対象パスを戻す)。
+ *
  * 終了コードの写像は例外の型で決まる: `GenerationRefused` = 3 / `RuntimeException` = 1。
  */
 final class FingerprintGenerationService
@@ -31,6 +36,30 @@ final class FingerprintGenerationService
     /** インスタンス化しない (純関数のみ)。 */
     private function __construct() {}
 
+    /**
+     * 既存のアプリ側指紋台帳の role を検査する (CLI の role ガードの判定本体)。
+     *
+     * ★これが**最も現実的な無効化経路**である。赤い CI を消すために受け手側で
+     *   正典側の生成器を善意で走らせると、逸脱検出そのものが消える。
+     *   `role: template` の台帳を持っているのは「提供元の生成器を回している」証拠なので拒否する。
+     * ★判定を CLI の本文へ直書きせず本メソッドへ置くのは、**両方向を負例で裏取りできる形**に
+     *   するためである (CLI とテストが同じ処理を呼ぶ)。
+     *
+     * @throws GenerationRefused role が app でないとき (終了コード 3 へ写る)
+     */
+    public static function assertAppLedgerRole(FingerprintLedger $ledger): void
+    {
+        if ($ledger->role === LedgerRole::App) {
+            return;
+        }
+
+        throw new GenerationRefused(
+            '既存の指紋台帳の role が app でない ('.$ledger->role->value.')。'
+                .'本リポジトリはテンプレートの受け手なので、正典側の生成器を走らせてはならない。'
+                .'共有ファイルを変えたのなら docs/template-divergence.md へ逸脱を登録すること。',
+        );
+    }
+
     /**
      * @param  string  $templateLedgerRaw  入力の正典台帳の**生バイト列**
      * @param  list<string>  $trackedPaths  git 追跡ファイル
@@ -43,6 +72,8 @@ private function __construct() {}
      * @param  callable(string): (string|false)  $reader
      * @param  callable(string, string): bool  $renamer
      * @param  callable(string): bool  $remover
+     * @param  (callable(string): InventoryPresence)|null  $presenceResolver  一覧のパスの在り方の解決
+     *                                                                        (既定は `InventoryPresence::fromPath()`。注入できるのは削除経路の負例を書くためである)
      * @return array{
      *     populationCount: int,
      *     adoptionDebtCount: int,
@@ -53,6 +84,9 @@ private function __construct() {}
      *     addedDebt: list<string>,
      *     templateLedgerCommit: string,
      *     seeded: bool,
+     *     retired: bool,
+     *     newlyRetired: bool,
+     *     debtInventoryRemoved: bool,
      * }
      *
      * @throws GenerationRefused ガードによる拒否 (終了コード 3)
@@ -71,7 +105,10 @@ public static function generate(
         callable $reader,
         callable $renamer,
         callable $remover,
+        ?callable $presenceResolver = null,
     ): array {
+        $presenceResolver ??= static fn (string $path): InventoryPresence => InventoryPresence::fromPath($path);
+
         // --- 入力の出自 (pin との一致) ---
         $actualSha256 = hash('sha256', $templateLedgerRaw);
         if ($actualSha256 !== $context->expectedTemplateLedgerSha256 && ! $context->adoptNewTemplateLedger) {
@@ -98,6 +135,32 @@ public static function generate(
             throw new RuntimeException('git 追跡ファイルが 0 件と算出された (実行不能として落とす)');
         }
 
+        // --- 初回生成 (採用) のガード ---
+        // 「前世代の台帳が無い」を初回とみなすと、**指紋台帳を working tree から消すだけで
+        // 採用をやり直せる** (未登録の相違を債務へ再基準化できる) 経路が開く。
+        // 本当の導入時は出力先がまだ git 追跡下に無いので、その 3 条件で初回を識別する。
+        if ($context->previousLedger === null) {
+            $tracked = array_fill_keys($trackedPaths, true);
+            $blockers = [];
+            if (array_key_exists(LedgerPins::FINGERPRINT_LEDGER_PATH, $tracked)) {
+                $blockers[] = LedgerPins::FINGERPRINT_LEDGER_PATH.' は既に git 追跡下にある';
+            }
+            if (array_key_exists(AdoptionDebtInventory::INVENTORY_PATH, $tracked)) {
+                $blockers[] = AdoptionDebtInventory::INVENTORY_PATH.' は既に git 追跡下にある';
+            }
+            if ($existingDebt !== []) {
+                $blockers[] = '既存の採用時債務が '.count($existingDebt).' 件ある';
+            }
+
+            if ($blockers !== []) {
+                throw new GenerationRefused(
+                    '初回生成 (採用) をやり直せない: '.implode(' / ', $blockers).'。'
+                        .'出力先が追跡済みなのに読めないのは「初回」ではなく削除・検査不能である。'
+                        .'指紋台帳を復元してから再実行すること。',
+                );
+            }
+        }
+
         // --- 母集合の縮小の拒否 (同じ正典入力のまま狭めさせない) ---
         if (! $context->adoptNewTemplateLedger && $context->previousLedger !== null) {
             $dropped = array_values(array_diff(
@@ -147,18 +210,51 @@ public static function generate(
             throw new RuntimeException('指紋台帳を置換できない: '.$reason);
         }
 
-        AtomicTextWriter::replace(
-            $context->debtOutputPath,
-            $debtContents,
-            static fn (): string|false => $tempPathFactory($context->debtOutputPath),
-            $writer,
-            $reader,
-            $renamer,
-            $remover,
-            static function (string $contents): void {
-                AdoptionDebtInventory::parse($contents);
-            },
-        );
+        // 引退は**安定状態**でなければならない。債務が 0 件のとき「ヘッダだけの一覧」を書くと、
+        // 台帳を更新するたびに一覧が再作成されて突合 gate が赤くなる。
+        // 正しい生成物の状態は「0 件の一覧」ではなく**一覧が無い**ことなので、
+        // 既存の一覧ファイルがあれば取り除く。
+        //
+        // ★**読み取り結果を存在判定に使わない**。production の読み取り器は
+        //   `is_file()` が false なら false を返すので、壊れた symlink・ディレクトリ・
+        //   読み取り不能な通常ファイルをすべて「不在」と誤認する (= 残置を成功扱いにする)。
+        //   在り方は `InventoryPresence` で見て、削除後に**もう一度取り直して確かめる**。
+        $debtInventoryRemoved = false;
+        if ($built['debt'] === []) {
+            $presence = $presenceResolver($context->debtOutputPath);
+
+            if ($presence->exists()) {
+                if (! $remover($context->debtOutputPath)) {
+                    throw new RuntimeException(
+                        '債務が 0 件になったが採用時債務一覧を取り除けない: '.$context->debtOutputPath,
+                    );
+                }
+                $debtInventoryRemoved = true;
+            }
+
+            // 削除後の再検査 (削除器が真を返しても実際には残っている形がある)
+            $after = $presenceResolver($context->debtOutputPath);
+            if ($after !== InventoryPresence::Absent) {
+                throw new RuntimeException(sprintf(
+                    '債務が 0 件になったが採用時債務一覧のパスが残っている (%s): %s',
+                    $after->name,
+                    $context->debtOutputPath,
+                ));
+            }
+        } else {
+            AtomicTextWriter::replace(
+                $context->debtOutputPath,
+                $debtContents,
+                static fn (): string|false => $tempPathFactory($context->debtOutputPath),
+                $writer,
+                $reader,
+                $renamer,
+                $remover,
+                static function (string $contents): void {
+                    AdoptionDebtInventory::parse($contents);
+                },
+            );
+        }
 
         return [
             'populationCount' => count($built['ledger']->entries),
@@ -170,6 +266,11 @@ static function (string $contents): void {
             'addedDebt' => $built['addedDebt'],
             'templateLedgerCommit' => $templateLedger->generatedAtCommit,
             'seeded' => $built['seeded'],
+            // retired = 生成結果が 0 件 / newlyRetired = 非空から 0 件へ遷移した /
+            // debtInventoryRemoved = 今回実際にパスを削除した (3 つは別の事実である)
+            'retired' => $built['debt'] === [],
+            'newlyRetired' => $built['debt'] === [] && $existingDebt !== [],
+            'debtInventoryRemoved' => $debtInventoryRemoved,
         ];
     }
 }
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 8b1a5cae..a6a4e8b0 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -33,6 +33,18 @@ private function __construct() {}
      */
     public const int ADOPTION_DEBT_COUNT = 174;
 
+    /**
+     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
+     *
+     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
+     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
+     *   同定に使うので番号を pin する。
+     *   ★**引退時に外すのは対象パスの 1 行だけで、登録そのものは残る** —
+     *   一覧が 0 件になっても判定機構 (`AdoptionDebtInventory`) は残り続けるので、
+     *   本アプリ固有の追加としての説明は要る (詳しくは同クラスの docblock)。
+     */
+    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;
+
     /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
     public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';
 
diff --git a/tests/Support/TemplateDivergence/RegularFileReader.php b/tests/Support/TemplateDivergence/RegularFileReader.php
new file mode 100644
index 00000000..0abf5d75
--- /dev/null
+++ b/tests/Support/TemplateDivergence/RegularFileReader.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+use RuntimeException;
+
+/**
+ * 「regular file であることを確かめてから読む」読み取り口 (純関数)。
+ *
+ * ★本機構が正本として読むファイル (指紋台帳・採用時債務一覧) は**symlink を受理しない**。
+ *   `file_get_contents()` はリンク先を読むので、リンクを差し替えるだけで
+ *   **母集合や債務の内容ごと入れ替えられる**。判定を 1 か所へ集めて、
+ *   利用側がうっかり素の `file_get_contents()` を呼ばないようにする。
+ *
+ * ★**読めないことを空へ潰さない** (fail-open を作らない)。落とす形は 4 つである:
+ *   symlink である / 存在しない / 通常ファイルでない (ディレクトリ等) / 読み取りが失敗した。
+ *
+ * ★**保証しないもの**: 見るのは呼ばれた時点の状態だけである (TOCTOU は閉じない)。
+ *   ファイルの中身の妥当性は見ない (それは呼び出し側の解析器の担当である)。
+ *   利用側が本クラスを通さずに読む経路を機械では塞げない (レビューの義務)。
+ *
+ * 負例と正例は `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が固定する。
+ */
+final class RegularFileReader
+{
+    /** インスタンス化しない (純関数のみ)。 */
+    private function __construct() {}
+
+    /**
+     * @param  string  $label  失敗メッセージに出す名前 (どの正本の話か分かるようにする)
+     * @param  (callable(string): (string|false))|null  $reader  読み取り器 (既定は file_get_contents)。
+     *                                                           **注入できるのは「regular file の判定を通った後に読み取りが失敗する」分岐を
+     *                                                           独立して負例で裏取りするため**である (symlink と不在は手前の分岐で落ちるので、
+     *                                                           実ファイルだけでは最後の分岐に到達できない)。
+     *
+     * @throws RuntimeException symlink / 不在 / 通常ファイルでない / 読み取り失敗
+     */
+    public static function read(string $path, string $label, ?callable $reader = null): string
+    {
+        if (is_link($path)) {
+            throw new RuntimeException("{$label} が symlink である (内容を差し替えられるため受理しない): {$path}");
+        }
+        if (! file_exists($path)) {
+            throw new RuntimeException("{$label} が存在しない: {$path}");
+        }
+        if (! is_file($path)) {
+            throw new RuntimeException("{$label} が通常ファイルでない: {$path}");
+        }
+
+        $contents = ($reader ?? static fn (string $p): string|false => file_get_contents($p))($path);
+        if ($contents === false) {
+            throw new RuntimeException("{$label} を読めない: {$path}");
+        }
+
+        return $contents;
+    }
+}
diff --git a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
index 98893d20..45c2182e 100644
--- a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
+++ b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
@@ -6,9 +6,11 @@
 use Tests\Support\TemplateDivergence\ComparisonState;
 use Tests\Support\TemplateDivergence\FingerprintLedger;
 use Tests\Support\TemplateDivergence\FingerprintReconciler;
+use Tests\Support\TemplateDivergence\InventoryPresence;
 use Tests\Support\TemplateDivergence\LedgerPins;
 use Tests\Support\TemplateDivergence\LedgerRole;
 use Tests\Support\TemplateDivergence\PathObservation;
+use Tests\Support\TemplateDivergence\RegularFileReader;
 use Tests\Support\TemplateDivergence\RepoRelativePath;
 
 /*
@@ -25,7 +27,8 @@
  *
  * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
  *   `FingerprintLedger::fromJson()` = 11 形 / `RepoRelativePath::isValid()` = 8 形 /
- *   `PathObservation` = 7 形 / `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
+ *   `PathObservation` = 組み合わせ 7 形 (値の書式は別軸なので数に入れない) /
+ *   `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
  *   `FingerprintReconciler` = 8 種別。
  *
  * 生成器側 (`AppFingerprintBuilder` / `AtomicLedgerWriter` / `AtomicTextWriter` /
@@ -124,17 +127,6 @@ function adoptionDebtText(string $commit, string ...$lines): string
     expect(FingerprintLedger::fromJson(fingerprintLedgerJson([]))->entries)->toBe([]);
 });
 
-test('FingerprintLedger の鮮度比較は generated_at_commit を無視する', function (): void {
-    $entries = ['a.php' => fingerprintHash()];
-    $left = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('a'), $entries);
-    $right = new FingerprintLedger(1, LedgerRole::App, fingerprintCommit('b'), $entries);
-
-    expect($left->matchesIgnoringGeneratedCommit($right))->toBeTrue()
-        ->and($left->matchesIgnoringGeneratedCommit(
-            new FingerprintLedger(1, LedgerRole::Template, fingerprintCommit('a'), $entries),
-        ))->toBeFalse();
-});
-
 // ---------------------------------------------------------------------------
 // 正準形バイト一致 (F1 の上積み) — 重複キー・整形の崩れを落とす
 // ---------------------------------------------------------------------------
@@ -215,7 +207,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
         ->and($failed->state)->toBeNull();
 });
 
-test('負例: PathObservation が矛盾した組み合わせを例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
+test('負例: PathObservation が矛盾した組み合わせ 7 形を例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
     expect(fn (): PathObservation => new PathObservation($state, $hash, $failure))
         ->toThrow(InvalidArgumentException::class);
 })->with([
@@ -228,7 +220,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
     '7: 検査不能の理由が空文字' => [null, null, ''],
 ]);
 
-test('負例: PathObservation がハッシュの書式違反を例外にする', function (): void {
+test('負例: PathObservation がハッシュの書式違反を例外にする (組み合わせとは別の軸)', function (): void {
     expect(fn (): PathObservation => new PathObservation(ComparisonState::Matched, 'DEADBEEF', null))
         ->toThrow(InvalidArgumentException::class);
 });
@@ -525,8 +517,234 @@ function reconcilerHashesFor(string ...$paths): array
 });
 
 test('正例: 現物の採用時債務一覧が読めて件数の pin と一致する', function (): void {
+    if (LedgerPins::ADOPTION_DEBT_COUNT === 0) {
+        // 引退後は一覧ファイルが無いのが正しい (掃除の両方向は下の検査が固定する)
+        expect(is_file(base_path(AdoptionDebtInventory::INVENTORY_PATH)))->toBeFalse();
+
+        return;
+    }
+
     $parsed = AdoptionDebtInventory::read(base_path());
 
     expect($parsed['entries'])->toHaveCount(LedgerPins::ADOPTION_DEBT_COUNT)
         ->and($parsed['templateLedgerCommit'])->toBe(LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT);
 });
+
+// ---------------------------------------------------------------------------
+// 債務の引退の掃除 (両方向) — 「0 件なら無条件で合格」を作らない
+// ---------------------------------------------------------------------------
+
+test('債務の引退の掃除を両方向で判定する (0 件を無条件で合格にしない)', function (
+    int $pinnedCount,
+    InventoryPresence $presence,
+    bool $isRegisteredAsTargetPath,
+    bool $entryExists,
+    bool $expectedClean,
+): void {
+    $violations = AdoptionDebtInventory::retirementViolations(
+        pinnedCount: $pinnedCount,
+        presence: $presence,
+        isRegisteredAsTargetPath: $isRegisteredAsTargetPath,
+        divergenceEntryExists: $entryExists,
+    );
+
+    expect($violations === [])->toBe($expectedClean, '違反: '.implode(' / ', $violations));
+})->with([
+    // pin が 1 件以上: 一覧が regular file として実在し、登録が存在し、対象パスに含む
+    '1 件以上・すべて揃っている → 合格' => [176, InventoryPresence::RegularFile, true, true, true],
+    '1 件以上・一覧のパスが無い → 違反' => [176, InventoryPresence::Absent, true, true, false],
+    '1 件以上・一覧が symlink → 違反' => [176, InventoryPresence::NonRegularFile, true, true, false],
+    '1 件以上・対象パスに含んでいない → 違反' => [176, InventoryPresence::RegularFile, false, true, false],
+    '1 件以上・登録そのものが無い → 違反' => [176, InventoryPresence::RegularFile, true, false, false],
+    // ★各項が単独で発火すること (対象パス側だけで通ってしまわないこと)
+    '1 件以上・対象パスはあるが登録が無い → 違反' => [176, InventoryPresence::RegularFile, true, false, false],
+    // pin が 0 件: 一覧のパスも対象パスも残っていてはならない (登録そのものは残る)
+    '0 件・掃除済み (一覧なし・対象パスなし・登録あり) → 合格' => [0, InventoryPresence::Absent, false, true, true],
+    '0 件・一覧ファイルが残っている → 違反' => [0, InventoryPresence::RegularFile, false, true, false],
+    '0 件・一覧が symlink として残っている → 違反' => [0, InventoryPresence::NonRegularFile, false, true, false],
+    '0 件・対象パスが残っている → 違反' => [0, InventoryPresence::Absent, true, true, false],
+    // ★登録ごと消すのは誤りである (機構が残るので説明は要る)
+    '0 件・登録ごと消してしまった → 違反' => [0, InventoryPresence::Absent, false, false, false],
+    '0 件・対象パスはあるが登録が無い → 違反' => [0, InventoryPresence::Absent, true, false, false],
+]);
+
+test('引退の掃除の違反は条件ごとに 1 件ずつ独立して出る', function (): void {
+    // 「登録が無い」だけ (対象パスは正しく外れている / 一覧も無い)
+    expect(AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::Absent,
+        isRegisteredAsTargetPath: false,
+        divergenceEntryExists: false,
+    ))->toHaveCount(1);
+
+    // 「対象パスが残っている」だけ
+    expect(AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::Absent,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: true,
+    ))->toHaveCount(1);
+
+    // 「一覧が残っている」だけ
+    expect(AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::RegularFile,
+        isRegisteredAsTargetPath: false,
+        divergenceEntryExists: true,
+    ))->toHaveCount(1);
+
+    // 3 つ同時 (集約されて 3 件出る)
+    expect(AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::RegularFile,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: false,
+    ))->toHaveCount(3);
+});
+
+test('負例: 債務の件数 pin が負なら例外にする', function (): void {
+    expect(fn (): array => AdoptionDebtInventory::retirementViolations(
+        pinnedCount: -1,
+        presence: InventoryPresence::RegularFile,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: true,
+    ))->toThrow(RuntimeException::class);
+});
+
+test('引退の掃除の違反は直し方まで告げる', function (): void {
+    $violations = AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::RegularFile,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: true,
+    );
+
+    expect(implode("\n", $violations))->toContain(AdoptionDebtInventory::INVENTORY_PATH)
+        ->and(implode("\n", $violations))->toContain('登録そのものは一覧クラスの説明として残す');
+});
+
+// ---------------------------------------------------------------------------
+// InventoryPresence — ファイルシステムから 3 値への写像 (矛盾を型で消す)
+// ---------------------------------------------------------------------------
+
+test('InventoryPresence はパスの在り方を 3 値へ写す', function (string $kind, InventoryPresence $expected): void {
+    $dir = sys_get_temp_dir().'/t236-presence-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+
+    $path = match ($kind) {
+        'regular' => (function () use ($dir): string {
+            $p = $dir.'/plain.tsv';
+            file_put_contents($p, "x\n");
+
+            return $p;
+        })(),
+        'symlink' => (function () use ($dir): string {
+            $real = $dir.'/real.tsv';
+            file_put_contents($real, "x\n");
+            $link = $dir.'/link.tsv';
+            symlink($real, $link);
+
+            return $link;
+        })(),
+        'broken-symlink' => (function () use ($dir): string {
+            $link = $dir.'/broken.tsv';
+            symlink($dir.'/absent.tsv', $link);
+
+            return $link;
+        })(),
+        'directory' => $dir,
+        'absent' => $dir.'/absent.tsv',
+    };
+
+    expect(InventoryPresence::fromPath($path))->toBe($expected);
+})->with([
+    '通常ファイル' => ['regular', InventoryPresence::RegularFile],
+    'symlink は残置扱い' => ['symlink', InventoryPresence::NonRegularFile],
+    '壊れた symlink も残置扱い' => ['broken-symlink', InventoryPresence::NonRegularFile],
+    'ディレクトリも残置扱い' => ['directory', InventoryPresence::NonRegularFile],
+    '不在' => ['absent', InventoryPresence::Absent],
+]);
+
+test('InventoryPresence::exists() は不在だけを false にする', function (): void {
+    expect(InventoryPresence::Absent->exists())->toBeFalse()
+        ->and(InventoryPresence::RegularFile->exists())->toBeTrue()
+        ->and(InventoryPresence::NonRegularFile->exists())->toBeTrue();
+});
+
+// ---------------------------------------------------------------------------
+// RegularFileReader — symlink 拒否の負例と正例 (走査条件を変えたので (c) の対象)
+// ---------------------------------------------------------------------------
+
+test('正例: RegularFileReader は通常ファイルの中身をそのまま返す', function (): void {
+    $dir = sys_get_temp_dir().'/t236-reader-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+    $path = $dir.'/plain.txt';
+    file_put_contents($path, "abc\n");
+
+    expect(RegularFileReader::read($path, '検体'))->toBe("abc\n");
+});
+
+test('負例: RegularFileReader が symlink・ディレクトリ・不在を例外にする', function (string $kind): void {
+    $dir = sys_get_temp_dir().'/t236-reader-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+
+    $path = match ($kind) {
+        'symlink' => (function () use ($dir): string {
+            $real = $dir.'/real.txt';
+            file_put_contents($real, "abc\n");
+            $link = $dir.'/link.txt';
+            symlink($real, $link);
+
+            return $link;
+        })(),
+        'broken-symlink' => (function () use ($dir): string {
+            $link = $dir.'/broken.txt';
+            symlink($dir.'/does-not-exist.txt', $link);
+
+            return $link;
+        })(),
+        'directory' => $dir,
+        'missing' => $dir.'/absent.txt',
+    };
+
+    expect(fn (): string => RegularFileReader::read($path, '検体'))->toThrow(RuntimeException::class);
+})->with(['symlink', 'broken-symlink', 'directory', 'missing']);
+
+test('負例: RegularFileReader は regular file の判定を通った後の読み取り失敗も例外にする', function (): void {
+    // symlink / 不在 は手前の分岐で落ちるので、この最後の分岐は読み取り器を注入しないと通れない
+    $dir = sys_get_temp_dir().'/t236-reader-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+    $path = $dir.'/plain.txt';
+    file_put_contents($path, "abc\n");
+
+    expect(fn (): string => RegularFileReader::read(
+        $path,
+        '検体',
+        static fn (string $p): string|false => false,
+    ))->toThrow(RuntimeException::class);
+});
+
+test('正例: RegularFileReader は注入された読み取り器の結果をそのまま返す', function (): void {
+    $dir = sys_get_temp_dir().'/t236-reader-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+    $path = $dir.'/plain.txt';
+    file_put_contents($path, "on-disk\n");
+
+    expect(RegularFileReader::read($path, '検体', static fn (string $p): string|false => "injected\n"))
+        ->toBe("injected\n");
+});
+
+test('負例: 指紋台帳が symlink なら読み取り口が拒否する (母集合の差し替えを塞ぐ)', function (): void {
+    // 現物を差し替えずに検出力を裏取りする: 実ファイルへのリンクを一時ディレクトリへ作り、
+    // 「中身は読めるがリンクである」入力が拒否されることを見る。
+    $dir = sys_get_temp_dir().'/t236-ledger-link-'.bin2hex(random_bytes(6));
+    mkdir($dir, 0o777, true);
+    $link = $dir.'/template-fingerprints.json';
+    symlink(base_path(LedgerPins::FINGERPRINT_LEDGER_PATH), $link);
+
+    // リンク先は正当な指紋台帳なので、素の file_get_contents なら通ってしまう
+    expect(FingerprintLedger::fromJson((string) file_get_contents($link))->role)->toBe(LedgerRole::App);
+
+    // 読み取り口は拒否する
+    expect(fn (): string => RegularFileReader::read($link, '指紋台帳'))->toThrow(RuntimeException::class);
+});
diff --git a/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
index bf8bad0e..b714de66 100644
--- a/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
+++ b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
@@ -11,6 +11,7 @@
 use Tests\Support\TemplateDivergence\FingerprintGenerationService;
 use Tests\Support\TemplateDivergence\FingerprintLedger;
 use Tests\Support\TemplateDivergence\GenerationRefused;
+use Tests\Support\TemplateDivergence\InventoryPresence;
 use Tests\Support\TemplateDivergence\LedgerPins;
 use Tests\Support\TemplateDivergence\LedgerRole;
 use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
@@ -102,6 +103,7 @@ function generatorRun(
     bool $adopt = false,
     ?string $templateCommit = null,
     ?callable $writer = null,
+    ?callable $remover = null,
 ): array {
     foreach ($files as $relative => $contents) {
         $absolute = $root.'/'.$relative;
@@ -134,7 +136,7 @@ function generatorRun(
         writer: $writer ?? $io['writer'],
         reader: $io['reader'],
         renamer: $io['renamer'],
-        remover: $io['remover'],
+        remover: $remover ?? $io['remover'],
     );
 }
 
@@ -668,7 +670,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
         ->and($debt['templateLedgerCommit'])->toBe($ledger->generatedAtCommit);
 });
 
-test('負例: service の拒否 4 経路では生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
+test('負例: service の拒否 4 経路は GenerationRefused で、生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
     $root = generatorTempRoot();
 
     // まず正常な生成物を作る (以後これが 1 バイトも変わらないことを見る)
@@ -686,12 +688,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
     $io = generatorIo();
 
     $call = match ($case) {
-        // 1: 既存台帳が role: template (CLI は先に exit 3 するが、型の側でも閉じる)
-        'role' => fn (): mixed => FingerprintGenerationContext::forRoot(
-            $root, str_repeat('a', 64), str_repeat('1', 40), false,
-            new FingerprintLedger(1, LedgerRole::Template, str_repeat('1', 40), ['a.php' => str_repeat('0', 64)]),
-        ),
-        // 2: 入力の sha256 が pin と違うのに載せ替えフラグが無い
+        // 1: 入力の sha256 が pin と違うのに載せ替えフラグが無い
         'sha' => function () use ($root, $previous, $io): mixed {
             $raw = generatorTemplateRaw(['a.php' => hash('sha256', 'A')]);
 
@@ -712,7 +709,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
                 remover: $io['remover'],
             );
         },
-        // 3: 債務へ新規パスを追加しようとした
+        // 2: 債務へ新規パスを追加しようとした
         'debt' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
@@ -721,7 +718,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
             previousLedger: $previous,
             templateCommit: $previous->generatedAtCommit,
         ),
-        // 4: 同じ正典入力のまま母集合を縮小しようとした
+        // 3: 同じ正典入力のまま母集合を縮小しようとした
         'shrink' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A')],
@@ -729,13 +726,272 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
             previousLedger: $previous,
             templateCommit: $previous->generatedAtCommit,
         ),
+        // 4: 出力先が既に追跡下にあるのに初回生成 (採用) をやり直そうとした
+        //    = 指紋台帳を消すだけで債務を再基準化する経路を塞ぐ
+        'reseed' => fn (): mixed => generatorRun(
+            root: $root,
+            templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+            files: [
+                'a.php' => 'A',
+                'b.php' => 'CHANGED',
+                LedgerPins::FINGERPRINT_LEDGER_PATH => $ledgerBefore,
+            ],
+            previousLedger: null,
+        ),
     };
 
-    expect($call)->toThrow(RuntimeException::class);
+    // **型を名指しで検査する** (素の RuntimeException を通すと「拒否として分類されること」が固定できない)
+    expect($call)->toThrow(GenerationRefused::class);
 
     expect(file_get_contents($ledgerPath))->toBe($ledgerBefore)
         ->and(file_get_contents($debtPath))->toBe($debtBefore);
-})->with(['role', 'sha', 'debt', 'shrink']);
+})->with(['sha', 'debt', 'shrink', 'reseed']);
+
+test('正例: 出力先がまだ追跡下に無いときだけ初回生成 (採用) が通る', function (): void {
+    $root = generatorTempRoot();
+
+    $report = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+        previousLedger: null,
+    );
+
+    expect($report['seeded'])->toBeTrue()
+        ->and($report['adoptionDebtCount'])->toBe(1);
+});
+
+test('負例: 初回生成 (採用) のガードの 3 つの blocker が 1 件ずつ拒否になる', function (array $extraFiles, array $existingDebt): void {
+    $root = generatorTempRoot();
+
+    expect(fn (): array => generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED', ...$extraFiles],
+        existingDebt: $existingDebt,
+        previousLedger: null,
+    ))->toThrow(GenerationRefused::class);
+})->with([
+    '1: 指紋台帳だけが追跡済み' => [
+        [LedgerPins::FINGERPRINT_LEDGER_PATH => '{}'],
+        [],
+    ],
+    // ★Codex Round 2 の指摘: ヘッダだけの旧一覧が残っている形は引退遷移の近くで現実に起こり得る
+    '2: 債務一覧だけが追跡済み (指紋台帳は未追跡)' => [
+        [AdoptionDebtInventory::INVENTORY_PATH => '# template_ledger_commit='.str_repeat('1', 40)."\n"],
+        [],
+    ],
+    '3: 既存債務が非空' => [
+        [],
+        ['b.php' => '0000000000000000000000000000000000000000000000000000000000000009'],
+    ],
+]);
+
+// ---------------------------------------------------------------------------
+// 引退 (債務 0 件) が安定状態であること
+// ---------------------------------------------------------------------------
+
+test('引退は安定状態である — 0 件になったら一覧を書かず、台帳更新でも再作成しない', function (): void {
+    $root = generatorTempRoot();
+    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+
+    // --- 第 1 世代: 未登録の相違が 1 件あるので一覧が作られる ---
+    $first = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+        templateCommit: generatorCommit('a'),
+    );
+
+    expect($first['adoptionDebtCount'])->toBe(1)
+        ->and($first['retired'])->toBeFalse()
+        ->and(is_file($debtPath))->toBeTrue();
+
+    // --- 第 2 世代: 内容をテンプレートへ戻したので債務が 0 件になる ---
+    $second = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'B'],
+        existingDebt: ['b.php' => hash('sha256', 'CHANGED')],
+        previousLedger: FingerprintLedger::fromJson((string) file_get_contents($ledgerPath)),
+        templateCommit: generatorCommit('a'),
+    );
+
+    expect($second['adoptionDebtCount'])->toBe(0)
+        ->and($second['retired'])->toBeTrue()
+        ->and($second['debtInventoryRemoved'])->toBeTrue()
+        // **「0 件の一覧」ではなく「一覧が無い」が正しい生成物の状態である**
+        ->and(is_file($debtPath))->toBeFalse();
+
+    // --- 第 3 世代: 新しい正典台帳を取り込む (新規債務は発生しない) ---
+    $third = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'B'],
+        existingDebt: [],
+        previousLedger: FingerprintLedger::fromJson((string) file_get_contents($ledgerPath)),
+        adopt: true,
+        templateCommit: generatorCommit('b'),
+    );
+
+    // --- 引退状態が維持され、一覧は再作成されない ---
+    expect($third['adoptionDebtCount'])->toBe(0)
+        ->and($third['retired'])->toBeTrue()
+        ->and(is_file($debtPath))->toBeFalse()
+        ->and(FingerprintLedger::fromJson((string) file_get_contents($ledgerPath))->generatedAtCommit)
+        ->toBe(generatorCommit('b'));
+
+    // 引退状態は掃除の判定でも合格する (pin を 0 へ、対象パスから 1 行外した状態)
+    expect(AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        presence: InventoryPresence::fromPath($debtPath),
+        isRegisteredAsTargetPath: false,
+        divergenceEntryExists: true,
+    ))->toBe([]);
+});
+
+test('引退の削除経路は残置を成功扱いにしない (6 形)', function (string $kind, bool $expectThrow, bool $expectRemoved): void {
+    $root = generatorTempRoot();
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+    $io = generatorIo();
+
+    // 一覧のパスを「その形」で用意する (実ファイルシステム上に本物を作る)
+    match ($kind) {
+        'regular' => file_put_contents($debtPath, '# template_ledger_commit='.str_repeat('1', 40)."\n"),
+        'symlink' => (function () use ($root, $debtPath): void {
+            $real = $root.'/real.tsv';
+            file_put_contents($real, "x\n");
+            symlink($real, $debtPath);
+        })(),
+        'broken-symlink' => symlink($root.'/absent.tsv', $debtPath),
+        'directory' => mkdir($debtPath, 0o777, true),
+        'remover-fails' => file_put_contents($debtPath, "x\n"),
+        'absent' => null,
+    };
+
+    $call = fn (): array => generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'A'], // 一致だけなので債務 0 件 = 引退の経路へ入る
+        remover: $kind === 'remover-fails' ? static fn (string $path): bool => false : null,
+    );
+
+    if ($expectThrow) {
+        expect($call)->toThrow(RuntimeException::class);
+
+        // 残置は残ったままである (成功扱いにしていない)
+        expect(InventoryPresence::fromPath($debtPath))->not->toBe(InventoryPresence::Absent);
+
+        return;
+    }
+
+    $report = $call();
+
+    expect($report['retired'])->toBeTrue()
+        ->and($report['debtInventoryRemoved'])->toBe($expectRemoved)
+        ->and(InventoryPresence::fromPath($debtPath))->toBe(InventoryPresence::Absent);
+})->with([
+    '1: 通常ファイルは削除される' => ['regular', false, true],
+    '2: symlink はリンクが消えて不在になる' => ['symlink', false, true],
+    '3: 壊れた symlink は残るので例外' => ['broken-symlink', true, false],
+    '4: ディレクトリは残るので例外' => ['directory', true, false],
+    '5: 削除器が false を返したら例外' => ['remover-fails', true, false],
+    '6: 元から不在なら削除しない' => ['absent', false, false],
+]);
+
+test('引退の報告は 3 つの事実を混ぜない (retired / newlyRetired / debtInventoryRemoved)', function (): void {
+    $root = generatorTempRoot();
+    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+
+    // 第 1 世代: 未登録の相違が 1 件あるので一覧が作られる (引退していない)
+    $first = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'CHANGED'],
+    );
+
+    expect($first['retired'])->toBeFalse()
+        ->and($first['newlyRetired'])->toBeFalse()
+        ->and($first['debtInventoryRemoved'])->toBeFalse()
+        ->and(is_file($debtPath))->toBeTrue();
+
+    // 第 2 世代: 内容を戻して 0 件へ**遷移**した回 (3 つとも真)
+    $transition = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'A'],
+        existingDebt: ['a.php' => hash('sha256', 'CHANGED')],
+        previousLedger: FingerprintLedger::fromJson((string) file_get_contents($ledgerPath)),
+    );
+
+    expect($transition['retired'])->toBeTrue()
+        ->and($transition['newlyRetired'])->toBeTrue()
+        ->and($transition['debtInventoryRemoved'])->toBeTrue();
+
+    // 第 3 世代: 既に引退している状態での再実行 (retired だけが真)
+    $stable = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'A'],
+        existingDebt: [],
+        previousLedger: FingerprintLedger::fromJson((string) file_get_contents($ledgerPath)),
+    );
+
+    expect($stable['retired'])->toBeTrue()
+        ->and($stable['newlyRetired'])->toBeFalse()
+        ->and($stable['debtInventoryRemoved'])->toBeFalse();
+});
+
+test('引退後に新しい債務が生じたら一覧は再作成される (機構が再開する)', function (): void {
+    $root = generatorTempRoot();
+    $ledgerPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;
+    $debtPath = $root.'/'.AdoptionDebtInventory::INVENTORY_PATH;
+
+    // 引退済みの状態を作る (一致しか無いので債務 0 件)
+    generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A')],
+        files: ['a.php' => 'A'],
+        templateCommit: generatorCommit('a'),
+    );
+    expect(is_file($debtPath))->toBeFalse();
+
+    // 台帳を載せ替えてテンプレート側が前進した = 前世代の正典ハッシュと一致する新規債務
+    $report = generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A2')],
+        files: ['a.php' => 'A'],
+        previousLedger: FingerprintLedger::fromJson((string) file_get_contents($ledgerPath)),
+        adopt: true,
+        templateCommit: generatorCommit('b'),
+    );
+
+    expect($report['adoptionDebtCount'])->toBe(1)
+        ->and($report['retired'])->toBeFalse()
+        ->and(is_file($debtPath))->toBeTrue()
+        ->and($report['addedDebt'])->toBe(['a.php']);
+});
+
+// ---------------------------------------------------------------------------
+// role ガード (CLI が呼ぶ判定本体) — 両方向
+// ---------------------------------------------------------------------------
+
+test('正例: 既存台帳の role が app なら role ガードは通す', function (): void {
+    $ledger = new FingerprintLedger(1, LedgerRole::App, generatorCommit('a'), ['a.php' => generatorHash('1')]);
+
+    FingerprintGenerationService::assertAppLedgerRole($ledger);
+
+    expect(true)->toBeTrue(); // 例外が出ないことが検査である
+});
+
+test('負例: 既存台帳の role が template なら role ガードが拒否する', function (): void {
+    $ledger = new FingerprintLedger(1, LedgerRole::Template, generatorCommit('a'), ['a.php' => generatorHash('1')]);
+
+    expect(fn (): mixed => FingerprintGenerationService::assertAppLedgerRole($ledger))
+        ->toThrow(GenerationRefused::class);
+});
 
 test('負例: 書き込み開始前の失敗では生成物が作られない', function (callable $call): void {
     expect($call)->toThrow(RuntimeException::class);
@@ -926,3 +1182,37 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
     '入力ファイルが存在しない' => [['--template-ledger=/tmp/t236-does-not-exist.json']],
     '--template-ledger の値が空' => [['--template-ledger=']],
 ]);
+
+test('負例: 生成器は pin と違う正典台帳を渡されると書き込み前に exit 3 で拒否する', function (): void {
+    // GenerationRefused → 終了コード 3 の写像を**実プロセスで**裏取りする。
+    // sha256 のガードは書き込みに一切到達しないので、本物の生成物には触れない。
+    $ledgerPath = base_path(LedgerPins::FINGERPRINT_LEDGER_PATH);
+    $debtPath = base_path(AdoptionDebtInventory::INVENTORY_PATH);
+    $ledgerBefore = (string) file_get_contents($ledgerPath);
+    $debtBefore = (string) file_get_contents($debtPath);
+
+    // pin とは違う「正当な (正準形の) 正典台帳」を一時ファイルへ置く
+    $input = sys_get_temp_dir().'/t236-other-template-ledger-'.bin2hex(random_bytes(6)).'.json';
+    file_put_contents($input, generatorTemplateRaw(['tests/Pest.php' => hash('sha256', 'not-the-pinned-ledger')]));
+
+    $process = new Process(
+        ['php', 'scripts/update-template-fingerprints.php', '--template-ledger='.$input],
+        base_path(),
+    );
+    $process->run();
+
+    expect($process->getExitCode())->toBe(3, '標準エラー: '.$process->getErrorOutput())
+        ->and($process->getErrorOutput())->toContain('refused:')
+        ->and(file_get_contents($ledgerPath))->toBe($ledgerBefore)
+        ->and(file_get_contents($debtPath))->toBe($debtBefore);
+
+    unlink($input);
+});
+
+/*
+ * **裏取りできない範囲 (誇張しない)**: CLI の role ガード自身の exit 3 は、
+ * 本物の `docs/template-fingerprints.json` を `role: template` へ書き換えないと到達できないため
+ * 実プロセスでは検査していない。型 (`GenerationRefused`) は上のテストが写像ごと固定し、
+ * 「前世代の台帳が app でない」入力条件は
+ * `FingerprintGenerationContext` の負例 (形 5) が固定する。
+ */
```
