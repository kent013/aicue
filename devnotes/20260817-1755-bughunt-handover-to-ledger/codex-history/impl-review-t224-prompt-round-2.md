## 対応マトリクス (Round 1 の指摘への対応)

devnotes/20260817-1755-bughunt-handover-to-ledger/codex-history/impl-review-t224-decisions-round-1.md
の内容をそのまま転記する:

# 対応マトリクス: impl-review-t224 Round 1

## [Warning] test_active_successor_of_a001_watches_toast_ts が「別々の登録がそれぞれの条件を満たすだけで通る」
- 判断: 対応する
- 根拠: 指摘のとおり、旧テストは (1) 同じ species_key/scope_value を持つ active 登録が toast.ts を持つこと、
  (2) 何らかの登録が A-001 を supersede していること、を独立に検証していた。この 2 条件は別々の登録が
  満たしても通ってしまい、A-004 が両方を兼ねることを固定できていなかった。
- 対応内容: `test_active_successor_of_a001_is_a004_and_watches_toast_ts` に置き換え、
  A-001 を **直接** supersede する登録を 1 件取得し、その 1 件が同じ species_key/scope_value を
  持つこと・active であること・機械項目 (verdict/conditions/symptom/rationale_ref/
  source_finding_ids/adjudicated_at_run/adjudicated_at_commit) が A-001 と同じであること・
  watch_globs に toast.ts を含むことをすべて同一レコードに対して検証するようにした。
  さらに「同じ種別・対象面を持つ active な登録がこの 1 件だけ」であることも別途固定した。

## [Warning] test_a001_itself_is_unchanged_and_now_superseded が species_key と watch_globs しか固定していない
- 判断: 対応する
- 根拠: 指摘のとおり、scope / conditions / symptom / verdict / rationale_ref / source_finding_ids /
  adjudicated_at_run / adjudicated_at_commit / review_after_days の改変を検出できていなかった。
- 対応内容: 移行時点の A-001 の全機械項目 (context を除く) を `EXPECTED_A001` として明示し、
  `records["A-001"]` から `context` を除いた辞書全体を `assertEqual` で比較するように書き換えた。
  これにより A-001 の機械項目のどの 1 項目が変わっても即座に検出できる。

再実行結果: `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` Ran 120 tests, OK。
`render_spec_ledger.py --check` exit 0。`validate_findings.py` errors 0 (adjudications: 4, invalid: 0)。

## 修正後の差分 (test_t224_a001_watch_globs.py。新設ファイルなのでフルスクラッチの diff として出る)

