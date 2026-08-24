# Round 2: Round 1 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] D50 の業務要件説明が条件付き運用契約と一致していない (30 秒断定 + ワーカー閉塞)

- 判断: **対応する**
- 根拠: 指摘のとおり。実効値が 30 秒へ縮むのは
  `docs/architecture.md` §AI 解析ジョブの運用契約 が挙げる 3 前提の上でのみ成り立つ話であり、
  同じ登録の「保証しないもの」で「実効値は見ない」と書いている以上、断定は自己矛盾になる。
  「ワーカーが塞がって後続が詰まる」は**裏取りしていない**。30 秒への短縮の直接の帰結は
  早期打ち切りであり、リトライ反復によるキュー圧迫を主張するには経路と上限の実測が要る
  (AGENTS.md「検出力の主張の書き方」= 根拠を併記できない主張は書かない)。
- 対応内容: `docs/template-divergence.md` D50 の
  (a) 登録メタ表「業務要件起因の説明」を 3 前提つきの条件文にし、帰結を
  「provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる」へ差し替え、
  (b) 「なぜ正当な差分か」1 も同じ条件文へ直し、
  **ワーカー占有の長期化は主張しないことと、その理由**を明記した。

## [Warning] gate 冒頭コメントの「宣言を落とすと実効値は 30 秒へ縮む」も無条件の断定

- 判断: **対応する**
- 根拠: 同上。直後の「この gate は実効値を保証しない」と読み手に矛盾して見える。
- 対応内容: `tests/Architecture/PromptClientTimeoutInvariantTest.php` の冒頭コメントを
  3 前提つきへ書き換え、「本 gate が固定するのは宣言の側だけである」を 1 行足した。

## [Suggestion] `PromptYaml::parseOrFail()` が null かつ空の理由列を返すと公開 2 口が非対称になる

- 判断: **対応する** (指摘は「現時点のブロッカーではない」だが、塞ぐ側へ倒す)
- 根拠: 非対称の向きが **`violations()` だけ緑 = fail-open** であり、
  AGENTS.md 共通規約 (b)「未解決を解決済みと同じ値へ混ぜない」に正面から当たる。
  分岐 3 行で消せるうえ、同クラスの `requirePositive()` は既に同種の
  「到達しないが黙って 0 を返さない」guard を持っており、書き方も一貫する。
- 対応内容: `read()` で `$parsed === null && $parseErrors === []` を
  内部不整合の違反ラベルへ正規化し、docblock の段 2 に 2 行追記した。
  **負例は置かない** — 共有ヘルパ (採用時債務で凍結) の実装を変えない限り
  公開口からは到達できないためで、同じ理由で `requirePositive()` の
  `$timeout === null` guard にも負例が無い (到達不能分岐であることをコメントで明示)。

## [情報] `composer test` の risky 5 件について

- 判断: **既存由来**として扱う (本 PR の追加分は明示的な assertion を持つ)。
- 根拠: main の直前クローズ (T254、`docs/TODO-closed.md`) の検証記録が
  「7370 tests / 7368 passed / 0 failed / 2 skipped / **5 risky**」であり、
  本 PR 前から同数の risky が存在する。本 PR で増えていない。

## 修正差分 (Round 1 の実装からの差分のみ)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 642c99ea..b7ae9964 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 46 件
+登録エントリ: 47 件
 
 ## 記録の原則
 
@@ -2854,3 +2854,73 @@ ### 関連
 
 - 実装: `tests/Architecture/ArchBaselineTest.php`
 - 設計: `devnotes/20260823-0020-pest-arch-baseline-per-rule-adoption/`
