# 対応マトリクス: impl-review Round 1

## [Critical] F12 が債務 0 件のとき無条件で成功し、掃除漏れを検出しない

- 判断: **対応する**
- 根拠: 指摘のとおりである。TODO の名前そのものが「掃除漏れ検出」であり、
  D34 の再判定の条件は「一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す)」と
  書いてある。現状の F12 は 0 件で `expect(true)->toBeTrue()` に落ちるので、
  **ヘッダだけの一覧と D34 が残った状態を緑にする** = 守りたい遷移をちょうど守れていない。
  さらに `fingerprintDebt()` と F0 が一覧ファイルを常に要求するため、
  正しい最終状態 (pin 0 / 一覧ファイル削除 / 登録削除) を**表現できない**。
- 対応内容:
  1. 判定を純関数 `AdoptionDebtInventory::retirementViolations(int $pinnedCount,
     bool $inventoryExists, bool $isRegistered): list<string>` へ切り出した。
     pin > 0 なら「一覧ファイルと登録が必須」、pin === 0 なら
     「一覧ファイルと登録が存在したら違反」を両方向で返す (負の pin も違反)。
  2. gate 側は `LedgerPins::ADOPTION_DEBT_COUNT` を状態の軸にして、
     0 件なら一覧を読まず**空の債務集合**を突合へ渡す (F0 も 0 件では一覧を要求しない)。
     F14 も 0 件では世代識別子の突き合わせへ進まない (ヘッダが存在しないため)。
  3. 両方向の負例と正例を
     `TemplateDivergenceFingerprintRulesTest` へ足した (4 通りの組み合わせ全数)。

## [Warning] 指紋台帳が symlink でも受理される

- 判断: **対応する**
- 根拠: 母集合を決める正本なので、債務一覧と同じ強さで守るべきである。
  `file_get_contents()` はリンク先を読むので、リンクを差し替えれば母集合ごと入れ替えられる。
- 対応内容: `fingerprintLedgerRaw()` を `is_file() && ! is_link()` 必須にし、
  F0 で指紋台帳と (0 件でなければ) 債務一覧が regular file であることを明示的に検査した。
  自己ハッシュは循環するので取らない (指摘どおり regular file 条件だけを独立に見る)。

## [Warning] 初回生成 (seeding) の抜け道を保証外の記述だけで済ませている

- 判断: **対応する**
- 根拠: 「fail-closed を持ち込む」という本 TODO の目的に対して、
  塞げるのに保証外へ逃がすのは弱い。指摘された条件は実装可能である。
- 対応内容: `FingerprintGenerationService` に seeding のガードを足した。
  `previousLedger === null` の生成を許すのは、**指紋台帳と債務一覧のどちらも
  `git ls-files` に無く、既存債務も空**のときだけである。
  出力先が追跡済みなのに working tree で読めない場合は「初回」ではなく
  **削除・検査不能**として `GenerationRefused` にする。
  これで本当の導入時は通り、導入後の単純な削除による再採用は拒否される。
  index からの削除まで伴う改変は従来どおり PR レビューの限界として docblock に残した。

## [Warning] `GenerationRefused` の docblock が実装と食い違う (role 違反は CLI が直接 exit 3)

- 判断: **対応する**
- 根拠: docblock が 4 経路と書いているのに、そのうち 1 つが例外型を使っていない。
  型の説明が実装を説明していない状態である。
- 対応内容: CLI の role ガードを `throw new GenerationRefused(...)` へ変え、
  例外から終了コードへの写像を**1 か所の catch** に集約した
  (拒否 = 3 / 実行不能 = 1)。docblock の 4 経路の記述が実装と一致するようになった。

## [Warning] 拒否 4 経路のテストが例外型を区別していない / exit 3 の写像を裏取りしていない

- 判断: **対応する**
- 根拠: `toThrow(RuntimeException::class)` は `GenerationRefused` も素の
  `RuntimeException` も通すので、「拒否として分類されること」を固定できていない。
