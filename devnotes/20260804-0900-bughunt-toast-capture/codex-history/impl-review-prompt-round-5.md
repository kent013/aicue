# impl-review Round 5 (レビュー後に追加した 1 変更の確認のみ)

Round 4 で APPROVED をもらった後、**設計の施策 1〜6 には無い変更**を 1 件だけ追加した。
これが「テストを緩めて黙らせた」に該当しないか、カバレッジの穴を作っていないかだけを見てほしい。
probe 本体 (`feedback-probe.js`)・`SKILL.md`・`spec-ledger.md`・`test_spec_ledger.py`・
`feedback-probe.test.ts` は Round 4 から**一切変更していない**。

## 経緯

設計 §検証計画は担保手段として
`python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` を挙げている。
これを走らせたところ、**本 TODO と無関係の既存 2 件が fail** していた:

- `EmptySeedRegistryTest.test_seed_has_no_entries`
- `EmptySeedRegistryTest.test_empty_registry_reports_zero_and_exits_zero`

原因は commit `062a822`(別 TODO)が `ledger/adjudications.jsonl` に実 run 由来の裁定 `A-001` を
登録した際、「同梱 seed は空」というテンプレート由来の前提に依存したテストを更新しなかったこと。
**main でも同じ 2 件が fail する**ことを実測確認済み(= 本ブランチが壊したのではない)。

## 判断

守りたい不変条件は「**registry が空でも validator が落ちない**(fail-closed による全面停止を作らない)」
であって「同梱 seed が空であること」ではない、と判断した。したがって

- 前提が崩れた `test_seed_has_no_entries` を削除、
- `test_empty_registry_reports_zero_and_exits_zero` を **tempfile の空ファイル**に対して実行するよう変更。

同梱 seed 自体の妥当性は既存の `AdjudicationBackwardCompatTest::test_seed_registry_is_valid`
(`validate_adjudications(load_jsonl(adjudications.jsonl)) == []`)が引き続き見る。

`ledger/adjudications.jsonl` 本体は設計どおり**一切触っていない**。

## 変更 diff (全文)

```diff
 class EmptySeedRegistryTest(unittest.TestCase):
-    """seed は空 (spirux 由来 18 件を削除)。空 registry でも valid / exit 0 であること。"""
+    """**空の** registry でも valid / exit 0 であること (fail-closed で全面停止しない)。

-    def _seed_path(self):
-        import os
-        return os.path.join(os.path.dirname(__file__), "adjudications.jsonl")
-
-    def test_seed_has_no_entries(self):
-        entries = [a for _, a, _ in v.load_jsonl(self._seed_path()) if a is not None]
-        self.assertEqual(entries, [])
+    かつては同梱 seed (`adjudications.jsonl`) が空である前提でそのファイルを使っていたが、
+    AI-CUE の実 run 由来の裁定 (A-001) が登録されて前提が崩れた。守りたい不変条件は
+    「registry が空でも validator が落ちない」ことなので、**空ファイルを都度作って**検証する。
+    同梱 seed 自体の妥当性は `AdjudicationBackwardCompatTest::test_seed_registry_is_valid` が見る。
+    """

     def test_empty_registry_reports_zero_and_exits_zero(self):
         import contextlib
-        buf = io.StringIO()
-        with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
-            code = v.main([self._example_findings(), "--adjudications", self._seed_path(), "--json"])
+        import os
+        import tempfile
+        with tempfile.TemporaryDirectory() as tmp:
+            empty = os.path.join(tmp, "adjudications.jsonl")
+            with open(empty, "w", encoding="utf-8"):
+                pass
+            buf = io.StringIO()
+            with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(io.StringIO()):
+                code = v.main([self._example_findings(), "--adjudications", empty, "--json"])
         self.assertEqual(code, 0)
         summary = json.loads(buf.getvalue())
         self.assertEqual(summary["adjudications_total"], 0)
         self.assertEqual(summary["adjudications_invalid"], 0)
```

## 実行結果

- 変更前: `Ran 71 tests` / `FAILED (failures=2)`
- 変更後: `Ran 70 tests` / `OK`
- `validate_findings.py example.findings.jsonl --adjudications adjudications.jsonl --json` は
  `adjudications_total: 1` / `adjudications_invalid: 0` / exit 0。

## 聞きたいこと (これだけ)

1. この変更は「**テストを実装に合わせて緩めた**」に該当するか。該当するなら、代わりに守るべき
   assertion は何か(例: seed が空でなくなったこと自体を別の形で pin すべきか)。
2. 削除した `test_seed_has_no_entries` が担っていた保護のうち、
   `test_seed_registry_is_valid` でも `test_empty_registry_reports_zero_and_exits_zero` でも
   拾えなくなったものはあるか。
3. そもそも本 TODO(feedback probe)でこの修正を抱き合わせるべきではなく、別 TODO に切り出して
   赤いまま報告すべきだったか。

指摘が無ければ `APPROVED` とだけ返してほしい。
