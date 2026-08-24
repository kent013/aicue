# Round 4 (確認): Round 3 の指摘への対応

Round 3 で [Critical] は 0 件になった。残っていた静的 gate の規約適合と文言整合を**すべて**対応した。

## 対応マトリクス

# 対応マトリクス: impl-review Round 3

> Round 3 で **[Critical] は 0 件**になった。残りは静的 gate の規約適合と文言整合で、すべて対応した。

## [Warning] TLM-2b の判定分岐に gate 側の負例が無い ((c) 規約)

- 判断: **対応する**
- 根拠: 実コードの母集団が現在たまたま空なので、判定を反転・削除しても緑になる形だった
  (AGENTS.md 共通規約 (c) と「新設・変更時の 4 点」の (1) の直接の対象)。
- 対応内容: 判定を純関数 `ticketLedgerMutationIsAmbiguous(bool $model, bool $fqcn, int $mutations)`
  へ切り出し、**4 ケースを両方向で固定**した。
  - `短名のみ + 変更語彙あり` → 違反
  - `短名 + FQCN 解決あり + 変更語彙あり` → 適合
  - `短名のみ + 変更語彙なし` → 対象外
  - `参照なし + 変更語彙あり` → 対象外

## [Warning] docblock の「負例 8 変異」と実数 9 の不一致

- 判断: **対応する** — 「負例 9 変異」へ更新。

## [Warning] TLM-5 の保証表現が「closure の内側」のままの箇所がある

- 判断: **対応する (利用側の名前・メッセージまで含めて狭める)**
- 根拠: AGENTS.md (b) は「保証範囲の外にする構文を docblock へ明記したら、
  その構文について検出力を主張しない」ことを求めており、
  **主張は docblock だけでなく利用側 gate の名前と失敗メッセージまで含む**。
- 対応内容: 次をすべて **「`DB::transaction(` の引数範囲」** へ統一した。
  - 走査器 `lockOrderViolations()` の 5 つの失敗メッセージとコメント (+ メソッド docblock)
  - gate の TLM-5 正例のテスト名
  - `TicketLedgerMutationInventory::APPEND_CALL` のコメント

## [Warning] サービス docblock の「同じ組織行ロックを取ることを静的に pin する」

- 判断: **対応する**
- 対応内容: 「静的に pin できるのは**トークン順の構造まで**」へ書き換え、
  TLM-5 が**ロックの受け手も削除対象も見ない**ことを明記し、限界の正本を走査器 docblock に置いた。

## [Warning] 「どちらの枝にも入らない行が無い」の限定

- 判断: **対応する**
- 対応内容: 「補集合であるのは**同一スナップショット上の述語として**である。
  削除と集約は別の SQL 文なので、その間に commit した行は今回の 2 枝のどちらにも入らず
  次回へ持ち越される (仕様。境界は N1c が固定する)」を docblock に追記。

## [Suggestion] N1c で監視値も固定する

- 判断: **対応する** — `candidates = 1 / processed = 1 / expiredRemaining = 1` を追加し、
  **恒等式がここでは成り立たない**こと (実行中に決着対象が増えたためで述語ずれではない) も
  コメントで明示した。

## [Suggestion] mutation-evidence の 7 / 9 の混在

- 判断: **対応する** — 「基本の 7 変異」「追加: 境界演算子の 2 変異」
  「基本 7 + 境界 2 = 全 9 形」へ表記を整理した。


## 変更差分 (Round 3 の状態からの差分)

```diff
diff --git a/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
index d45e4401..640ebbb1 100644
--- a/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
+++ b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
@@ -43,6 +43,10 @@
  * 第 2 段の述語は {@see TicketLedgerService} の残高集計条件
  * (`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**である。ずらすと
  * 「どちらの枝にも入らない行」か「両方に入る行」が生まれる。
+ * ★補集合であるのは**同一スナップショット上の述語として**である。削除と集約は別の SQL 文なので、
+ *   その間に (組織行ロックを取らない追記経路が) commit した行は今回の 2 枝のどちらにも入らず
+ *   次回の実行へ持ち越される。これは仕様であり、`expires_at = now` の境界でそうなることを
+ *   Feature テスト N1c が固定する。
  *
  * 繰越行は説明・決済事業者の識別子・冪等キー・予約への参照・個別の付与時刻を一切引き継がない。
  * `created_at` は**畳み込んだ行の最大 `created_at`** = 集約の基準時刻である (実行時刻ではない)。
@@ -102,8 +106,12 @@
  * `Tests\Support\Architecture\TicketLedgerMutationInventory` である。
  *
  * **保証しないこと**: 真の並行実行 (別 connection + barrier) での排他の実効性は測っていない。