adjudications.jsonl / test_spec_ledger.py / spec-ledger.md は Round 1 から変更していない
(Round 1 でこれらは「問題なし」判定だった)。

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py b/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py
new file mode 100644
index 00000000..48d5a831
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py
@@ -0,0 +1,137 @@
+"""T224: A-001 の再オープン条件と watch_globs の食い違いを閉じたことの契約テスト (stdlib のみ)。
+
+A-001 の再オープン条件 (b) は `resources/js/lib/stores/toast.ts` の
+`AUTO_DISMISS_MS` を挙げているが、A-001 自身の `watch_globs` にこのファイルは無かった
+(移行時点の登録はそのままにする append-only 規約のため、A-001 は今後も直さない)。
+この登録を supersede する新登録 (A-004) が `watch_globs` に `toast.ts` を持つことを固定する。
+
+実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
+"""
+
+from __future__ import annotations
+
+import unittest
+from pathlib import Path
+
+import render_spec_ledger as renderer
+
+LEDGER_DIR = Path(__file__).resolve().parent
+ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"
+
+# A-001 が持っていた種別・対象面 (機械項目は不変。新登録も同じ判定を指す)。
+SPECIES_KEY = "other:video_manual:delete:self"
+SCOPE_VALUE = "projects.manuals.destroy"
+REQUIRED_WATCH_GLOB = "resources/js/lib/stores/toast.ts"
+
+
+# A-001 の移行時点の内容 (機械項目は append-only 規約によりこの先も書き換えない)。
+EXPECTED_A001 = {
+    "adjudication_id": "A-001",
+    "species_key": SPECIES_KEY,
+    "scope": {"scope_kind": "route_name", "scope_value": SCOPE_VALUE},
+    "conditions": {"browser": "chromium", "mode": "real-llm"},
+    "symptom": {
+        "required_tokens": ["delete_success_flash_missing"],
+        "known_tokens": ["toast", "auto_dismiss", "projects_show_redirect"],
+    },
+    "verdict": "false_positive",
+    "rationale_ref": "devnotes/20260804-0021-ux-small-gaps/detailed-design.md",
+    "source_finding_ids": ["F-1-02"],
+    "adjudicated_at_run": "20260803-203721",
+    "adjudicated_at_commit": "22d6d30",
+    "watch_globs": [
+        "app/Http/Controllers/Projects/VideoManualController.php",
+        "resources/js/components/organisms/ToastContainer.svelte",
+        "resources/js/lib/stores/flash-to-toast.ts",
+    ],
+    "review_after_days": 180,
+}
+
+
+class A001WatchGlobsCoverToastTest(unittest.TestCase):
+    def test_a001_itself_is_unchanged_and_now_superseded(self) -> None:
+        """A-001 の機械項目 (context を除く全項目) は移行時点から 1 バイトも変えず、
+        supersede によって履歴化されていること。"""
+        records = {r["adjudication_id"]: r for r in renderer.load_adjudications(str(ADJUDICATIONS))}
+        self.assertIn("A-001", records)
+        a001 = dict(records["A-001"])
+        a001.pop("context", None)
+        self.assertEqual(
+            a001,
+            EXPECTED_A001,
+            "A-001 の機械項目 (context 以外) は append-only 規約により書き換えない",
+        )
+        superseded_ids = {r["supersedes"] for r in records.values() if r.get("supersedes")}
+        self.assertIn("A-001", superseded_ids, "A-001 は新登録に supersede されて履歴化されていること")
+
+    def test_active_successor_of_a001_is_a004_and_watches_toast_ts(self) -> None:
+        """A-001 を **直接** supersede し、かつ同じ種別・対象面を持つ登録がちょうど 1 件あり、
+        それが現在 active であり、その watch_globs に toast.ts が含まれること。
+
+        supersede 関係・種別/対象面の一致・active 状態・watch_globs をまとめて 1 つの登録
+        (A-004) に対して検証する (別々の登録がそれぞれの条件を満たすだけでは通らない)。
+        """
+        records = renderer.load_adjudications(str(ADJUDICATIONS))
+        by_id = {r["adjudication_id"]: r for r in records}
+        superseded_ids = {r["supersedes"] for r in records if r.get("supersedes")}
+
+        direct_successors = [r for r in records if r.get("supersedes") == "A-001"]
+        self.assertEqual(
+            len(direct_successors),
+            1,
+            f"A-001 を直接 supersede する登録がちょうど 1 件であること: {direct_successors!r}",
+        )
+        successor = direct_successors[0]
+
+        # 同じ種別・対象面を持ち、かつ現在 active (誰にも supersede されていない) であること。
+        self.assertEqual(successor["species_key"], SPECIES_KEY)
+        self.assertEqual(successor["scope"]["scope_kind"], "route_name")
+        self.assertEqual(successor["scope"]["scope_value"], SCOPE_VALUE)
+        self.assertNotIn(
+            successor["adjudication_id"],
+            superseded_ids,
+            f"{successor['adjudication_id']} 自身が別の登録に supersede されていないこと (active であること)",
+        )
+
+        # 判定内容 (機械項目) は A-001 から変えていないこと。
+        for key in (
+            "verdict",
+            "conditions",
+            "symptom",
+            "rationale_ref",
+            "source_finding_ids",
+            "adjudicated_at_run",
+            "adjudicated_at_commit",
+        ):
+            self.assertEqual(
+                successor[key],
+                by_id["A-001"][key],
+                f"{successor['adjudication_id']}.{key} は A-001 と同じであること (判定内容は変えていない)",
+            )
+
+        self.assertIn(
+            REQUIRED_WATCH_GLOB,
+            successor["watch_globs"],
+            f"{successor['adjudication_id']}: watch_globs に {REQUIRED_WATCH_GLOB} が無い "
+            "(toast.ts の AUTO_DISMISS_MS 変更が invalidation を発火しない)",
+        )
+
+        # 同じ種別・対象面を持つ active な登録は、この 1 件だけであること
+        # (誤って A-001 と同種の登録が並立していないか)。
+        active_same_species_scope = [
+            r
+            for r in records
+            if r["species_key"] == SPECIES_KEY
+            and r["scope"]["scope_value"] == SCOPE_VALUE
+            and r["adjudication_id"] not in superseded_ids
+        ]
+        self.assertEqual(
+            active_same_species_scope,
+            [successor],
+            "同じ種別・対象面の active な登録は A-004 の 1 件だけであること: "
+            f"{[r['adjudication_id'] for r in active_same_species_scope]!r}",
+        )
+
+
+if __name__ == "__main__":
+    unittest.main()

```

上記の対応で Warning 2 件が解消されているか再確認し、全体判定を APPROVED または
CHANGES_REQUESTED の 1 行で明記すること。