+
+## D50 LLM 待ち予算の宣言検査を、単一読み取り器 + 検出器自己テスト形へ差し替える
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Architecture/PromptClientTimeoutInvariantTest.php` / `tests/Support/PromptWaitBudget.php` / `tests/Unit/Architecture/PromptWaitBudgetTest.php` |
+| 業務要件起因の説明 | 本アプリの AI 解析は 1 呼び出し 360 秒の待ち予算を前提に deadline (3C) と job timeout と retry_after と予約 TTL の連鎖を組んでおり、prompt YAML の宣言が落ちると (`docs/architecture.md` §AI 解析ジョブの運用契約 が挙げる 3 前提が成立する現行実装では) 実効値が 30 秒へ縮み、provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる。テンプレートの形は判定を gate ファイル内へインラインで持つため、同じ規則が時間 budget 側にも複製されて実際に緩い実装 (0 以下を通す) が生まれた。判定を 1 クラスへ切り出し、待ち予算を読む検査すべてがそれを参照する形にする |
+| 揃え続ける不変条件と保証機構 | 全 prompt YAML が `client_options.timeout` を正の整数で宣言することは `PromptClientTimeoutInvariantTest` が既定拒否で固定する (テンプレートと同じ不変条件)。分母の全数性は既存の走査ヘルパ `PromptYaml::paths()` の実装契約に依存し、新設 test が裏取りするのは現在の列挙結果に既知 5 本が含まれることまでである。判定の検出力は `tests/Unit/Architecture/PromptWaitBudgetTest.php` が負例 9 類型 + 正例 + 解決不能形 3 種 (分類つき) で裏取りする |
+| 再判定の条件 | テンプレート側が判定の 1 クラス化を取り込んだとき (家系の正典 v1 が既にこの形なので、追従で差分が消える可能性がある)。または `resources/prompts` の走査ヘルパ (`PromptYaml`) をテンプレートへ同期する判断をしたとき |
+| 決めた日 | 2026-08-24 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260824-1016-llm-prompt-wait-budget-v1/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-06-30 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 判定の置き場 | gate ファイル内のインライン判定 | `Tests\Support\PromptWaitBudget` 1 クラス (public 2 口・判定は private) |
+| 検出力の裏取り | なし | 負例 9 類型 + 正例 + 解決不能形 3 種 (`tests/Unit/Architecture/PromptWaitBudgetTest.php`) |
+| 分母の証明 | 非空のみ | 非空 + 既知 5 本の包含 (走査根の改名・移動と既知ファイルの削除で赤くなる。再帰性の退行は検出しない) |
+| 時間 budget 側との関係 | 無関係 (テンプレートに解析パイプラインが無い) | 同じ読み取り器を `AnalysisBudget::clientTimeoutSecondsFromYaml()` が参照する |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **待ち予算の宣言落ちは本アプリでだけ致命になる**。SOP → シナリオ生成の 3 段
+   (`sop-extract` / `work-decomposition` / `scenario-generation`) と OCR 経路
+   (`sop-extract-media`) は 1 呼び出し 360 秒を前提に時間 budget の連鎖を組んでいる。
+   宣言が 1 行落ちると (実効性の 3 前提が成立する現行実装では) 実効値は
+   `config/prism.php` の 30 秒へ縮み、**平常時は何も起きず、provider の応答が
+   30 秒を超えたときだけ**「解析が早期に打ち切られる」という形で遠くに症状が出る。
+   テンプレートには解析パイプラインが無いので、同じ検査でも守っている連鎖の長さが違う。
+   (**ワーカー占有の長期化は本登録では主張しない** — 30 秒への短縮の直接の帰結は
+   早期打ち切りであり、リトライ反復によるキュー圧迫を主張するにはその経路と上限の
+   裏取りが要る。裏取りしていないものは書かない。)
+2. **同じ規則の複数実装が実際に緩んでいた**。判定を gate 内へインラインで書く形だったため、
+   時間 budget 側 (`AnalysisBudget::clientTimeoutSecondsFromYaml()`) が
+   `Assert::integer()` 止まりの別実装になり **`timeout: 0` を通していた**。
+   穴が閉じていたのは「別の gate が偶然厳しかったから」であって、構造ではなかった。
+   判定を 1 クラスへ寄せると、どの検査から見ても同じ厳しさで落ちる。
+3. **検出力を負例で裏取りできる形になる**。読み取り器は引数でパスを受けるので見本
+   ファイルを食わせられる。gate 内のインライン判定のままでは
+   `resources/prompts` の実データを壊す以外に負例を作れず、同じ分母を見る他の 3 gate を
+   巻き添えにするため実質的に裏取りできない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「resources/prompts 配下の prompt YAML は全数が client_options.timeout を
+> 正の整数で宣言しており、未宣言・0 以下・整数でない値は検査で落ちる」
+
+### 保証しないもの
+
+- 宣言値が**実効値であること**は見ない (正本は読み取り器 `Tests\Support\PromptWaitBudget` の
+  docblock。実効性が依存する 3 前提と、崩れたときの手当ては
+  `docs/architecture.md` §AI 解析ジョブの運用契約 に書いてある)
+- **分母の全数性は走査ヘルパ `PromptYaml::paths()` の実装契約に依存する**。到達証明が
+  裏取りするのは「現在の列挙結果に既知 5 本が含まれること」であり、5 本はいずれも
+  走査根の直下にあるため**再帰性の退行では赤くならない** (走査ヘルパが探索根を
+  引数で受けないので見本ディレクトリを食わせられない)
+- parse 段の失敗分類は共有ヘルパ `PromptYaml::parseOrFail()` に従う
+  (構文エラーと vendor 内部エラーを区別しない)
+- 4 本目の読み取り実装の再流入は機械では止めない (字句走査では読み取り実装と
+  失敗メッセージ中の文字列を区別できないため。唯一の読み取り器であることは
+  読み取り器の docblock の宣言とレビューが担う)
+
+### 関連
+
+- 実装: `tests/Support/PromptWaitBudget.php`
+- 見本ファイル: `tests/Architecture/fixtures/prompt-wait-budget/` (12 本)
+- 設計: `devnotes/20260824-1016-llm-prompt-wait-budget-v1/`
+- 家系の正典: lctl feature `llm-prompt-wait-budget` canonical v1 (参照実装 spirux)
diff --git a/tests/Architecture/PromptClientTimeoutInvariantTest.php b/tests/Architecture/PromptClientTimeoutInvariantTest.php
index 7f0f1d2a..a551cf3d 100644
--- a/tests/Architecture/PromptClientTimeoutInvariantTest.php
+++ b/tests/Architecture/PromptClientTimeoutInvariantTest.php
@@ -2,38 +2,93 @@
 
 declare(strict_types=1);
 
+use Tests\Support\PromptWaitBudget;
 use Tests\Support\PromptYaml;
 
 /*
  * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
  * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
  * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
+ * 宣言を落とすと、docs/architecture.md §AI 解析ジョブの運用契約 が挙げる 3 前提が
+ * 成立する現行実装では実効値が config/prism.php の request_timeout (30 秒) へ縮み、
+ * 360 秒前提の時間 budget 連鎖 (AnalysisTimeBudgetInvariantTest) の前提が黙って崩れて
+ * provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる。
+ * ★この gate は**実効値そのものは見ない** (下の「保証しないもの」1)。
+ *   実効値の話は前提つきの運用契約であり、本 gate が固定するのは**宣言の側**だけである。
  *
- * 走査は PromptYamlContractTest と同じ deny-by-default (再帰 + 0 件 fail-fast)。
+ * 【走査対象】`PromptYaml::paths()` = resources/prompts 配下の *.yaml / *.yml を再帰全数
+ *   (大文字拡張子も拾う)。0 件は失敗にする。
+ * 【判定の正本】`Tests\Support\PromptWaitBudget` **1 箇所**である。
+ *   待ち予算を読む検査 (本 gate / AnalysisTimeBudgetInvariantTest /
+ *   AnalysisTokenBudgetInvariantTest) はすべて同じ読み取り器を参照する
+ *   (同じ規則を 2 実装持つと、片方だけが緩んでも気付けない)。
+ *   検出力の裏取り (負例 9 類型 + 正例 + 解決不能形 3 種) は
+ *   tests/Unit/Architecture/PromptWaitBudgetTest.php が持つ。
+ *
+ * 【この gate が保証しないもの (誇張しない)】
+ *  1. **宣言値が実効値であること**は見ない (読み取り器の docblock が正本)。
+ *  2. **走査の再帰そのものの検出力は裏取りしていない**。`PromptYaml::paths()` は
+ *     探索根を引数で受けず `base_path('resources/prompts')` を直接見るため、見本
+ *     ディレクトリを食わせられない。テスト中に resources/prompts へ一時ファイルを作る形は
+ *     同じ分母を見る他の 3 gate (PromptYamlContractTest / DefensiveInstructionsPresenceTest /
+ *     PromptDefenseWindowGateTest) を汚すので採らない。**実データにも
+ *     サブディレクトリが無い**ので、再帰が壊れても本 gate は気付かない。
+ *  3. 到達証明は**「現在の列挙結果に既知 5 本が含まれること」**だけである。
+ *     全数性そのものは `PromptYaml::paths()` の実装契約に依存する。
+ *     既知 5 本は**いずれも resources/prompts 直下**にあるので、
+ *     `paths()` が非再帰へ退行しても 5 本は取れて**緑のまま**になる
+ *     (= 再帰性の退行は検出しない。上の 2 と同じ限界である)。
+ *     新規 prompt が分母に入ることも本証明は保証しない (再帰全数走査の既定拒否が受け持つ)。
+ */
+
+/** 走査根 (resources/prompts) からの相対パス。違反ラベルに使う。 */
+function promptWaitBudgetLabel(string $absolutePath): string
+{
+    $prefix = rtrim(base_path('resources/prompts'), '/').'/';
+    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);
+
+    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
+}
+
+/**
+ * 現在の列挙結果に必ず含まれる既知の prompt (到達証明)。
+ *
+ * ★件数の pin ではなく**包含**である。新規 prompt の追加でこの一覧を直す必要は無く、
+ *   既知の 1 本が消えた・改名された・走査根が別物になったときだけ赤くなる。
+ *   意図した削除なら同じ PR でこの一覧を直す。
+ * ★**再帰性の退行は検出しない** (5 本とも resources/prompts 直下にあるため)。
  */
-test('全 prompt YAML が client_options.timeout (>0) を宣言する', function (): void {
+const PROMPT_WAIT_BUDGET_REQUIRED_LABELS = [
+    'example-summary.yaml',
+    'scenario-generation.yaml',
+    'sop-extract-media.yaml',
+    'sop-extract.yaml',
+    'work-decomposition.yaml',
+];
+
+test('走査の列挙結果に既知の prompt YAML が含まれる (分母の到達証明)', function (): void {
+    $labels = array_map(promptWaitBudgetLabel(...), PromptYaml::paths());
+
+    expect($labels)->not->toBeEmpty();
+
+    $missing = array_values(array_diff(PROMPT_WAIT_BUDGET_REQUIRED_LABELS, $labels));
+
+    expect($missing)->toBe([],
+        '走査の列挙結果に既知の prompt YAML が含まれていません'
+        .' (走査根の改名・移動、または既知ファイルの削除・改名)。'
+        .PHP_EOL.'不足: '.implode(', ', $missing));
+});
+
+test('全 prompt YAML が client_options.timeout (>0 の int) を宣言する', function (): void {
     $files = PromptYaml::paths();
 
+    // ★到達証明の test と重複するが**意図的に残す**。各不変条件の test を単独で
+    //   フィルタ実行したときにも「分母 0 件で緑」にならないようにするため。
     expect($files)->not->toBeEmpty();
 
     $violations = [];
     foreach ($files as $file) {
-        $parseErrors = [];
-        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
-        if ($parsed === null) {
-            array_push($violations, ...$parseErrors);
-
-            continue;
-        }
-        if (! array_key_exists('client_options', $parsed) || ! is_array($parsed['client_options'])) {
-            $violations[] = "{$file}: client_options (map) がありません";
-
-            continue;
-        }
-        $timeout = $parsed['client_options']['timeout'] ?? null;
-        if (! is_int($timeout) || $timeout <= 0) {
-            $violations[] = "{$file}: client_options.timeout は正の int で宣言してください";
-        }
+        array_push($violations, ...PromptWaitBudget::violations($file, promptWaitBudgetLabel($file)));
```