- 対応内容:
  1. service 側の拒否 3 経路 (sha256 不一致 / 債務への新規追加 / 母集合の縮小) と
     新設の seeding ガードは **`GenerationRefused::class` を名指しで**検査するようにした。
  2. `role` ケースは `FingerprintGenerationContext` の負例 (形 5) が既に担当なので
     拒否 4 経路の dataset から外し、そちらへ寄せた。
  3. **実プロセスで exit 3 の写像を裏取りする**テストを新設した。
     入力の sha256 が pin と違う正当な台帳を `--adopt-new-template-ledger` 無しで渡すと
     `GenerationRefused` になり、これは**書き込みに一切到達しない**ので
     本物の生成物に触れずに終了コード 3 を確認できる (生成物の byte 不変も併せて見る)。
  4. **裏取りできない範囲は明記した**: CLI の role ガード自身の exit 3 は、
     本物の指紋台帳を `role: template` へ書き換えないと到達できないため
     プロセスでは検査していない (型は docblock と context の形 5 が固定する)。

## [Suggestion] `PathObservation` の docblock が「7 形」と言いつつハッシュ書式も落とす

- 判断: **対応する**
- 根拠: 件数の主張と実装が食い違っている。設計書の 7 形は
  「状態・ハッシュ・理由の**組み合わせ**」の数であり、ハッシュの**書式**は別の軸である。
- 対応内容: docblock を「組み合わせの 7 形」と「加えて値の書式 (64 桁小文字 hex)」に
  書き分け、テスト側の宣言も同じ書き分けにした (dataset は組み合わせ 7 件のまま、
  書式違反は独立したテストであることを明記)。

## [Suggestion] `FingerprintLedger::matchesIgnoringGeneratedCommit()` は未使用なので消す

- 判断: **対応する**
- 根拠: 当初は「正典との差を 1 点に留める」という詳細設計 S2 の方針に従って残したが、
  役割 (鮮度比較) は role: template 側の検査のためのもので、受け手側には**呼び出し元が無い**。
  「移植差分を小さく保つことは未使用機能を維持する理由にならない」という指摘に同意する
  (思考原則 2)。差は D33 で既に登録済みなので、行を 1 つ足すだけで済む。
- 対応内容: メソッドと対応する単体テストを削除し、D33 の観点表へ
  「鮮度比較」の行を足した。

---

# Round 2: 修正内容

Round 1 の指摘 (Critical 1 / Warning 4 / Suggestion 3) は**すべて対応した**
(反論・見送りは 1 件も無い)。上の対応マトリクスが判断の記録である。

## 検証結果 (修正後・10 本すべて green)

