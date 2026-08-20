# 対応マトリクス: impl-review Round 2

## [Critical] F12 が D34 の存在ではなく対象パスだけを見ている

- 判断: **対応する**
- 根拠: 指摘のとおりの抜けがある。債務が 0 件になったときに
  「一覧ファイルを消す + D34 の対象パスから `adoption-debt.tsv` だけ削る + D34 は残す」
  という変更をすると、D34 はもう 1 本 (`AdoptionDebtInventory.php`) を対象に持つので
  形式検査 (TD3 = 対象パスが 1 件以上・実在) にも抵触せず、**緑になる**。
  D34 の再判定の条件と F12 の失敗メッセージはどちらも「D34 ごと削除」を要求しているので、
  検査が要求と一致していない。
- 対応内容: 判定を望ましい等式そのままへ書き換えた。
  - pin > 0: 一覧ファイルが regular file として実在し、**D34 が存在し**、
    D34 が一覧パスを対象に含む
  - pin = 0: 一覧のパスがどんな形でも残っておらず、**D34 自体が存在しない**
  D34 の同定は**番号**で行う。番号は `LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID` に
  他の pin と同じ場所へ置き、gate は解析済みの登録一覧から `id === 34` を探す。
- 併せて指摘された「D34 削除後の `AdoptionDebtInventory.php` の扱いが曖昧」について:
  **同クラスは正典の指紋台帳のキーではない** (実測で確認済み。母集合 281 件の外)。
  したがって D34 を消しても**未登録の逸脱は 1 件も残らない**し、突合 gate は同クラスに
  沈黙する。曖昧なのは記述だったので、D34 の本文と一覧クラスの docblock に
  「一覧クラスは母集合の外であり、D34 を消しても登録先を探す必要は無い」ことを明記した。

## [Warning] `fingerprintDebtInventoryExists()` が「残置」と「regular file」を混同している

- 判断: **対応する**
- 根拠: 掃除の判定では symlink も残置である。現状は pin = 0 のときに
  `adoption-debt.tsv` を symlink にすると `false` を返し「一覧なし」と判定して緑になる。
- 対応内容: 指摘どおり 2 つに分けた。
  - `inventoryPathExists` = `file_exists($path) || is_link($path)`
    (壊れた symlink も残置として数えるため `is_link` を or で足す)
  - `inventoryIsRegularFile` = `is_file($path) && ! is_link($path)`
  引退前は後者を要求し、引退後は前者が false であることを要求する。

## [Warning] 指紋台帳の symlink 拒否に負例が無い

- 判断: **対応する**
- 根拠: 走査条件を変えたのだから共通規約 (c) の対象である。
  「現物が regular file である」ことの確認は正例にすぎず、検出力の裏取りではない。
- 対応内容: 「パスを受け取り regular file か検査して読む」処理を
  `Tests\Support\TemplateDivergence\RegularFileReader` へ切り出し、
  一時 symlink / ディレクトリ / 不在 の負例と通常ファイルの正例を足した。
  gate の `fingerprintLedgerRaw()` と `AdoptionDebtInventory::read()` の
  どちらも同じ読み取り口を通るようにしたので、判定は 1 か所になった。

## [Warning] seeding ガードの blocker「債務一覧だけが追跡済み」に負例が無い

- 判断: **対応する**
- 根拠: 3 つの blocker のうち 1 つが裏取りされていないのは (c) の不足である。
  指摘どおり「ヘッダだけの旧一覧が残っている」状態は引退遷移の近くで現実に起こり得る。
- 対応内容: 負例を dataset の独立ケースへ足した
  (`previousLedger === null` / 指紋台帳は未追跡 / `adoption-debt.tsv` だけ追跡済み /
  既存債務は空)。あわせて既存の 2 ケース (指紋台帳が追跡済み / 既存債務が非空) も
  独立した dataset 名で並べ、**3 blocker が 1 件ずつ裏取りされる**形にした。

## [Warning] CLI の role 分岐そのものに負例が無い

- 判断: **対応する**
- 根拠: `FingerprintGenerationContext` の形 5 は別の分岐であり、CLI が読んだ既存台帳を
  検査する分岐の検出力は裏取りできていない。コメントで保証外にするのは、
  **新設・変更した判定分岐**に対する (c) の代わりにはならない。
