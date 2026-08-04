## Round 2 の Critical への対応

指摘は妥当だったのでそのまま対応した。

### [Critical] `-v CI` / `printenv CI` の偽陰性 → **修正した**

検出パターンを 4 本に拡張した:

1. `/\$\{?CI\b/` — `$CI` / `${CI}` / `${CI:-}` / `${CI+x}`
2. `/(?:\[\[|\btest\b|\[)[^\n]*\s-v\s+["\']?CI["\']?/` — `[[ -v CI ]]` / `test -v CI` / `[ -v CI ]`
3. `/\bprintenv\b[^\n]*\bCI\b/` — `printenv CI`
4. `/\benv\b[^\n|]*\|[^\n]*\bCI\b/` — `env | grep CI`

負のコントロールは 1 本 → **6 形態のテーブル駆動**に拡張した
(`expansion` / `bracket-v` / `test-v` / `printenv` / `env-grep` / `indirect` = `flag=$CI` の 2 段構え)。
コメント内の `${CI}` / `printenv CI` は違反にしない (正のコントロール) も維持。

### [Suggestion] 偽陽性と契約表現の不一致 → **「参照禁止」に揃えた**

分岐検出へ限定すると `flag=$CI` → `if [ "$flag" ]` の 2 段構えを取りこぼし、Critical と同じ穴を
別の形で残す。ロック機構が CI を読む正当な用途は 1 つも無いので、**参照自体を禁じる**方が
契約として単純で漏れがない (安全側の偽陽性は許容)。命名・文言・docblock をこれに合わせた:

- `globalTestLockCiBypassViolations` → `globalTestLockCiReferenceViolations`
- `GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS` → `GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS`
- エラー文 →「`{path}` が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)」
- docblock に「契約は『分岐していないこと』ではなく『参照していないこと』= deny-by-default」と明記

## テスト結果

- 層 2: 14 tests / **59 assertions** (49 → 59) / 全 pass
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- 層 1 (shell) は無変更のため Round 1 と同じ (passed=65 failed=0 skipped=0)

## 差分 (Round 2 からの追加分)

```diff
diff --git a/tests/Architecture/GlobalTestLockInventoryTest.php b/tests/Architecture/GlobalTestLockInventoryTest.php
index 478a31b..192fad5 100644
--- a/tests/Architecture/GlobalTestLockInventoryTest.php
+++ b/tests/Architecture/GlobalTestLockInventoryTest.php
@@ -152,6 +152,51 @@ function globalTestLockCodeLines(string $source): string
     return implode("\n", $code);
 }
 
+/**
+ * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
+ *
+ * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
+ * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
+ * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
+ */
+const GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS = [
+    'scripts/global-test-lock.sh',
+    'scripts/with-global-test-lock.sh',
+    'scripts/run-test.sh',
+    'scripts/run-browser-test.sh',
+    'scripts/run-vitest.sh',
+];
+
+/**
+ * ロック機構が `CI` 環境変数を **参照していない** ことを検査する (純関数)。
+ *
+ * 契約は「分岐していないこと」ではなく「**参照していないこと**」= deny-by-default。
+ * 分岐だけを狙うと `flag=$CI` → `if [ "$flag" ]` のような 2 段構えを取りこぼすし、
+ * そもそもロック機構が CI を読む正当な用途が 1 つも無いため、参照自体を禁じる方が
+ * 契約として単純で漏れがない (安全側の偽陽性は許容する)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function globalTestLockCiReferenceViolations(string $path, string $source): array
+{
+    $code = globalTestLockCodeLines($source);
+
+    // 参照の書き方は複数あるので、bash で実際に CI を読める形を網羅する。
+    $patterns = [
+        '/\$\{?CI\b/',                     // $CI / ${CI} / ${CI:-} / ${CI+x}
+        '/(?:\[\[|\btest\b|\[)[^\n]*\s-v\s+["\']?CI["\']?/', // [[ -v CI ]] / test -v CI
+        '/\bprintenv\b[^\n]*\bCI\b/',      // printenv CI
+        '/\benv\b[^\n|]*\|[^\n]*\bCI\b/',  // env | grep CI
+    ];
+    foreach ($patterns as $pattern) {
+        if (preg_match($pattern, $code) === 1) {
+            return ["{$path} が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)"];
+        }
+    }
+
+    return [];
+}
+
 /**
  * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
  *
@@ -258,6 +303,15 @@ function globalTestLockLaneScriptViolations(string $path, string $source): array
     }
 });
 
+test('ロック機構が CI 環境変数を参照しないこと (CI バイパス禁止)', function (): void {
+    foreach (GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS as $rel) {
+        $source = file_get_contents(base_path($rel));
+        expect($source)->toBeString();
+        /** @var string $source */
+        expect(globalTestLockCiReferenceViolations($rel, $source))->toBe([]);
+    }
+});
+
 /*
  * 負のコントロール (実ファイルは書き換えない):
  * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
@@ -325,6 +379,31 @@ function globalTestLockLaneScriptViolations(string $path, string $source): array
     expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
 });
 
+test('負のコントロール: CI 環境変数の参照を書き方によらず検出する', function (): void {
+    // 「${CI} だけ見る」実装だと素通りする形を含めて固定する (Codex impl-review Round 2 の指摘)。
+    $broken = [
+        'expansion' => '        if [ "${CI:-}" = "true" ]; then exec "$@"; fi',
+        'bracket-v' => '        if [[ -v CI ]]; then return 0; fi',
+        'test-v' => '        if test -v CI; then return 0; fi',
+        'printenv' => '        if [ "$(printenv CI)" = "true" ]; then return 0; fi',
+        'env-grep' => '        if env | grep -q "^CI="; then return 0; fi',
+        'indirect' => '        flag=$CI',
+    ];
+    foreach ($broken as $label => $line) {
+        $violations = globalTestLockCiReferenceViolations('fixture.sh', "#!/usr/bin/env bash\n{$line}\n");
+        expect($violations)->not->toBe([], "CI 参照 ({$label}) を検出できていない");
+        expect(implode("\n", $violations))->toContain('CI 環境変数を参照している');
+    }
+
+    // コメント内の説明は違反にしない (実装が方針を説明できないと困るため)。
+    $ok = <<<'SH'
+    #!/usr/bin/env bash
+    # CI バイパス分岐は作らない (${CI} で素通りさせない / printenv CI も見ない)
+    global_test_lock_acquire "lane"
+    SH;
+    expect(globalTestLockCiReferenceViolations('fixture.sh', $ok))->toBe([]);
+});
+
 test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
     $broken = <<<'SH'
     #!/usr/bin/env bash
```

偽陰性が残っていないか、および新たな偽陽性 (実装ファイルを誤検出する形) が無いかを確認してほしい。
