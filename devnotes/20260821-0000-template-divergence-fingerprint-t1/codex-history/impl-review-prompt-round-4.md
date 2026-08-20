# 対応マトリクス: impl-review Round 3

## [Critical] D34 のライフサイクルが設計上矛盾している

- 判断: **対応する (提示された 3 案のうち 3 番目を採る)**
- 根拠: 指摘が正しい。D34 は**一時的なもの** (`adoption-debt.tsv`) と
  **恒久的な機構** (`AdoptionDebtInventory.php`) の 2 つを対象にしている。
  債務が 0 件になってもクラスと判定機構は残る (F12 が `retirementViolations()` を
  呼び続けるため) のに、「機構は残すが gate が見ないので登録だけ消す」と書いていた。
  **機械検査が沈黙することと、登録簿上の説明が不要になることは別**である。
  この指摘で、Round 2 の Critical に対する私の直し方が**片側に寄りすぎていた**ことが分かった —
  あのとき塞ぐべき穴は「F12 が何も見ていない」ことであり、
  正しい終端状態を「D34 ごと削除」と決めつけたのは私の誤りである。
- 対応内容: **案 3 (D34 を残し、引退時は一覧パスだけを対象から外す)** を採った。
  理由は、3 案のうち唯一**先回りの作業を増やさず、事実をそのまま書ける**案だからである
  (案 1 は「将来 0 件になったら機構ごと消す」という予定の管理を登録簿へ持ち込む。
  案 2 は今すぐ登録を 2 本へ割る作業が必要で、件数 pin も動く)。
  等式を次へ確定した:
  - **pin の値に関わらず**: 一覧クラスを説明する登録 (D34) が存在する
    (クラスは本アプリ固有の追加であり、母集合の外でも登録簿の説明対象である)
  - pin > 0: 一覧ファイルが regular file として実在し、その登録が一覧パスを対象に含む
  - pin = 0: 一覧のパスがどんな形でも残っておらず、どの登録の対象パスにも入っていない
  D34 の再判定の条件を「一覧ファイルを削除し、対象パスから一覧パスを外す
  (**登録自体は一覧クラスの説明として残る**)」へ書き換えた。
  機構ごと撤去する判断をするなら本検査も一緒に消すことになる旨も docblock へ書いた
  (手編集の限界と同じ扱い)。

## [Warning] 引退後の安定状態が生成器に反映されていない

- 判断: **対応する**
- 根拠: 指摘のとおり、`$built['debt'] === []` でも `AtomicTextWriter` が
  ヘッダだけの一覧を書くので、引退後に台帳を更新すると一覧が再作成されて F12 が赤くなる。
  引退が**安定状態にならない**のは、機構として不完全である。
- 対応内容: 債務が 0 件のときは一覧を書かず、**既存の一覧ファイルがあれば取り除く**ようにした
  (生成物の正しい状態は「0 件の一覧」ではなく「一覧が無い」である)。
  報告に `retired` / `debtInventoryRemoved` を足し、CLI が
  「pin を 0 にして D34 の対象パスから一覧パスを外せ」と案内する。
  指摘された 4 段の経路 (引退済み → 新しい正典台帳を取り込む → 新規債務なし →
  一覧は再作成されず引退状態を維持する) をテストで固定した。
  **新しい債務が発生した場合は機構が再開する** (一覧が再作成され、pin を戻す必要が出る)。
  これは「債務は正典台帳の載せ替えでしか増えない」という既存のガードの下でのみ起こり、
  そのときは D34 の対象パスへ一覧パスを戻すことになる。この向きも docblock に書いた。

## [Warning] `retirementViolations()` が矛盾した bool の組み合わせを受理する

- 判断: **対応する (enum 化する)**
- 根拠: `inventoryPathExists: false` かつ `inventoryIsRegularFile: true` は実在状態として
  不可能なのに pin 0 で合格する。共通規約 (b) に反する。