- 対応内容: 指摘の案をそのまま採った。判定を
  `FingerprintGenerationService::assertAppLedgerRole(FingerprintLedger $ledger): void`
  へ切り出し、CLI とテストが同じ処理を呼ぶ。`Template` なら `GenerationRefused`、
  `App` なら正常終了の両方向を固定した。
  実プロセスの写像 (`GenerationRefused` → exit 3) は sha256 経路 1 本で確認済みなので、
  指摘のとおり role ごとのプロセステストは足していない。

## [Suggestion] `retirementViolations()` の `@throws` が docblock に無い

- 判断: **対応する**
- 根拠: fail-closed の入力条件なので契約として書くべきである。
- 対応内容: `@throws RuntimeException` を足し、負の pin を落とすことを本文にも書いた。

---

# Round 3: 修正内容

Round 2 の指摘 (Critical 1 / Warning 4 / Suggestion 1) は**すべて対応した**
(反論・見送りは 1 件も無い)。上の対応マトリクスが判断の記録である。

## 主要な変更

1. **`retirementViolations()` を望ましい等式そのままへ書き換えた** (Critical)。
   引数は 5 つになった:
   `pinnedCount` / `inventoryPathExists` / `inventoryIsRegularFile` /
   `isRegisteredAsTargetPath` / `divergenceEntryExists`。
   登録の同定は**番号** (`LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID = 34`) で行い、
   gate は解析済みの登録一覧から `id === 34` を探す。
   dataset に **「0 件・一覧は消したが登録が残っている → 違反」** を独立ケースとして足した
   (これが Round 2 で指摘された、形式検査も通り抜ける中途半端な掃除の形である)。

2. **`RegularFileReader` を新設した** (Warning)。「パスを受け取り regular file か検査して読む」
   処理を 1 か所へ集め、gate の指紋台帳読み取りと `AdoptionDebtInventory::read()` の
   どちらも同じ口を通る。負例は symlink / 壊れた symlink / ディレクトリ / 不在 の 4 形で、
   さらに**実物の指紋台帳への symlink を一時ディレクトリに作り、
   「素の `file_get_contents()` なら通ってしまう入力が読み取り口では拒否される」**ことを
   固定した (現物を差し替えずに検出力を裏取りする形)。

3. **「残置」と「regular file」を分けた** (Warning)。
   `fingerprintDebtInventoryPathExists()` = `file_exists() || is_link()` /
   `fingerprintDebtInventoryIsRegularFile()` = `is_file() && ! is_link()`。
   pin 0 で symlink を置く形も dataset に入れた。

4. **seeding ガードの 3 blocker を 1 件ずつ裏取りした** (Warning)。
   足りていなかった「債務一覧だけが追跡済み (指紋台帳は未追跡)」を独立ケースとして追加した。

5. **role 判定を `FingerprintGenerationService::assertAppLedgerRole()` へ切り出した** (Warning)。
   CLI はこれを呼ぶだけになり、`Template` で `GenerationRefused` / `App` で正常終了の
   両方向を単体テストが固定する。指摘のとおり role ごとのプロセステストは足していない
   (`GenerationRefused` → exit 3 の写像は sha256 経路 1 本で確認済み)。

6. **`@throws RuntimeException` を足した** (Suggestion)。

7. **D34 の記述を整理した**。「一覧クラスは母集合の外なので、本登録を消すときに
   登録先を探す必要は無い」ことを D34 の保証しないもの と 一覧クラスの docblock に書いた
   (Round 2 で曖昧と指摘された点。**これは記述の問題であり事実は変わっていない** —
   `AdoptionDebtInventory.php` は正典の指紋台帳の 947 キーに含まれないことを実測で確認済み)。

## 検証結果 (修正後・10 本すべて green)