(注: 上の diff は Round 1 提示時点の作業ツリーからの差分ではなく HEAD からの累積差分になる場合がある。
Round 1 で指摘された 3 点の修正後の該当箇所を以下に全文で示す。)

### tests/Architecture/PromptClientTimeoutInvariantTest.php の冒頭コメント
```php
<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;
use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 * 宣言を落とすと、docs/architecture.md §AI 解析ジョブの運用契約 が挙げる 3 前提が
 * 成立する現行実装では実効値が config/prism.php の request_timeout (30 秒) へ縮み、
 * 360 秒前提の時間 budget 連鎖 (AnalysisTimeBudgetInvariantTest) の前提が黙って崩れて
 * provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる。
 * ★この gate は**実効値そのものは見ない** (下の「保証しないもの」1)。
 *   実効値の話は前提つきの運用契約であり、本 gate が固定するのは**宣言の側**だけである。
 *
 * 【走査対象】`PromptYaml::paths()` = resources/prompts 配下の *.yaml / *.yml を再帰全数
 *   (大文字拡張子も拾う)。0 件は失敗にする。
 * 【判定の正本】`Tests\Support\PromptWaitBudget` **1 箇所**である。
 *   待ち予算を読む検査 (本 gate / AnalysisTimeBudgetInvariantTest /
 *   AnalysisTokenBudgetInvariantTest) はすべて同じ読み取り器を参照する
 *   (同じ規則を 2 実装持つと、片方だけが緩んでも気付けない)。
 *   検出力の裏取り (負例 9 類型 + 正例 + 解決不能形 3 種) は
 *   tests/Unit/Architecture/PromptWaitBudgetTest.php が持つ。
 *
 * 【この gate が保証しないもの (誇張しない)】
 *  1. **宣言値が実効値であること**は見ない (読み取り器の docblock が正本)。
 *  2. **走査の再帰そのものの検出力は裏取りしていない**。`PromptYaml::paths()` は
 *     探索根を引数で受けず `base_path('resources/prompts')` を直接見るため、見本
 *     ディレクトリを食わせられない。テスト中に resources/prompts へ一時ファイルを作る形は
 *     同じ分母を見る他の 3 gate (PromptYamlContractTest / DefensiveInstructionsPresenceTest /
 *     PromptDefenseWindowGateTest) を汚すので採らない。**実データにも
 *     サブディレクトリが無い**ので、再帰が壊れても本 gate は気付かない。
 *  3. 到達証明は**「現在の列挙結果に既知 5 本が含まれること」**だけである。
 *     全数性そのものは `PromptYaml::paths()` の実装契約に依存する。
 *     既知 5 本は**いずれも resources/prompts 直下**にあるので、
 *     `paths()` が非再帰へ退行しても 5 本は取れて**緑のまま**になる
 *     (= 再帰性の退行は検出しない。上の 2 と同じ限界である)。
 *     新規 prompt が分母に入ることも本証明は保証しない (再帰全数走査の既定拒否が受け持つ)。
 */

/** 走査根 (resources/prompts) からの相対パス。違反ラベルに使う。 */
function promptWaitBudgetLabel(string $absolutePath): string
```