- 対応内容: 指摘の 2 案のうち**強い方 (enum)** を採った。
  `InventoryPresence` (3 値: `Absent` / `RegularFile` / `NonRegularFile`) を新設し、
  2 つの bool を 1 つの enum へ置き換えた。**矛盾した組み合わせは型として作れない**。
  ファイルシステムから enum への写像は gate の 1 か所
  (`fingerprintDebtInventoryPresence()`) に閉じ、その写像自身も負例で裏取りした
  (通常ファイル / symlink / 壊れた symlink / 不在 / ディレクトリ)。

## [Warning] 等式の各項を独立して発火させる負例が 2 つ不足

- 判断: **対応する**
- 根拠: 「登録そのものが無い」ケースで 2 つの bool をどちらも false にしていたため、
  `divergenceEntryExists` 側の判定が壊れても対象パス側の違反だけでテストが通る。
  集約結果の非空だけを見ていて、各条件の単独の検出力を固定していなかった。
- 対応内容: 指摘の 2 ケースを dataset へ足した
  (pin > 0 / pin = 0 のそれぞれで `isRegisteredAsTargetPath=true` かつ
  `divergenceEntryExists=false`)。あわせて**違反メッセージの本数と内容まで**検査する
  テストを足し、どの条件が発火したのかを 1 件ずつ見分けられるようにした。

## [Warning] CLI の既存台帳読み取りが `RegularFileReader` を通っていない

- 判断: **対応する**
- 根拠: D33 で「指紋台帳自身は regular file 必須」と宣言した以上、生成器も同じ口を通るべきである。
  現状は CLI だけ valid symlink を追跡する。
- 対応内容: CLI の既存台帳読み取りを `RegularFileReader::read()` へ寄せた。
  判定の正本は 1 つ (gate / 一覧 / CLI の 3 経路が同じ口を通る) になった。

## [Warning] `RegularFileReader.php` 本体が差分に含まれていない

- 判断: **対応する (提示漏れであり実装漏れではない)**
- 根拠: 新規ファイルを index へ入れる前に `git diff` を撮ったため、
  未追跡ファイルが差分に現れなかった (私の提示の誤りである)。
- 対応内容: Round 4 の差分は `git add -N` で新規ファイルを index へ登録してから撮り、
  `RegularFileReader.php` と `InventoryPresence.php` の**本体を含める**。
  指摘された 5 点 (`declare(strict_types=1)` / `is_link()` と `is_file()` の判定順 /
  読み取り失敗時の処理 / 戻り値型と例外契約 / 保証範囲の docblock) はすべて実装済みなので、
  差分で確認してほしい。

---

# Round 4: 修正内容

Round 3 の指摘 (Critical 1 / Warning 5) は**すべて対応した**
(反論・見送りは 1 件も無い)。上の対応マトリクスが判断の記録である。

## 主要な変更

1. **D34 のライフサイクルを案 3 で確定した** (Critical)。等式は 3 本になった:
   - **pin の値に関わらず**: 一覧クラスを説明する登録が存在する
   - pin > 0: 一覧が regular file として実在し、その登録が一覧パスを対象に含む
   - pin = 0: 一覧のパスがどんな形でも残っておらず、どの登録の対象パスにも入っていない
   D34 の再判定の条件を「一覧ファイルを削除し、**対象パスから一覧パスの 1 行を外す**。
   登録そのものは一覧クラスの説明として残す」へ書き換えた。
   dataset に「0 件・登録ごと消してしまった → 違反」を足したので、
   **Round 2 で私が採った『D34 ごと削除』は今は違反として落ちる**。

2. **引退を安定状態にした** (Warning)。債務が 0 件のとき生成器は一覧を書かず、
   既存の一覧ファイルを取り除く。報告に `retired` / `debtInventoryRemoved` を足し、
   CLI が「pin を 0 にして対象パスから 1 行外せ」と案内する。
   指摘された 4 段の経路をテストで固定した (第 1 世代で一覧あり → 内容を戻して 0 件 →
   一覧が消える → 新しい正典台帳を取り込んでも**再作成されない**)。
   逆向き (引退後に台帳の載せ替えで新規債務が生じたら一覧が再作成される) も固定した。