```
composer test              : 6308 tests, 6306 passed, 0 failed, 2 skipped, 5 risky (30189 assertions)
                             ※ Round 1 時点の 6296 件から新設テスト 12 件ぶん増えている
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

生成器の再実行で **pin は 281 / 174 / 33 のまま**、生成物 2 本は **byte 不変**、
債務追加 0 件であることを確認した (アプリ側の指紋台帳が持つのは**正典側の**ハッシュなので、
アプリ側のファイルを編集しても母集合の内容は動かない)。

## 特に確認してほしい点

1. **Critical (F12) の直し方**が指摘の意図どおりか。判定を純関数
   `AdoptionDebtInventory::retirementViolations(int $pinnedCount, bool $inventoryExists,
   bool $isRegistered): list<string>` に切り出し、gate は `LedgerPins::ADOPTION_DEBT_COUNT` を
   状態の軸にした。0 件のときは一覧を読まず空の債務集合を突合へ渡し、
   「一覧ファイルが残っている」「登録が残っている」をそれぞれ違反にする。
   **4 通りの組み合わせ全数** (pin>0 × 一覧有無 × 登録有無 の 4 件 + pin=0 の 4 件 = 8 dataset) を
   両方向で固定した。

2. **seeding ガードの条件**が過剰でないか。`previousLedger === null` の生成を許すのは
   「指紋台帳と債務一覧のどちらも `git ls-files` に無く、既存債務も空」のときだけにした。
   本リポジトリの実際の導入時 (出力先がまだ追跡下に無い) は通り、
   導入後に指紋台帳を消して再実行する経路は `GenerationRefused` になる。
   なお**この条件は当リポジトリの導入手順を実際に通した** — 初回生成は `git add` の前に
   走らせたので出力先が未追跡であり、ガードを足した後の再実行は前世代の台帳があるので
   seeding 経路に入らない。

3. **exit 3 の裏取りの範囲の書き方**。実プロセスで裏取りしたのは
   「pin と違う正当な正典台帳を `--adopt-new-template-ledger` 無しで渡すと exit 3 になり、
   生成物が byte 不変である」ことである (sha256 ガードは書き込みに到達しないため
   本物の生成物に触れずに写像を確認できる)。
   **CLI の role ガード自身の exit 3 は実プロセスでは裏取りしていない** —
   本物の指紋台帳を `role: template` へ書き換えないと到達できないためで、
   その旨をテストファイルのコメントに明記した。この線引きで良いか、
   それとも role ガードも到達可能にする作りへ変えるべきか。

4. **`PathObservation` の件数の書き分け**が曖昧さを解消できているか
   (組み合わせ 7 形 / 値の書式は別軸で数に入れない)。

## 修正差分 (git diff。Round 1 で提示した差分からの delta のみ)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 9751cd3a..fcae4a0d 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -1959,6 +1959,9 @@ ## D33 テンプレート乖離の突合を、正典の分類規則ではなく
 | 母集合の出典 | 自リポジトリの `git ls-files` を `SharedPathRules` (22KB の規則表) で分類する | 正典が公開する指紋台帳のキー ∩ 自リポジトリの追跡ファイル (規則表は持ち込まない) |
 | パスの書式判定 | `SharedPathRules::isValidRepoRelativePath()` | `RepoRelativePath::isValid()` (書式判定だけを切り出した 1 クラス) |
 | 指紋台帳の解釈 | 連想配列で解釈する | object 形で解釈し、空配列と空 object を型で区別する |
+| 鮮度比較 | `matchesIgnoringGeneratedCommit()` で指紋台帳が古くなっていないかを見る | 持たない (受け手側には呼び出し元が無いため。思考原則 2) |
+| 指紋台帳自身の種別 | 検査しない | 母集合の正本なので regular file であることを要求する (symlink 差し替えを塞ぐ) |
+| 初回生成 (採用) の条件 | 概念が無い (提供元は毎回生成する) | 出力先 2 本がどちらも追跡下に無く既存債務も空のときだけ許す (削除して再採用する経路を塞ぐ) |
 | 正本の正準形 | 検査しない | 正本のバイト列が解釈して直列化し直した結果と完全一致することを要求する (重複キー・整形の崩れを落とす) |
 | 突合の DTO | 対象パスを 1 件だけ持つ `DivergenceEntry` | 対象パスの複数指定を許す本アプリの解析結果をそのまま使う |
 | 生成器の起動 | 提供元で走らせ、子アプリでは role ガードが拒否する | 受け手側で走らせ、入力の正典台帳を `--template-ledger` で渡す (既存台帳が `role: template` なら拒否) |
diff --git a/scripts/update-template-fingerprints.php b/scripts/update-template-fingerprints.php
index 684fd282..2d6a4534 100644
--- a/scripts/update-template-fingerprints.php
+++ b/scripts/update-template-fingerprints.php
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
@@ -165,6 +149,22 @@
 
 // --- 生成 ---
 try {
+    if (is_file($fingerprintPath)) {
+        $existingRaw = file_get_contents($fingerprintPath);
+        if ($existingRaw === false) {
+            throw new RuntimeException("既存の指紋台帳を読めない: {$fingerprintPath}");
+        }
+
+        $previousLedger = FingerprintLedger::fromJson($existingRaw);
+
+        if ($previousLedger->role !== LedgerRole::App) {
+            throw new GenerationRefused(
+                '既存の指紋台帳の role が app でない。'
+                    .'本リポジトリはテンプレートの受け手なので、正典側の生成器を走らせてはならない。',
+            );
+        }
+    }
+
     $context = FingerprintGenerationContext::forRoot(
         root: $root,
         expectedTemplateLedgerSha256: LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256,
diff --git a/tests/Architecture/TemplateDivergenceFingerprintTest.php b/tests/Architecture/TemplateDivergenceFingerprintTest.php
index 2ae3f05f..171bfe2a 100644
--- a/tests/Architecture/TemplateDivergenceFingerprintTest.php
+++ b/tests/Architecture/TemplateDivergenceFingerprintTest.php
@@ -84,10 +84,22 @@ function fingerprintRequiredMembers(): array
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
+    $path = base_path(LedgerPins::FINGERPRINT_LEDGER_PATH);
+    if (is_link($path) || ! is_file($path)) {
+        throw new RuntimeException(
+            '指紋台帳 '.LedgerPins::FINGERPRINT_LEDGER_PATH.' が regular file として存在しない',
+        );
+    }
+
+    $raw = file_get_contents($path);
     if ($raw === false) {
         throw new RuntimeException('指紋台帳 '.LedgerPins::FINGERPRINT_LEDGER_PATH.' を読めない');
     }
@@ -101,17 +113,42 @@ function fingerprintLedger(): FingerprintLedger
     return FingerprintLedger::fromJson(fingerprintLedgerRaw());
 }
 
+/**
+ * 採用時債務が引退済みか (件数の pin が 0)。
+ *
+ * 引退後は**一覧ファイルも登録も残っていてはならない**ので、gate は pin を状態の軸にして
+ * 一覧を読まずに空の債務集合を突合へ渡す。判定の両方向は
+ * `AdoptionDebtInventory::retirementViolations()` が持つ。
+ */
+function fingerprintDebtRetired(): bool
+{
+    return LedgerPins::ADOPTION_DEBT_COUNT === 0;
+}
+
+/** 一覧ファイルが regular file として実在するか (symlink は実在扱いにしない)。 */
+function fingerprintDebtInventoryExists(): bool
+{
+    $path = base_path(AdoptionDebtInventory::INVENTORY_PATH);
+
+    return is_file($path) && ! is_link($path);
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
@@ -254,8 +291,15 @@ function fingerprintReconciliation(): ReconciliationResult
 
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
+        expect(fingerprintDebtInventoryExists())->toBeTrue();
+    }
 
     // 負のコントロール: 読めない入力が黙って空へ潰れず例外になること
     expect(fn (): array => AdoptionDebtInventory::read(base_path('storage/framework/t236-absent')))
@@ -357,20 +401,18 @@ function fingerprintReconciliation(): ReconciliationResult
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
+    // pin が 1 件以上 → 一覧ファイルと登録が必須 / pin が 0 件 → どちらも残っていてはならない。
+    // **0 件を無条件で合格にしない** (ヘッダだけの一覧と D34 が残った状態を緑にしないため)。
     $registeredPaths = array_column(fingerprintRegisteredPaths(), 'path');
 
-    expect(in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true))->toBeTrue(
-        '債務が残っている間は '.AdoptionDebtInventory::INVENTORY_PATH.' を登録簿へ登録しておくこと',
+    $violations = AdoptionDebtInventory::retirementViolations(
+        LedgerPins::ADOPTION_DEBT_COUNT,
+        fingerprintDebtInventoryExists(),
+        in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true),
     );
+
+    expect($violations)->toBe([], '採用時債務の掃除漏れ:'.PHP_EOL.implode(PHP_EOL, $violations));
 });
 
 test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
@@ -389,6 +431,13 @@ function fingerprintReconciliation(): ReconciliationResult
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
diff --git a/tests/Support/TemplateDivergence/AdoptionDebtInventory.php b/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
index 90db7428..f21eb284 100644
--- a/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
+++ b/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
@@ -128,6 +128,50 @@ public static function parse(string $contents): array
         return ['templateLedgerCommit' => $matches[1], 'entries' => $entries];
     }
 
+    /**
+     * 「債務が 0 件になったときの掃除」が済んでいるかを判定する (純関数)。
+     *
+     * 一覧が 0 件になったら**一覧ファイルと登録を同じ変更で消す**のが D34 の再判定の条件である。
+     * 件数の pin を状態の軸にして、次の両方向を落とす:
+     *  - pin が 1 件以上: 一覧ファイルと登録がどちらも必須 (無ければ違反)
+     *  - pin が 0 件: 一覧ファイルと登録はどちらも残っていてはならない (残っていたら違反)
+     *
+     * ★**0 件を無条件で合格にしない**のが要点である。ヘッダだけの一覧と登録が残った状態を
+     *   緑にすると、本機構が名前どおり「掃除漏れ」を検出できないことになる。
+     *
+     * @return list<string> 違反の説明 (空 = 合格)
+     */
+    public static function retirementViolations(int $pinnedCount, bool $inventoryExists, bool $isRegistered): array
+    {
+        if ($pinnedCount < 0) {
+            throw new RuntimeException("採用時債務の件数 pin が負である: {$pinnedCount}");
+        }
+
+        $violations = [];
+
+        if ($pinnedCount > 0) {
+            if (! $inventoryExists) {
+                $violations[] = '債務が '.$pinnedCount.' 件あるのに一覧ファイル ('.self::INVENTORY_PATH.') が無い';
+            }
+            if (! $isRegistered) {
+                $violations[] = '債務が残っている間は '.self::INVENTORY_PATH.' を登録簿へ登録しておくこと';
+            }
+
+            return $violations;
+        }
+
+        if ($inventoryExists) {
+            $violations[] = '債務が 0 件になったのに一覧ファイル ('.self::INVENTORY_PATH.') が残っている '
+                .'(同じ変更で削除すること)';
+        }
+        if ($isRegistered) {
+            $violations[] = '債務が 0 件になったのに '.self::INVENTORY_PATH.' の登録が登録簿に残っている '
+                .'(D34 ごと削除し、LedgerPins::DIVERGENCE_ENTRY_COUNT を 1 減らすこと)';
+        }
+
+        return $violations;
+    }
+
     /**
      * 検証済みの内容から一覧の本文を組み立てる (生成器が使う。読み書きの正準形を 1 か所にする)。
      *
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationService.php b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
index b80f34a5..81f7e90d 100644
--- a/tests/Support/TemplateDivergence/FingerprintGenerationService.php
+++ b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
@@ -98,6 +98,32 @@ public static function generate(
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
diff --git a/tests/Support/TemplateDivergence/FingerprintLedger.php b/tests/Support/TemplateDivergence/FingerprintLedger.php
index 6d841237..5041ecc2 100644
--- a/tests/Support/TemplateDivergence/FingerprintLedger.php
+++ b/tests/Support/TemplateDivergence/FingerprintLedger.php
@@ -12,10 +12,10 @@
  * 指紋台帳 `docs/template-fingerprints.json` の DTO と直列化。
  *
  * 解釈不能はすべて例外 (正典の boundary (5c)「検査自体が実行不能なら fail」)。
- * `generated_at_commit` は情報フィールドであり **鮮度比較では比較しない** —
- * 生成コミット自身を比較に含めると「生成時点で未存在の commit」を要求する循環になる。
+ * `generated_at_commit` は出自を示す情報フィールドであり、利用側 (突合 gate の F5) は
+ * pin との一致だけを見る。
  *
- * 正典 (laravel-claude-template) からの移植で、差は 2 点だけである
+ * 正典 (laravel-claude-template) からの移植で、差は 3 点だけである
  * (`docs/template-divergence.md` D33 に登録済み):
  *  1. キーの書式判定を `SharedPathRules::isValidRepoRelativePath()` から
  *     `RepoRelativePath::isValid()` へ差し替えた (規則表を持ち込まないため)
@@ -23,6 +23,9 @@
  *     `{"entries": []}` のような**空配列と空 object の混同を受理してしまう**。
  *     本リポジトリは突合 gate が「entries が object であること」を負例で固定するので、
  *     両者を型で区別できる object 形にした (過剰検出寄りへの上積み)
+ *  3. 鮮度比較 (`matchesIgnoringGeneratedCommit()`) を持たない。あれは提供元側が
+ *     「指紋台帳が古くなっていないか」を見るためのもので、受け手側には呼び出し元が無い
+ *     (思考原則 2 = 今必要なものだけ作る)
  *
  * **重複キーは本クラスでは検出できない** (`json_decode` が後勝ちで潰すため)。
  * 検出は利用側が**正準形バイト一致** (`$raw === self::fromJson($raw)->toJson()`) を
@@ -138,14 +141,4 @@ public function toJson(): string
             'entries' => (object) $entries,
         ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
     }
-
-    /**
-     * 鮮度比較。`generated_at_commit` は比較しない (循環回避)。
-     */
-    public function matchesIgnoringGeneratedCommit(self $other): bool
-    {
-        return $this->schemaVersion === $other->schemaVersion
-            && $this->role === $other->role
-            && $this->entries === $other->entries;
-    }
 }
diff --git a/tests/Support/TemplateDivergence/PathObservation.php b/tests/Support/TemplateDivergence/PathObservation.php
index 21eb00d6..8ef6ab90 100644
--- a/tests/Support/TemplateDivergence/PathObservation.php
+++ b/tests/Support/TemplateDivergence/PathObservation.php
@@ -19,7 +19,11 @@
  *    - `MissingCurrent`  + null      + null   (git index / working tree から消えた)
  *    - null              + null      + 空でない理由 (symlink / 非 regular / 読めない / hash 失敗)
  *
- * 落とす 7 形と、許す 4 形が構築できることは
+ * ★件数の言い方を混ぜない — 上の 4 形は**状態・ハッシュ・理由の組み合わせ**の話で、
+ *   落とす組み合わせは **7 形**である。**それとは別の軸として値の書式**も見る
+ *   (ハッシュは 64 桁小文字 hex でなければ例外)。書式は組み合わせの数には数えない。
+ *
+ * 組み合わせの 7 形・書式違反・許す 4 形が構築できることは
  * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` が両方向で固定する。
  */
 final readonly class PathObservation