```
composer test              : 6320 tests, 6318 passed, 0 failed, 2 skipped, 5 risky (30206 assertions)
                             ※ Round 1 時点 6296 → Round 2 時点 6308 → 今回 6320
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

生成器の再実行で pin は **281 / 174 / 33** のまま、生成物 2 本は **byte 不変**、
債務追加 0 件である。

## 差分の読み方 (注意)

以下は **Round 1 で提示した index からの累積差分**であり、Round 2 で見せた分を含む
(Round 2 の状態を git の tree として保存していないため、delta だけを切り出せない)。
今回新しいのは上の 1〜7 で、対象ファイルは 9 本である。

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 9751cd3a..a0b69a92 100644
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
@@ -2006,7 +2009,7 @@ ## D34 採用時点で説明の無い食い違いを、採用時ハッシュ付
 | 対象パス | `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` |
 | 業務要件起因の説明 | テンプレートの現物が CI に無いため、採用時点で食い違っていたファイル (174 件) が意図的逸脱なのか追従遅れなのかを機械では区別できない。区別が付くまで採用時の姿を凍結して扱う層を持つ |
 | 揃え続ける不変条件と保証機構 | 債務パスは採用時のアプリ側ハッシュのまま留まること。変えたら `mutatedDebtPaths`、テンプレート一致へ戻ったら `resolvedDebtPaths` が落とす (`TemplateDivergenceFingerprintTest` の F10 / F11) |
-| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
+| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す。突合 gate の F12 が両方向で強制する) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
 | 決めた日 | 2026-08-20 |
 | 決めた人 | 開発者 |
 | 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
@@ -2049,6 +2052,10 @@ ### 保証しないもの
 - 保証しないものの正本は `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` と
   `tests/Architecture/TemplateDivergenceFingerprintTest.php` の docblock である
   (本書には写さない)
+- **本登録を消すときに、一覧クラス (`AdoptionDebtInventory.php`) の登録先を探す必要は無い**。
+  同クラスは**正典の指紋台帳のキーではない** (母集合の外) ため、突合 gate は同クラスに
+  沈黙し、未登録の逸脱として残ることはない。対象パスに挙げているのは
+  「本アプリ固有の追加である」ことを記録するためである
 
 ### 関連
 
diff --git a/scripts/update-template-fingerprints.php b/scripts/update-template-fingerprints.php
index 684fd282..2579e458 100644
--- a/scripts/update-template-fingerprints.php
+++ b/scripts/update-template-fingerprints.php
@@ -40,7 +40,6 @@
 use Tests\Support\TemplateDivergence\FingerprintLedger;
 use Tests\Support\TemplateDivergence\GenerationRefused;
 use Tests\Support\TemplateDivergence\LedgerPins;
-use Tests\Support\TemplateDivergence\LedgerRole;
 use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
 
 $root = dirname(__DIR__);
@@ -105,26 +104,10 @@
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
@@ -165,6 +148,18 @@
 
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
+        // 判定本体は service 側に置く (CLI とテストが同じ処理を呼ぶ = 両方向を裏取りできる)
+        FingerprintGenerationService::assertAppLedgerRole($previousLedger);
+    }
+
     $context = FingerprintGenerationContext::forRoot(
         root: $root,
         expectedTemplateLedgerSha256: LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256,
diff --git a/tests/Architecture/TemplateDivergenceFingerprintTest.php b/tests/Architecture/TemplateDivergenceFingerprintTest.php
index 2ae3f05f..2063339e 100644
--- a/tests/Architecture/TemplateDivergenceFingerprintTest.php
+++ b/tests/Architecture/TemplateDivergenceFingerprintTest.php
@@ -12,6 +12,7 @@
 use Tests\Support\TemplateDivergence\ParsedLedger;
 use Tests\Support\TemplateDivergence\PathObservation;
 use Tests\Support\TemplateDivergence\ReconciliationResult;
+use Tests\Support\TemplateDivergence\RegularFileReader;
 use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;
 
 /*
@@ -84,15 +85,15 @@ function fingerprintRequiredMembers(): array
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
@@ -101,17 +102,68 @@ function fingerprintLedger(): FingerprintLedger
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
+/**
+ * 一覧のパスが**何らかの形で**残っているか。
+ *
+ * 掃除の判定では **symlink も残置**である (壊れた symlink は `file_exists()` が false を
+ * 返すので `is_link()` を or で足す)。「残置か」と「regular file か」は別の問いなので
+ * 関数を分ける (混ぜると pin 0 のときに symlink を置くだけで緑になる)。
+ */
+function fingerprintDebtInventoryPathExists(): bool
+{
+    $path = base_path(AdoptionDebtInventory::INVENTORY_PATH);
+
+    return file_exists($path) || is_link($path);
+}
+
+/** 一覧が regular file か (symlink は受理しない)。 */
+function fingerprintDebtInventoryIsRegularFile(): bool
+{
+    $path = base_path(AdoptionDebtInventory::INVENTORY_PATH);
+
+    return is_file($path) && ! is_link($path);
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
@@ -254,8 +306,15 @@ function fingerprintReconciliation(): ReconciliationResult
 
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
+        expect(fingerprintDebtInventoryIsRegularFile())->toBeTrue();
+    }
 
     // 負のコントロール: 読めない入力が黙って空へ潰れず例外になること
     expect(fn (): array => AdoptionDebtInventory::read(base_path('storage/framework/t236-absent')))
@@ -357,20 +416,21 @@ function fingerprintReconciliation(): ReconciliationResult
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
+        pinnedCount: LedgerPins::ADOPTION_DEBT_COUNT,
+        inventoryPathExists: fingerprintDebtInventoryPathExists(),
+        inventoryIsRegularFile: fingerprintDebtInventoryIsRegularFile(),
+        isRegisteredAsTargetPath: in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true),
+        // **登録の存在は番号で同定する** (対象パスだけを見ると中途半端な掃除が緑になる)
+        divergenceEntryExists: fingerprintDebtDivergenceEntryExists(),
     );
+
+    expect($violations)->toBe([], '採用時債務の掃除漏れ:'.PHP_EOL.implode(PHP_EOL, $violations));
 });
 
 test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
@@ -389,6 +449,13 @@ function fingerprintReconciliation(): ReconciliationResult
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
index 90db7428..3c3051ae 100644
--- a/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
+++ b/tests/Support/TemplateDivergence/AdoptionDebtInventory.php
@@ -50,14 +50,10 @@ private function __construct() {}
      */
     public static function read(string $root): array
     {
-        $path = rtrim($root, '/').'/'.self::INVENTORY_PATH;
-        $contents = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
-
-        if ($contents === false) {
-            throw new RuntimeException("採用時債務一覧を読めない (実行不能として落とす): {$path}");
-        }
-
-        return self::parse($contents);
+        return self::parse(RegularFileReader::read(
+            rtrim($root, '/').'/'.self::INVENTORY_PATH,
+            '採用時債務一覧',
+        ));
     }
 
     /**
@@ -128,6 +124,80 @@ public static function parse(string $contents): array
         return ['templateLedgerCommit' => $matches[1], 'entries' => $entries];
     }
 
+    /**
+     * 「債務が 0 件になったときの掃除」が済んでいるかを判定する (純関数)。
+     *
+     * 一覧が 0 件になったら**一覧ファイルと登録 (D34) を同じ変更で消す**のが
+     * D34 の再判定の条件である。件数の pin を状態の軸にして、次の等式を両方向で落とす:
+     *  - pin が 1 件以上: 一覧ファイルが **regular file として**実在し、
+     *    **登録そのものが存在し**、その登録が一覧パスを対象に含む
+     *  - pin が 0 件: 一覧のパスが**どんな形でも**残っておらず (symlink も残置である)、
+     *    **登録そのものが存在しない**
+     *
+     * ★**0 件を無条件で合格にしない**のが要点である。ヘッダだけの一覧と登録が残った状態を
+     *   緑にすると、本機構が名前どおり「掃除漏れ」を検出できないことになる。
+     * ★**登録の同定は対象パスではなく登録の存在で行う**。対象パスだけを見ると
+     *   「一覧ファイルを消し、対象パス欄から一覧パスだけを削り、登録は残す」という
+     *   中途半端な掃除が緑になる (登録はもう 1 本の対象パスを持つので形式検査も通る)。
+     * ★**一覧クラス自身 (`AdoptionDebtInventory.php`) は母集合の外**である
+     *   (正典の指紋台帳のキーではない)。したがって D34 を消しても
+     *   このクラスのための登録先を探す必要は無く、突合 gate は同クラスに沈黙する。
+     *
+     * @param  bool  $inventoryPathExists  一覧のパスが**何らかの形で**残っているか
+     *                                     (`file_exists() || is_link()`。壊れた symlink も残置として数える)
+     * @param  bool  $inventoryIsRegularFile  一覧が regular file か (`is_file() && ! is_link()`)
+     * @param  bool  $isRegisteredAsTargetPath  どこかの登録が一覧パスを対象に含んでいるか
+     * @param  bool  $divergenceEntryExists  一覧を説明する登録 (D34) そのものが存在するか
+     * @return list<string> 違反の説明 (空 = 合格)
+     *
+     * @throws RuntimeException 件数 pin が負のとき (fail-closed。判定できない入力を通さない)
+     */
+    public static function retirementViolations(
+        int $pinnedCount,
+        bool $inventoryPathExists,
+        bool $inventoryIsRegularFile,
+        bool $isRegisteredAsTargetPath,
+        bool $divergenceEntryExists,
+    ): array {
+        if ($pinnedCount < 0) {
+            throw new RuntimeException("採用時債務の件数 pin が負である: {$pinnedCount}");
+        }
+
+        $violations = [];
+
+        if ($pinnedCount > 0) {
+            if (! $inventoryPathExists) {
+                $violations[] = '債務が '.$pinnedCount.' 件あるのに一覧ファイル ('.self::INVENTORY_PATH.') が無い';
+            } elseif (! $inventoryIsRegularFile) {
+                $violations[] = '一覧ファイル ('.self::INVENTORY_PATH.') が regular file でない '
+                    .'(symlink は受理しない)';
+            }
+            if (! $divergenceEntryExists) {
+                $violations[] = '債務が残っている間は一覧を説明する登録を登録簿に置いておくこと';
+            }
+            if (! $isRegisteredAsTargetPath) {
+                $violations[] = '債務が残っている間は '.self::INVENTORY_PATH.' を登録の対象パスに含めておくこと';
+            }
+
+            return $violations;
+        }
+
+        if ($inventoryPathExists) {
+            $violations[] = '債務が 0 件になったのに一覧のパス ('.self::INVENTORY_PATH.') が残っている '
+                .'(symlink も残置である。同じ変更で削除すること)';
+        }
+        if ($divergenceEntryExists) {
+            $violations[] = '債務が 0 件になったのに一覧を説明する登録が登録簿に残っている '
+                .'(登録ごと削除し、LedgerPins::DIVERGENCE_ENTRY_COUNT を 1 減らすこと)';
+        }
+        if ($isRegisteredAsTargetPath) {
+            $violations[] = '債務が 0 件になったのに '.self::INVENTORY_PATH.' がまだ'
+                .'どこかの登録の対象パスに入っている';
+        }
+
+        return $violations;
+    }
+
     /**
      * 検証済みの内容から一覧の本文を組み立てる (生成器が使う。読み書きの正準形を 1 か所にする)。
      *
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationService.php b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
index b80f34a5..e3ab5347 100644
--- a/tests/Support/TemplateDivergence/FingerprintGenerationService.php
+++ b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
@@ -31,6 +31,30 @@ final class FingerprintGenerationService
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
@@ -98,6 +122,32 @@ public static function generate(
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
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 8b1a5cae..ce5512c2 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -33,6 +33,15 @@ private function __construct() {}
      */
     public const int ADOPTION_DEBT_COUNT = 174;
 
+    /**
+     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
+     *
+     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
+     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
+     *   同定に使うので番号を pin する。債務が 0 件になったらこの登録ごと消す。
+     */
+    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;
+
     /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
     public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';
 
diff --git a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
index 98893d20..561b45c2 100644
--- a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
+++ b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
@@ -9,6 +9,7 @@
 use Tests\Support\TemplateDivergence\LedgerPins;
 use Tests\Support\TemplateDivergence\LedgerRole;
 use Tests\Support\TemplateDivergence\PathObservation;
+use Tests\Support\TemplateDivergence\RegularFileReader;
 use Tests\Support\TemplateDivergence\RepoRelativePath;
 
 /*
@@ -25,7 +26,8 @@
  *
  * ★件数の正本は各 dataset の名前である。詳細設計の「N 形」と一致していること:
  *   `FingerprintLedger::fromJson()` = 11 形 / `RepoRelativePath::isValid()` = 8 形 /
- *   `PathObservation` = 7 形 / `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
+ *   `PathObservation` = 組み合わせ 7 形 (値の書式は別軸なので数に入れない) /
+ *   `AdoptionDebtInventory` = 11 形 (読み取り失敗 1 + 内容 10) /
  *   `FingerprintReconciler` = 8 種別。
  *
  * 生成器側 (`AppFingerprintBuilder` / `AtomicLedgerWriter` / `AtomicTextWriter` /
@@ -124,17 +126,6 @@ function adoptionDebtText(string $commit, string ...$lines): string
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
@@ -215,7 +206,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
         ->and($failed->state)->toBeNull();
 });
 