3. **矛盾した bool の組み合わせを型で消した** (Warning)。
   `InventoryPresence` (`Absent` / `RegularFile` / `NonRegularFile`) を新設し、
   2 つの bool を 1 つの enum へ置き換えた。ファイルシステムからの写像は
   `InventoryPresence::fromPath()` の 1 か所で、その写像自身も
   通常ファイル / symlink / 壊れた symlink / ディレクトリ / 不在 の 5 形で裏取りした。

4. **各項が単独で発火することを固定した** (Warning)。
   指摘の 2 ケース (`isRegisteredAsTargetPath=true` かつ `divergenceEntryExists=false` を
   pin > 0 と pin = 0 の両方) を dataset へ足し、さらに
   **違反の本数を条件ごとに検査する**テストを足した (1 件 / 1 件 / 1 件 / 3 件)。

5. **CLI の既存台帳読み取りを `RegularFileReader` へ寄せた** (Warning)。
   これで gate / 一覧 / 生成器の 3 経路が同じ読み取り口を通る。

6. **`RegularFileReader.php` と `InventoryPresence.php` の本体を差分に含めた** (Warning)。
   Round 3 の提示漏れは `git diff` が未追跡ファイルを出さないためだった。
   今回は `git add -N` で index へ登録してから差分を撮っている。

## 検証結果 (修正後・10 本すべて green)