### tests/Support/PromptWaitBudget.php の read() と段 2 の docblock
```php
 *         にするため、段 2 に混ぜると「構文が壊れている」と区別できなくなる。
 *         走査由来のパスでは起きないが `requirePositive()` は名前から組んだパスを受ける
 *         (prompt の改名で現実に起きる)。
 *   段 2: parse 不能 / 最上位が map でない → `PromptYaml::parseOrFail()` が積む既存の 2 ラベル。
 *         共有ヘルパが理由を積まずに null を返した場合も**適合にはせず**内部不整合として
 *         違反にする (公開 2 口が非対称になる = `violations()` だけ緑になる形を作らない)。
 *         **本クラスは自前の `catch` を書かない** (分類は既存の共有ヘルパに従う)。
 *         ★ただし同ヘルパは `Yaml::parseFile()` の投げる `Throwable` をまとめて
 *         「parse 失敗」へ分類するため、**構文エラーと vendor 内部のエラーの区別までは
 *         保証しない** (ヘルパは採用時債務として凍結されており本 PR では変えない)。
 *   段 3: 上記 5 類型 → `evaluate()` が積む。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言値が実効値であることは主張しない**。見るのは宣言の有無と型と正負だけである。
     */
    private static function read(string $absolutePath, string $label): array
    {
        if (! is_file($absolutePath)) {
            return self::rejected("{$label}: prompt YAML が無い ({$absolutePath})");
        }

        /** @var list<string> $parseErrors */
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($absolutePath, $parseErrors);
        if ($parsed === null) {
            // 共有ヘルパは失敗時に必ず理由を積むが、積まずに null を返す形へ変わったときに
            // 「違反 0 件 = 適合」へ倒れる (= 公開 2 口が非対称になり violations() だけ緑になる)。
            // 現状は到達しない分岐だが、黙って適合にせず内部不整合として落とす (fail-closed)。
            return $parseErrors === []
                ? self::rejected("{$label}: prompt YAML の解析結果を解決できない (共有ヘルパが理由を積まなかった)")
                : ['timeout' => null, 'violations' => $parseErrors];
        }

        return self::evaluate($parsed, $label);
    }

```

