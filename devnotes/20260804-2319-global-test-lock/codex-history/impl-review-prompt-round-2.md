## Round 1 の指摘への対応

全体判定は APPROVED (Critical / Warning ゼロ) だったが、Suggestion 1 件を採用したので
その差分の確認を求める。もう 1 件は見送り、根拠を記録した。

### [Suggestion 1] 層 2 に「CI 分岐禁止」の静的検査を追加 → **対応した**

理由: 概念設計は「`CI=true` によるバイパス分岐を作らない」を非交渉方針として明記しているのに、
それを機械で固定する手段が無く「今は無い」ことしか保証できていなかった
(AGENTS.md 禁止事項 1 = 不変条件は Architecture テストへの登録まで含めて実装済み、に照らすと登録漏れ)。

実装:
- 純関数 `globalTestLockCiBypassViolations()` と定数 `GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS` を追加
- 検査対象は lane 3 本 + ラッパ + **ライブラリ本体** (`scripts/global-test-lock.sh`)。
  バイパスを入れるならライブラリが最有力なので、`GLOBAL_TEST_LOCK_GUARDED_SCRIPTS`
  (trap / exec fd リダイレクトを正当に持つため除外している) とは別リストにした
- 検査は `globalTestLockCodeLines()` の出力 (行頭コメント除去済みの実行行) に対して行う
  = 実装が「CI を特別扱いしない」方針をコメントで説明できる
- 負のコントロール 2 方向 (バイパス分岐を検出する / コメント内の `${CI}` は違反にしない) を追加

### [Suggestion 2] C19 の `/dev/tcp` 非対応 skip 条件の明示 → **見送った**

理由: 「`/dev/tcp` が使えないシェルでは検査を skip して続行する (guard であって保証ではない)」は
`scripts/run-browser-test.sh` のヘッダコメントに既に明記済み。層 1 の C19 が skip するのは
「python3 不在 / 8010..8018 を bind できない」の 2 条件で、いずれも `[SKIP]` 行として
理由つきで出力され、集計にも skip 数が必ず出る (偽グリーンにならない)。
`/dev/tcp` 無効ビルドは devcontainer にも ubuntu-latest にも存在せず、
今分岐を足すのは「今必要なものだけ作る」に反するため、必要が生じた時点で足す。

## 追加テスト結果

- 層 2 `vendor/bin/pest tests/Architecture/GlobalTestLockInventoryTest.php`:
  12 tests → **14 tests / 49 assertions / 全 pass**
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- 層 1 (`scripts/verify-global-test-lock.sh`) は shell 側に変更が無いため再実行結果は Round 1 と同じ
  (passed=65 failed=0 skipped=0)

## 差分 (今回の追加分のみ)

```diff
diff --git a/tests/Architecture/GlobalTestLockInventoryTest.php b/tests/Architecture/GlobalTestLockInventoryTest.php
index 478a31b..feac672 100644
--- a/tests/Architecture/GlobalTestLockInventoryTest.php
+++ b/tests/Architecture/GlobalTestLockInventoryTest.php
@@ -152,6 +152,37 @@ function globalTestLockCodeLines(string $source): string
     return implode("\n", $code);
 }
 
+/**
+ * CI バイパス分岐の禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
+ *
+ * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
+ * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
+ * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
+ */
+const GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS = [
+    'scripts/global-test-lock.sh',
+    'scripts/with-global-test-lock.sh',
+    'scripts/run-test.sh',
+    'scripts/run-browser-test.sh',
+    'scripts/run-vitest.sh',
+];
+
+/**
+ * ロック機構が `CI` 環境変数を参照していないことを検査する (純関数)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function globalTestLockCiBypassViolations(string $path, string $source): array
+{
+    $code = globalTestLockCodeLines($source);
+
+    if (preg_match('/\$\{?CI\b/', $code) === 1) {
+        return ["{$path} が CI 環境変数で分岐している (CI バイパスを作らないこと)"];
+    }
+
+    return [];
+}
+
 /**
  * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
  *
@@ -258,6 +289,15 @@ function globalTestLockLaneScriptViolations(string $path, string $source): array
     }
 });
 
+test('ロック機構が CI バイパス分岐を持たないこと', function (): void {
+    foreach (GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS as $rel) {
+        $source = file_get_contents(base_path($rel));
+        expect($source)->toBeString();
+        /** @var string $source */
+        expect(globalTestLockCiBypassViolations($rel, $source))->toBe([]);
+    }
+});
+
 /*
  * 負のコントロール (実ファイルは書き換えない):
  * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
@@ -325,6 +365,26 @@ function globalTestLockLaneScriptViolations(string $path, string $source): array
     expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
 });
 
+test('負のコントロール: CI バイパス分岐を検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    if [ "${CI:-}" = "true" ]; then
+        exec "$@"
+    fi
+    SH;
+    $violations = globalTestLockCiBypassViolations('fixture.sh', $broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('CI バイパス');
+
+    // コメント内の説明は違反にしない (実装が方針を説明できないと困るため)。
+    $ok = <<<'SH'
+    #!/usr/bin/env bash
+    # CI バイパス分岐は作らない (${CI} で素通りさせない)
+    global_test_lock_acquire "lane"
+    SH;
+    expect(globalTestLockCiBypassViolations('fixture.sh', $ok))->toBe([]);
+});
+
 test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
     $broken = <<<'SH'
     #!/usr/bin/env bash
```

この追加で **全体判定 APPROVED が維持されるか** を確認してほしい。
新たな Critical / Warning があれば指摘すること (特に正規表現の偽陽性・偽陰性)。