diff --git a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
index 98893d20..79bf6753 100644
--- a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
+++ b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
@@ -25,7 +25,8 @@
  *
  * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
  *   `FingerprintLedger::fromJson()` = 11 形 / `RepoRelativePath::isValid()` = 8 形 /
- *   `PathObservation` = 7 形 / `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
+ *   `PathObservation` = 組み合わせ 7 形 (値の書式は別軸なので数に入れない) /
+ *   `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
  *   `FingerprintReconciler` = 8 種別。
  *
  * 生成器側 (`AppFingerprintBuilder` / `AtomicLedgerWriter` / `AtomicTextWriter` /
@@ -124,17 +125,6 @@ function adoptionDebtText(string $commit, string ...$lines): string
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
@@ -215,7 +205,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
         ->and($failed->state)->toBeNull();
 });
 
-test('負例: PathObservation が矛盾した組み合わせを例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
+test('負例: PathObservation が矛盾した組み合わせ 7 形を例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
     expect(fn (): PathObservation => new PathObservation($state, $hash, $failure))
         ->toThrow(InvalidArgumentException::class);
 })->with([
@@ -228,7 +218,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
     '7: 検査不能の理由が空文字' => [null, null, ''],
 ]);
 
-test('負例: PathObservation がハッシュの書式違反を例外にする', function (): void {
+test('負例: PathObservation がハッシュの書式違反を例外にする (組み合わせとは別の軸)', function (): void {
     expect(fn (): PathObservation => new PathObservation(ComparisonState::Matched, 'DEADBEEF', null))
         ->toThrow(InvalidArgumentException::class);
 });
@@ -525,8 +515,54 @@ function reconcilerHashesFor(string ...$paths): array
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
+    bool $inventoryExists,
+    bool $isRegistered,
+    bool $expectedClean,
+): void {
+    $violations = AdoptionDebtInventory::retirementViolations($pinnedCount, $inventoryExists, $isRegistered);
+
+    expect($violations === [])->toBe($expectedClean);
+})->with([
+    // pin が 1 件以上: 一覧ファイルと登録がどちらも必須
+    '1 件以上・一覧あり・登録あり → 合格' => [176, true, true, true],
+    '1 件以上・一覧なし → 違反' => [176, false, true, false],
+    '1 件以上・登録なし → 違反' => [176, true, false, false],
+    '1 件以上・両方なし → 違反' => [176, false, false, false],
+    // pin が 0 件: 一覧ファイルと登録はどちらも残っていてはならない
+    '0 件・一覧なし・登録なし → 合格 (掃除済み)' => [0, false, false, true],
+    '0 件・一覧が残っている → 違反' => [0, true, false, false],
+    '0 件・登録が残っている → 違反' => [0, false, true, false],
+    '0 件・両方残っている → 違反' => [0, true, true, false],
+]);
+
+test('負例: 債務の件数 pin が負なら例外にする', function (): void {
+    expect(fn (): array => AdoptionDebtInventory::retirementViolations(-1, true, true))
+        ->toThrow(RuntimeException::class);
+});
+
+test('債務の引退の掃除の違反は直し方まで告げる', function (): void {
+    $violations = AdoptionDebtInventory::retirementViolations(0, true, true);
+
+    expect($violations)->toHaveCount(2)
+        ->and(implode("\n", $violations))->toContain(AdoptionDebtInventory::INVENTORY_PATH)
+        ->and(implode("\n", $violations))->toContain('DIVERGENCE_ENTRY_COUNT');
+});
diff --git a/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
index bf8bad0e..009f988a 100644
--- a/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
+++ b/tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php
@@ -668,7 +668,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
         ->and($debt['templateLedgerCommit'])->toBe($ledger->generatedAtCommit);
 });
 