### docs/template-divergence.md D50 の該当箇所
```markdown
## D50 LLM 待ち予算の宣言検査を、単一読み取り器 + 検出器自己テスト形へ差し替える

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/PromptClientTimeoutInvariantTest.php` / `tests/Support/PromptWaitBudget.php` / `tests/Unit/Architecture/PromptWaitBudgetTest.php` |
| 業務要件起因の説明 | 本アプリの AI 解析は 1 呼び出し 360 秒の待ち予算を前提に deadline (3C) と job timeout と retry_after と予約 TTL の連鎖を組んでおり、prompt YAML の宣言が落ちると (`docs/architecture.md` §AI 解析ジョブの運用契約 が挙げる 3 前提が成立する現行実装では) 実効値が 30 秒へ縮み、provider の応答が 30 秒を超えた時点で解析が早期に打ち切られる。テンプレートの形は判定を gate ファイル内へインラインで持つため、同じ規則が時間 budget 側にも複製されて実際に緩い実装 (0 以下を通す) が生まれた。判定を 1 クラスへ切り出し、待ち予算を読む検査すべてがそれを参照する形にする |
| 揃え続ける不変条件と保証機構 | 全 prompt YAML が `client_options.timeout` を正の整数で宣言することは `PromptClientTimeoutInvariantTest` が既定拒否で固定する (テンプレートと同じ不変条件)。分母の全数性は既存の走査ヘルパ `PromptYaml::paths()` の実装契約に依存し、新設 test が裏取りするのは現在の列挙結果に既知 5 本が含まれることまでである。判定の検出力は `tests/Unit/Architecture/PromptWaitBudgetTest.php` が負例 9 類型 + 正例 + 解決不能形 3 種 (分類つき) で裏取りする |
| 再判定の条件 | テンプレート側が判定の 1 クラス化を取り込んだとき (家系の正典 v1 が既にこの形なので、追従で差分が消える可能性がある)。または `resources/prompts` の走査ヘルパ (`PromptYaml`) をテンプレートへ同期する判断をしたとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1016-llm-prompt-wait-budget-v1/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-06-30 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 判定の置き場 | gate ファイル内のインライン判定 | `Tests\Support\PromptWaitBudget` 1 クラス (public 2 口・判定は private) |
| 検出力の裏取り | なし | 負例 9 類型 + 正例 + 解決不能形 3 種 (`tests/Unit/Architecture/PromptWaitBudgetTest.php`) |
| 分母の証明 | 非空のみ | 非空 + 既知 5 本の包含 (走査根の改名・移動と既知ファイルの削除で赤くなる。再帰性の退行は検出しない) |
| 時間 budget 側との関係 | 無関係 (テンプレートに解析パイプラインが無い) | 同じ読み取り器を `AnalysisBudget::clientTimeoutSecondsFromYaml()` が参照する |