```
composer test              : 6331 tests, 6329 passed, 0 failed, 2 skipped, 5 risky (30240 assertions)
                             ※ 6296 (R1) → 6308 (R2) → 6320 (R3) → 6331 (R4)
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

## 差分の読み方 (注意)

Round 1 で提示した index からの**累積差分**である (Round 2 / Round 3 で見せた分を含む)。
今回新しいのは上の 1〜6 で、対象ファイルは 10 本 (うち新規 2 本) である。

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 9751cd3a..be26af1b 100644
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
+| 再判定の条件 | 一覧が 0 件になったとき (一覧ファイルを削除し、対象パスから一覧パスの 1 行を外す。**登録そのものは一覧クラスの説明として残す**。突合 gate の F12 が両方向で強制する) / テンプレート更新の一括取り込みを行うとき / 債務パスの分類が付いたとき |
 | 決めた日 | 2026-08-20 |
 | 決めた人 | 開発者 |
 | 根拠 | devnotes/20260821-0000-template-divergence-fingerprint-t1/ |
@@ -2049,6 +2052,19 @@ ### 保証しないもの
 - 保証しないものの正本は `tests/Support/TemplateDivergence/AdoptionDebtInventory.php` と
   `tests/Architecture/TemplateDivergenceFingerprintTest.php` の docblock である
   (本書には写さない)
+- **引退で消えるのは一覧ファイルと対象パスの 1 行だけで、本登録は残る**。
+  一覧が 0 件になっても判定機構 (`AdoptionDebtInventory.php`) は残り続ける
+  (突合 gate の F12 が `retirementViolations()` を呼び続けるため) ので、
+  「機構は残すが説明だけ消す」は登録簿の意味と一致しない。
+  なお同クラスは**正典の指紋台帳のキーではない** (母集合の外) ため、突合 gate は
+  同クラスの内容には沈黙する。対象パスに挙げているのは
+  「本アプリ固有の追加である」ことを記録するためである
+- **引退は安定状態である**。生成器は債務が 0 件のとき一覧を書かず、既存の一覧ファイルを
+  取り除く (「0 件の一覧」ではなく「一覧が無い」が正しい生成物の状態である)。
+  逆に台帳の載せ替えで新しい債務が生じたら一覧は再作成されるので、そのときは
+  件数 pin を戻し、対象パスへ一覧パスの 1 行を戻すことになる
+- **機構ごと撤去する判断をするなら、本登録・判定機構・F12 を一緒に消すことになる**
+  (そこは指紋台帳や gate 自身の手編集と同じ原理的限界であり、PR レビューの担当である)
 
 ### 関連
 
diff --git a/scripts/update-template-fingerprints.php b/scripts/update-template-fingerprints.php
index 684fd282..86b949e0 100644
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
@@ -230,4 +225,15 @@
     $report['templateLedgerCommit'],
 ));
 
+if ($report['retired']) {
+    fwrite(STDOUT,
+        "採用時債務が 0 件になった。同じ変更で次の 2 つを行うこと:\n"
+        ."  1. LedgerPins::ADOPTION_DEBT_COUNT を 0 にする\n"
+        .'  2. docs/template-divergence.md の対象パスから '
+            .AdoptionDebtInventory::INVENTORY_PATH." の 1 行を外す\n"
+        ."     (登録そのものは一覧クラスの説明として残す)\n"
+        .($report['debtInventoryRemoved'] ? "  ※ 一覧ファイルは生成器が取り除いた\n" : ''),
+    );
+}
+
 exit(0);
diff --git a/tests/Architecture/TemplateDivergenceFingerprintTest.php b/tests/Architecture/TemplateDivergenceFingerprintTest.php
index 2ae3f05f..29c37a77 100644
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
@@ -101,17 +103,58 @@ function fingerprintLedger(): FingerprintLedger
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
@@ -254,8 +297,15 @@ function fingerprintReconciliation(): ReconciliationResult
 
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
@@ -357,20 +407,20 @@ function fingerprintReconciliation(): ReconciliationResult
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
+        presence: fingerprintDebtInventoryPresence(),
+        isRegisteredAsTargetPath: in_array(AdoptionDebtInventory::INVENTORY_PATH, $registeredPaths, true),
+        // **登録の存在は番号で同定する** (対象パスだけを見ると中途半端な掃除が緑になる)
+        divergenceEntryExists: fingerprintDebtDivergenceEntryExists(),
     );
+
+    expect($violations)->toBe([], '採用時債務の掃除漏れ:'.PHP_EOL.implode(PHP_EOL, $violations));
 });
 
 test('F13: 逸脱の登録簿の解析が成功していること (解析違反から登録を組み立てない)', function (): void {
@@ -389,6 +439,13 @@ function fingerprintReconciliation(): ReconciliationResult
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
index 90db7428..4ec64c3a 100644
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
+     * 等式は 3 本である:
+     *  - **pin の値に関わらず**: 一覧クラスを説明する登録 (D34) が存在する。
+     *    このクラスは本アプリ固有の追加なので、債務が 0 件になっても説明は要る
+     *  - pin が 1 件以上: 一覧ファイルが **regular file として**実在し、
+     *    その登録が一覧パスを対象に含む
+     *  - pin が 0 件: 一覧のパスが**どんな形でも**残っておらず (symlink も残置である)、
+     *    どの登録の対象パスにも入っていない
+     *
+     * ★**0 件を無条件で合格にしない**のが要点である。ヘッダだけの一覧や、
+     *   対象パスに残った一覧パスを緑にすると、本機構が名前どおり「掃除漏れ」を検出できない。
+     * ★**引退で消えるのは一覧ファイルと対象パスの 1 行だけで、登録は残る**。
+     *   判定機構 (本クラス) は 0 件になっても残り続ける (F12 が本メソッドを呼び続ける) ので、
+     *   「機構は残すが登録だけ消す」は登録簿の意味と一致しない。
+     *   機構ごと撤去する判断をするなら、本メソッドと F12 も一緒に消すことになる
+     *   (そこは手編集の限界と同じく PR レビューの担当である)。
+     * ★**引退は安定状態である**。生成器は債務が 0 件のとき一覧を書かず、
+     *   既存の一覧ファイルがあれば取り除く (「0 件の一覧」ではなく「一覧が無い」が
+     *   正しい生成物の状態である)。逆に新しい債務が生じたら一覧は再作成されるので、
+     *   そのときは pin を戻し、対象パスへ一覧パスを戻すことになる。
+     *
+     * @param  InventoryPresence  $presence  一覧のパスの在り方 (矛盾した組み合わせを型で消してある)
+     * @param  bool  $isRegisteredAsTargetPath  どこかの登録が一覧パスを対象に含んでいるか
+     * @param  bool  $divergenceEntryExists  一覧クラスを説明する登録 (D34) そのものが存在するか
+     * @return list<string> 違反の説明 (空 = 合格)
+     *
+     * @throws RuntimeException 件数 pin が負のとき (fail-closed。判定できない入力を通さない)
+     */
+    public static function retirementViolations(
+        int $pinnedCount,
+        InventoryPresence $presence,
+        bool $isRegisteredAsTargetPath,
+        bool $divergenceEntryExists,
+    ): array {
+        if ($pinnedCount < 0) {
+            throw new RuntimeException("採用時債務の件数 pin が負である: {$pinnedCount}");
+        }
+
+        $violations = [];
+
+        // pin の値に関わらず要る (一覧クラスは本アプリ固有の追加である)
+        if (! $divergenceEntryExists) {
+            $violations[] = '採用時債務の機構を説明する登録が登録簿に無い '
+                .'(一覧が 0 件でも '.self::class.' は残るので説明は要る)';
+        }
+
+        if ($pinnedCount > 0) {
+            if ($presence === InventoryPresence::Absent) {
+                $violations[] = '債務が '.$pinnedCount.' 件あるのに一覧ファイル ('.self::INVENTORY_PATH.') が無い';
+            } elseif ($presence === InventoryPresence::NonRegularFile) {
+                $violations[] = '一覧ファイル ('.self::INVENTORY_PATH.') が regular file でない '
+                    .'(symlink は受理しない)';
+            }
+            if (! $isRegisteredAsTargetPath) {
+                $violations[] = '債務が残っている間は '.self::INVENTORY_PATH.' を登録の対象パスに含めておくこと';
+            }
+
+            return $violations;
+        }
+
+        if ($presence->exists()) {
+            $violations[] = '債務が 0 件になったのに一覧のパス ('.self::INVENTORY_PATH.') が残っている '
+                .'(symlink も残置である。同じ変更で削除すること)';
+        }
+        if ($isRegisteredAsTargetPath) {
+            $violations[] = '債務が 0 件になったのに '.self::INVENTORY_PATH.' が登録の対象パスに残っている '
+                .'(対象パス欄から 1 行外すこと。登録そのものは一覧クラスの説明として残す)';
+        }
+
+        return $violations;
+    }
+
     /**
      * 検証済みの内容から一覧の本文を組み立てる (生成器が使う。読み書きの正準形を 1 か所にする)。
      *
diff --git a/tests/Support/TemplateDivergence/FingerprintGenerationService.php b/tests/Support/TemplateDivergence/FingerprintGenerationService.php
index b80f34a5..5c2267c2 100644
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
@@ -53,6 +82,8 @@ private function __construct() {}
      *     addedDebt: list<string>,
      *     templateLedgerCommit: string,
      *     seeded: bool,
+     *     retired: bool,
+     *     debtInventoryRemoved: bool,
      * }
      *
      * @throws GenerationRefused ガードによる拒否 (終了コード 3)
@@ -98,6 +129,32 @@ public static function generate(
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
@@ -147,18 +204,32 @@ public static function generate(
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
+        $debtInventoryRemoved = false;
+        if ($built['debt'] === []) {
+            if ($reader($context->debtOutputPath) !== false && ! $remover($context->debtOutputPath)) {
+                throw new RuntimeException(
+                    '債務が 0 件になったが採用時債務一覧を取り除けない: '.$context->debtOutputPath,
+                );
+            }
+            $debtInventoryRemoved = true;
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
@@ -170,6 +241,8 @@ static function (string $contents): void {
             'addedDebt' => $built['addedDebt'],
             'templateLedgerCommit' => $templateLedger->generatedAtCommit,
             'seeded' => $built['seeded'],
+            'retired' => $built['debt'] === [],
+            'debtInventoryRemoved' => $debtInventoryRemoved,
         ];
     }
 }
diff --git a/tests/Support/TemplateDivergence/InventoryPresence.php b/tests/Support/TemplateDivergence/InventoryPresence.php
new file mode 100644
index 00000000..5569793d
--- /dev/null
+++ b/tests/Support/TemplateDivergence/InventoryPresence.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\TemplateDivergence;
+
+/**
+ * 採用時債務一覧のパスの在り方 (3 値)。
+ *
+ * ★**2 つの真偽値 (「残っているか」「regular file か」) にすると矛盾した組み合わせを
+ *   作れてしまう** — 「存在しないが regular file である」は実在状態として不可能なのに、
+ *   引数としては渡せてしまう。共通規約 (b) の「解決できない形を落とす」に反するので、
+ *   **型として矛盾を作れない形**にした。
+ *
+ * 掃除の判定では **symlink も残置**である (`NonRegularFile` に入る)。
+ * 壊れた symlink も残置なので `Absent` ではない。
+ */
+enum InventoryPresence
+{
+    /** パスがどんな形でも存在しない (掃除済みの状態)。 */
+    case Absent;
+
+    /** 通常ファイルとして存在する (債務が残っている間の正しい状態)。 */
+    case RegularFile;
+
+    /** 存在はするが通常ファイルでない (symlink / 壊れた symlink / ディレクトリ等)。 */
+    case NonRegularFile;
+
+    /**
+     * ファイルシステムの状態から写す (写像を 1 か所に閉じる)。
+     *
+     * `file_exists()` は壊れた symlink に false を返すので `is_link()` を or で足す。
+     */
+    public static function fromPath(string $path): self
+    {
+        if (is_link($path)) {
+            return self::NonRegularFile;
+        }
+        if (! file_exists($path)) {
+            return self::Absent;
+        }
+
+        return is_file($path) ? self::RegularFile : self::NonRegularFile;
+    }
+
+    /** パスが何らかの形で残っているか。 */
+    public function exists(): bool
+    {
+        return $this !== self::Absent;
+    }
+}
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
 
diff --git a/tests/Support/TemplateDivergence/RegularFileReader.php b/tests/Support/TemplateDivergence/RegularFileReader.php
new file mode 100644
index 00000000..0575ad46
--- /dev/null
+++ b/tests/Support/TemplateDivergence/RegularFileReader.php
@@ -0,0 +1,55 @@
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
+     *
+     * @throws RuntimeException symlink / 不在 / 通常ファイルでない / 読み取り失敗
+     */
+    public static function read(string $path, string $label): string
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
+        $contents = file_get_contents($path);
+        if ($contents === false) {
+            throw new RuntimeException("{$label} を読めない: {$path}");
+        }
+
+        return $contents;
+    }
+}
diff --git a/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php b/tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php
index 98893d20..7a1542a8 100644
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
@@ -525,8 +517,210 @@ function reconcilerHashesFor(string ...$paths): array
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
index bf8bad0e..6c1d802d 100644
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
@@ -668,7 +669,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
         ->and($debt['templateLedgerCommit'])->toBe($ledger->generatedAtCommit);
 });
 
-test('負例: service の拒否 4 経路では生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
+test('負例: service の拒否 4 経路は GenerationRefused で、生成物のバイト列が 1 ビットも変わらない', function (string $case): void {
     $root = generatorTempRoot();
 
     // まず正常な生成物を作る (以後これが 1 バイトも変わらないことを見る)
@@ -686,12 +687,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
 
@@ -712,7 +708,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
                 remover: $io['remover'],
             );
         },
-        // 3: 債務へ新規パスを追加しようとした
+        // 2: 債務へ新規パスを追加しようとした
         'debt' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A'), 'b.php' => hash('sha256', 'B')],
@@ -721,7 +717,7 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
             previousLedger: $previous,
             templateCommit: $previous->generatedAtCommit,
         ),
-        // 4: 同じ正典入力のまま母集合を縮小しようとした
+        // 3: 同じ正典入力のまま母集合を縮小しようとした
         'shrink' => fn (): mixed => generatorRun(
             root: $root,
             templateEntries: ['a.php' => hash('sha256', 'A')],
@@ -729,13 +725,179 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
@@ -926,3 +1088,37 @@ function atomicFailureDatasets(?string $validContents = null, ?string $invalidCo
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