-test('負例: service の拒否 4 経路では生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
+test('負例: service の拒否 4 経路は GenerationRefused で、生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
     $root = generatorTempRoot();
 
     // まず正常な生成物を作る (以後これが 1 バイトも変わらないことを見る)
@@ -686,12 +686,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
 
@@ -712,7 +707,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
                 remover: $io['remover'],
             );
         },
-        // 3: 債務へ新規パスを追加しようとした
+        // 2: 債務へ新規パスを追加しようとした
         'debt' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
@@ -721,7 +716,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
             previousLedger: $previous,
             templateCommit: $previous->generatedAtCommit,
         ),
-        // 4: 同じ正典入力のまま母集合を縮小しようとした
+        // 3: 同じ正典入力のまま母集合を縮小しようとした
         'shrink' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A')],
@@ -729,13 +724,52 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
+test('負例: 既存債務が残っているのに初回生成をやり直そうとすると拒否される', function (): void {
+    $root = generatorTempRoot();
+
+    expect(fn (): array => generatorRun(
+        root: $root,
+        templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
+        files: ['a.php' => 'A', 'b.php' => 'CHANGED'],
+        existingDebt: ['b.php' => hash('sha256', 'OLD')],
+        previousLedger: null,
+    ))->toThrow(GenerationRefused::class);
+});
 
 test('負例: 書き込み開始前の失敗では生成物が作られない', function (callable $call): void {
     expect($call)->toThrow(RuntimeException::class);
@@ -926,3 +960,37 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