### なぜ正当な差分か (logic-driven)

1. **待ち予算の宣言落ちは本アプリでだけ致命になる**。SOP → シナリオ生成の 3 段
   (`sop-extract` / `work-decomposition` / `scenario-generation`) と OCR 経路
   (`sop-extract-media`) は 1 呼び出し 360 秒を前提に時間 budget の連鎖を組んでいる。
   宣言が 1 行落ちると (実効性の 3 前提が成立する現行実装では) 実効値は
   `config/prism.php` の 30 秒へ縮み、**平常時は何も起きず、provider の応答が
   30 秒を超えたときだけ**「解析が早期に打ち切られる」という形で遠くに症状が出る。
   テンプレートには解析パイプラインが無いので、同じ検査でも守っている連鎖の長さが違う。
   (**ワーカー占有の長期化は本登録では主張しない** — 30 秒への短縮の直接の帰結は
   早期打ち切りであり、リトライ反復によるキュー圧迫を主張するにはその経路と上限の
   裏取りが要る。裏取りしていないものは書かない。)
2. **同じ規則の複数実装が実際に緩んでいた**。判定を gate 内へインラインで書く形だったため、
   時間 budget 側 (`AnalysisBudget::clientTimeoutSecondsFromYaml()`) が
   `Assert::integer()` 止まりの別実装になり **`timeout: 0` を通していた**。
   穴が閉じていたのは「別の gate が偶然厳しかったから」であって、構造ではなかった。
   判定を 1 クラスへ寄せると、どの検査から見ても同じ厳しさで落ちる。
```

## 再検証の結果 (修正後)

- `vendor/bin/pint --test`: passed
- `composer phpstan`: level 10 / 1114 files / No errors
- `composer test`: 7376 tests / 7374 passed / 0 failed / 2 skipped / 5 risky / 34671 assertions
- risky 5 件は main の直前クローズ (T254) の検証記録にも同数で現れており本 PR 由来ではない

## 質問

Round 1 の [Warning] 2 件と [Suggestion] 1 件への対応で全体判定を再評価せよ。