- * 代わりに「台帳書き込みの既存経路と同じ組織行ロックを、変更より先に、同じトランザクションの
- * 内側で取ること」を静的に pin する。
+ * 静的に pin できるのは**トークン順の構造まで**である —
+ * `TicketLedgerMutationSiteGateTest` (TLM-5) が見るのは
+ * 「変更操作が同一の `DB::transaction(` の引数範囲の内側に閉じており、
+ * ロック語彙がその中の最初の変更操作より前に現れる」ことだけで、
+ * **ロックの受け手が組織モデルか / 削除の対象が台帳かは見ない**
+ * (限界の正本は `Tests\Support\Architecture\TicketLedgerMutationScanner` の docblock)。
  */
 final class TicketLedgerCarryForwardService
 {
diff --git a/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md b/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md
index ed8673bc..f5a480c8 100644
--- a/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md
+++ b/devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md
@@ -15,7 +15,7 @@ ## 測り方
                 tests/Architecture/TicketLedgerMutationSiteGateTest.php
 ```
 
-## 結果 (7 変異すべてが検出された)
+## 結果: 基本の 7 変異 (すべて検出された)
 
 | # | 変異 | 実際に入れた変更 | 赤になったテスト |
 |---|---|---|---|
@@ -27,7 +27,7 @@ ## 結果 (7 変異すべてが検出された)
 | MU6 | **`withTrashed()` を外す** | `Organization::withTrashed()` → `Organization::query()` (2 箇所) | N12/N13 / N14 / TLM-4 / TLM-7 |
 | MU7 | **決着対象から失効した繰越行を外す** | `settlementPredicate()` を `kind != carry_forward` だけにする | N18 |
 
-## 追加で測った境界演算子の変異 (Codex 実装レビュー Round 1・2 の指摘を受けて追加)
+## 追加: 境界演算子の 2 変異 (Codex 実装レビュー Round 1・2 の指摘を受けて追加)
 
 | 変異 | 赤になったテスト |
 |---|---|
@@ -65,4 +65,5 @@ ## 読み取り (誇張しない)
 - **`candidates = processed + expiredRemaining` の恒等式は静止した集合についての性質**である。
   組織行ロックを取らない追記経路が実行中に commit すれば、述語が正しくても崩れる。
   「崩れたら述語ずれ」と断定しないこと (DTO の docblock と runbook にも同じ注意を書いた)。
-- 本表が示すのは**この 9 形の変異に対する検出力**であり、実装の正しさ一般ではない。
+- 本書が示すのは**基本 7 変異 + 境界 2 変異 = 全 9 形**に対する検出力であり、
+  実装の正しさ一般ではない。
diff --git a/tests/Architecture/TicketLedgerMutationSiteGateTest.php b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
index 717b7e36..d0f98c85 100644
--- a/tests/Architecture/TicketLedgerMutationSiteGateTest.php
+++ b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
@@ -41,7 +41,7 @@
  *     (完全修飾名まで解決できない) 状態を**曖昧として失敗させる**。
  *     登録済みファイルの本物の参照を同名の別クラスへ差し替える書き換えを止める
  *   - TLM-5: 畳み込みの**変更操作がすべて同一の `DB::transaction(` の引数範囲の内側にあり、
- *     ロック語彙がその中の最初の変更操作より前にある** (5 条。負例 8 変異で裏取り)。
+ *     ロック語彙がその中の最初の変更操作より前にある** (5 条。負例 9 変異で裏取り)。
  *     **見るのはトークン順の構造だけ**である — 引数範囲は closure 本体そのものではなく
  *     `transaction(` の**引数全体**であり、`lockForUpdate(` の受け手が組織モデルか、
  *     `delete(` の対象が台帳かは**見ない** (限界の正本は走査器の docblock 5b)
@@ -158,6 +158,21 @@ function ticketLedgerLockOrderViolations(string $source): array
     );
 }
 
+/**
+ * TLM-2b の判定 (純関数)。**短名一致だけで当たったモデル参照 + 変更語彙**を曖昧として落とす。
+ *
+ * 実コードの母集団は現在たまたま空なので、この分岐は**合成入力の負例で裏取りする**
+ * (AGENTS.md 共通規約 (c)。母集団が空のまま判定を反転しても緑になる形を作らない)。
+ */
+function ticketLedgerMutationIsAmbiguous(bool $model, bool $modelFqcn, int $mutations): bool
+{
+    if ($mutations === 0 || ! $model) {
+        return false;
+    }
+
+    return ! $modelFqcn;
+}
+
 test('TLM-1: 表名リテラルの出現ファイルと件数が目録と完全一致する', function (): void {
     $detected = [];
     foreach (ticketLedgerMutationScan() as $path => $result) {
@@ -198,10 +213,7 @@ function ticketLedgerLockOrderViolations(string $source): array
     //   TLM-2 の exact-fit を通ってしまうので、**曖昧な参照そのもの**をここで落とす。
     $ambiguous = [];
     foreach (ticketLedgerMutationScan() as $path => $result) {
-        if ($result['mutations'] === 0 || ! $result['model']) {
-            continue;
-        }
-        if (! $result['modelFqcn']) {
+        if (ticketLedgerMutationIsAmbiguous($result['model'], $result['modelFqcn'], $result['mutations'])) {
             $ambiguous[] = $path;
         }
     }
@@ -213,6 +225,19 @@ function ticketLedgerLockOrderViolations(string $source): array
         .PHP_EOL.implode(PHP_EOL, $ambiguous));
 });
 
+test('TLM-2b (負例と正例): 曖昧判定が両方向で正しい', function (bool $model, bool $fqcn, int $mutations, bool $expected): void {
+    expect(ticketLedgerMutationIsAmbiguous($model, $fqcn, $mutations))->toBe($expected);
+})->with([
+    // 短名だけで当たった参照 + 変更語彙 => 曖昧 (落とす)
+    '短名のみ + 変更語彙あり' => [true, false, 1, true],
+    // 完全修飾名まで解決できていれば適合
+    '短名 + FQCN 解決あり + 変更語彙あり' => [true, true, 1, false],
+    // 変更語彙が無ければ対象外 (読むだけのファイルを巻き込まない)
+    '短名のみ + 変更語彙なし' => [true, false, 0, false],
+    // モデル参照が無ければ対象外
+    '参照なし + 変更語彙あり' => [false, false, 3, false],
+]);
+
 test('TLM-3: 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけである', function (): void {
     $detected = [];
     foreach (ticketLedgerMutationScan() as $path => $result) {
@@ -260,7 +285,7 @@ function ticketLedgerLockOrderViolations(string $source): array
         .PHP_EOL.implode(PHP_EOL, $unresolved));
 });
 
-test('TLM-5 (正例): 畳み込みは変更操作をすべてトランザクション closure の内側に置きロックを先頭に取る', function (): void {
+test('TLM-5 (正例): 畳み込みは変更操作をすべて DB::transaction( の引数範囲の内側に置きロックを先頭に取る', function (): void {
     $violations = TicketLedgerMutationScanner::lockOrderViolations(
         TicketLedgerMutationScanner::tokenize(
             ticketLedgerCarryForwardSource(),
diff --git a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
index 6658bb2e..d05a6a94 100644
--- a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
+++ b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
@@ -475,6 +475,14 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect($injected)->toBeTrue(); // 空振り検知: 割り込みが実際に起きた
     expect($result->unexpectedFailures)->toBe(0);
 
+    // ★監視値にも現れる。`candidates` は割り込み前に数えているので 1、
+    //   `expiredRemaining` は割り込んだ行が決着対象として残るので 1 になる。
+    //   **恒等式 candidates = processed + expiredRemaining は成り立たない** —
+    //   実行中に決着対象が増えたからであり、述語ずれではない (DTO docblock の前提どおり)。
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->expiredRemaining)->toBe(1);
+
     // 割り込んだ境界行は**寄与側に取り込まれず、手つかずで残る**
     $survivor = TicketLedgerEntry::query()->where('delta', 9)->sole();
     expect($survivor->kind)->toBe(TicketLedgerKind::Grant);
diff --git a/tests/Support/Architecture/TicketLedgerMutationInventory.php b/tests/Support/Architecture/TicketLedgerMutationInventory.php
index 9099da8a..1dea4bca 100644
--- a/tests/Support/Architecture/TicketLedgerMutationInventory.php
+++ b/tests/Support/Architecture/TicketLedgerMutationInventory.php
@@ -40,7 +40,7 @@ final class TicketLedgerMutationInventory
     /** 畳み込みのロック順序を見るメソッド名。 */
     public const string LOCK_ORDER_METHOD = 'carryForwardOrganization';
 
-    /** 繰越行の追記の呼び出し (TLM-5 の 5 条が closure の内側にあることを要求する)。 */
+    /** 繰越行の追記の呼び出し (TLM-5 の 5 条が `DB::transaction(` の引数範囲の内側にあることを要求する)。 */
     public const string APPEND_CALL = 'appendCarryForward';
 
     /** インスタンス化しない (目録の置き場)。 */
diff --git a/tests/Support/Architecture/TicketLedgerMutationScanner.php b/tests/Support/Architecture/TicketLedgerMutationScanner.php
index 54713628..faa7a377 100644
--- a/tests/Support/Architecture/TicketLedgerMutationScanner.php
+++ b/tests/Support/Architecture/TicketLedgerMutationScanner.php
@@ -226,6 +226,10 @@ public static function trashedScopes(string $relativePath, string $source, array
     /**
      * 畳み込みの「ロック → 変更」構造の違反 (TLM-5 の 5 条)。空配列なら適合。
      *
+     * ★見る範囲は **`DB::transaction(` の引数範囲**であって closure の本体そのものではない
+     *   (第 1 引数が closure であることは要求するが、後続の引数があればその範囲も内側に数える)。
+     *   受け手・削除対象は見ない (保証しないもの 5b)。
+     *
      * @param  list<array{id: int|null, text: string, line: int}>  $tokens
      * @param  list<string>  $mutationVerbs
      * @param  list<string>  $deleteVerbs
@@ -282,16 +286,16 @@ public static function lockOrderViolations(
 
         $violations = [];
 
-        // 条件 2: closure の内側にロックがある
+        // 条件 2: transaction 引数範囲の内側にロックがある
         $locks = array_values(array_filter(
             self::verbPositions($tokens, ['lockForUpdate']),
             static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
         ));
         if ($locks === []) {
-            $violations[] = 'トランザクション closure の内側に lockForUpdate( が無い';
+            $violations[] = 'DB::transaction( の引数範囲の内側に lockForUpdate( が無い';
         }
 
-        // 条件 3: closure の内側に変更操作が 2 種類以上ある (空振り検出を兼ねる)
+        // 条件 3: transaction 引数範囲の内側に変更操作が 2 種類以上ある (空振り検出を兼ねる)
         $deletes = array_values(array_filter(
             self::verbPositions($tokens, $deleteVerbs),
             static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
@@ -301,27 +305,27 @@ public static function lockOrderViolations(
             static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
         ));
         if (count($deletes) < 2) {
-            $violations[] = 'トランザクション closure の内側の削除語彙が 2 つ未満である (空振りの疑い)';
+            $violations[] = 'DB::transaction( の引数範囲の内側の削除語彙が 2 つ未満である (空振りの疑い)';
         }
         if (count($appends) !== 1) {
             $violations[] = sprintf(
-                'トランザクション closure の内側の %s( が %d 個ある (ちょうど 1 つであること)',
+                'DB::transaction( の引数範囲の内側の %s( が %d 個ある (ちょうど 1 つであること)',
                 $appendCall,
                 count($appends),
             );
         }
 
-        // 条件 4: ロックが closure 内の最初の変更操作より前にある
+        // 条件 4: ロックが transaction 引数範囲内の最初の変更操作より前にある
         $operationVerbs = array_values(array_unique([...$mutationVerbs, $appendCall]));
         $operations = array_values(array_filter(
             self::verbPositions($tokens, $operationVerbs),
             static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
         ));
         if ($operations !== [] && $locks !== [] && $locks[0] > $operations[0]) {
-            $violations[] = 'lockForUpdate( が closure 内の最初の変更操作より後ろにある (順序が契約である)';
+            $violations[] = 'lockForUpdate( が DB::transaction( の引数範囲内の最初の変更操作より後ろにある (順序が契約である)';
         }
 
-        // 条件 5: 本体のうち closure の外側に変更操作が 1 つも無い
+        // 条件 5: 本体のうち transaction 引数範囲の外側に変更操作が 1 つも無い
         $outside = array_values(array_filter(
             self::verbPositions($tokens, $operationVerbs),
             static fn (int $i): bool => $i > $bodyStart && $i < $bodyEnd
@@ -329,7 +333,7 @@ public static function lockOrderViolations(
         ));
         if ($outside !== []) {
             $violations[] = sprintf(
-                'メソッド %s() のトランザクション closure の外側に変更操作が %d 個ある',
+                'メソッド %s() の DB::transaction( の引数範囲の外側に変更操作が %d 個ある',
                 $method,
                 count($outside),
             );

```

## 実測結果

- `composer phpstan` (level 10): **No errors**
- `tests/Feature/Billing/TicketLedgerCarryForwardTest.php`: **29 passed**
- `tests/Architecture/TicketLedgerMutationSiteGateTest.php`: **24 passed**
- `tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`: **17 passed**
- `composer test` (全レーン): 7450 tests / **7448 passed** / 2 skipped / 5 risky / **0 failed**
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages`: green。`pnpm test` / `pnpm test:packages` はグローバルテストロック待ちで実行中
  (本 PR は `resources/js` / `resources/css` / `packages/` を 1 バイトも変更していない)
- `vendor/bin/pint --test`: 本 PR の対象ファイルは green。唯一の fail は main にも存在する
  別 TODO の証跡ファイルで、本 PR は触っていない (最終報告に申し送る)

## 質問

残る指摘が無ければ **APPROVED** と明記してほしい。