-test('負例: PathObservation が矛盾した組み合わせを例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
+test('負例: PathObservation が矛盾した組み合わせ 7 形を例外にする', function (?ComparisonState $state, ?string $hash, ?string $failure): void {
     expect(fn (): PathObservation => new PathObservation($state, $hash, $failure))
         ->toThrow(InvalidArgumentException::class);
 })->with([
@@ -228,7 +219,7 @@ function adoptionDebtText(string $commit, string ...$lines): string
     '7: 検査不能の理由が空文字' => [null, null, ''],
 ]);
 
-test('負例: PathObservation がハッシュの書式違反を例外にする', function (): void {
+test('負例: PathObservation がハッシュの書式違反を例外にする (組み合わせとは別の軸)', function (): void {
     expect(fn (): PathObservation => new PathObservation(ComparisonState::Matched, 'DEADBEEF', null))
         ->toThrow(InvalidArgumentException::class);
 });
@@ -525,8 +516,130 @@ function reconcilerHashesFor(string ...$paths): array
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
+    bool $pathExists,
+    bool $isRegularFile,
+    bool $isRegisteredAsTargetPath,
+    bool $entryExists,
+    bool $expectedClean,
+): void {
+    $violations = AdoptionDebtInventory::retirementViolations(
+        pinnedCount: $pinnedCount,
+        inventoryPathExists: $pathExists,
+        inventoryIsRegularFile: $isRegularFile,
+        isRegisteredAsTargetPath: $isRegisteredAsTargetPath,
+        divergenceEntryExists: $entryExists,
+    );
+
+    expect($violations === [])->toBe($expectedClean, '違反: '.implode(' / ', $violations));
+})->with([
+    // pin が 1 件以上: 一覧が regular file として実在し、登録が存在し、対象パスに含む
+    '1 件以上・すべて揃っている → 合格' => [176, true, true, true, true, true],
+    '1 件以上・一覧のパスが無い → 違反' => [176, false, false, true, true, false],
+    '1 件以上・一覧が symlink (regular file でない) → 違反' => [176, true, false, true, true, false],
+    '1 件以上・登録そのものが無い → 違反' => [176, true, true, false, false, false],
+    '1 件以上・登録はあるが対象パスに含んでいない → 違反' => [176, true, true, false, true, false],
+    // pin が 0 件: 一覧のパスも登録も残っていてはならない
+    '0 件・一覧なし・登録なし → 合格 (掃除済み)' => [0, false, false, false, false, true],
+    '0 件・一覧ファイルが残っている → 違反' => [0, true, true, false, false, false],
+    '0 件・一覧が symlink として残っている → 違反' => [0, true, false, false, false, false],
+    '0 件・登録ごと残っている → 違反' => [0, false, false, true, true, false],
+    // ★Codex Round 2 の Critical: 一覧を消し対象パスだけ削って登録を残す「中途半端な掃除」
+    '0 件・一覧は消したが登録が残っている → 違反' => [0, false, false, false, true, false],
+]);
+
+test('負例: 債務の件数 pin が負なら例外にする', function (): void {
+    expect(fn (): array => AdoptionDebtInventory::retirementViolations(
+        pinnedCount: -1,
+        inventoryPathExists: true,
+        inventoryIsRegularFile: true,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: true,
+    ))->toThrow(RuntimeException::class);
+});
+
+test('債務の引退の掃除の違反は直し方まで告げる', function (): void {
+    $violations = AdoptionDebtInventory::retirementViolations(
+        pinnedCount: 0,
+        inventoryPathExists: true,
+        inventoryIsRegularFile: true,
+        isRegisteredAsTargetPath: true,
+        divergenceEntryExists: true,
+    );
+
+    expect($violations)->toHaveCount(3)
+        ->and(implode("\n", $violations))->toContain(AdoptionDebtInventory::INVENTORY_PATH)
+        ->and(implode("\n", $violations))->toContain('DIVERGENCE_ENTRY_COUNT');
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
index bf8bad0e..3b15e492 100644
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
@@ -729,13 +724,85 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
@@ -926,3 +993,37 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
