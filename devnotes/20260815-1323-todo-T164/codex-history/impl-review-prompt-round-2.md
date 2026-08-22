# Round 2: 指摘への対応と、未提示だった施策 5 (文書) の差分

## 対応マトリクス (Round 1 の指摘をどう捌いたか)

# 対応マトリクス: impl-review Round 1

## [Critical] build_executed.py `_check_row()` が unhashable な `status` で traceback になる

- 判断: **対応する**
- 根拠: 指摘のとおり `{} in {"ok","blocked"}` は `TypeError: unhashable type` を投げる。
  `main()` は `CaptureError` と `OSError` しか捕まえないため、終了コード規約 (1 / 3) から
  外れて traceback で落ちる。「構文上は読めるが形が契約外なら 3」という契約の穴であり、
  correlate.py 側は同じ理由で既に `isinstance(status, str)` を先に見ている (非対称だった)。
- 対応内容: `_check_row()` の判定を
  `if not isinstance(status, str) or status not in VALID_STATUSES:` に変更した。

## [Warning] BughuntExecutedRouteOrderingTest のコメント / 失敗メッセージが誤った直し方を案内している

- 判断: **対応する**
- 根拠: `appendToPriorityList` は `[$append => $after]` の連想配列なので、同じ記録器を
  複数の anchor で append すると後勝ちで 1 本しか残らない。赤を見た人がその案内どおりに
  直すと、直したつもりで順序が閉じない (静かに fail-open へ戻る) 経路になる。
- 対応内容: docblock と失敗メッセージの案内を
  `prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡middleware)` に直し、
  「なぜ append 側ではないか」も 1 行添えた。

## [Warning] test_build_executed.py に unhashable status の負の対照が無い

- 判断: **対応する**
- 根拠: 上の Critical を直しても、回帰を止めるテストが無ければ同じ穴が戻る
  (禁止事項 1: 不変条件はテストへの登録まで含めて実装済み)。
- 対応内容: `test_unhashable_status_returns_3` を追加し、`{}` / `[]` / `0` を通しても
  traceback ではなく終了コード 3 になることを固定した。

## [Suggestion] test_naming_no_stale.py の `--executed 省略` パターンが backtick 付き表記を拾えない

- 判断: **対応する**
- 根拠: 文言 gate の目的は「旧 fail-open の説明が文書へ戻ること」の検知であり、
  Markdown では `` `--executed` 省略時 `` と書くのが自然な表記である。
  現状は `未実行 candidate` 側で捕まるが、旧文言だけが単独で戻ると素通しになる。
- 対応内容: パターンを `` `?--executed`?\s*(を)?省略 `` に広げ、gate 自身のテストへ
  backtick 付き表記のケースを追加した。

## [注記] 施策 5 の文書差分がレビューに含まれていなかった

- 判断: **Round 2 で提示する**
- 根拠: 差分の分量を抑えるため `docs/` `AGENTS.md` `.claude/agents/` `.claude/skills/**/SKILL.md`
  を Round 1 の diff から外していたが、施策 5 は文言 gate と対になる変更なので未確認のままにしない。
- 対応内容: Round 2 のプロンプトへ当該 4 ファイルの diff を全文添付する。


## 修正差分 (Round 1 の 4 指摘に対する変更)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage/build_executed.py b/.claude/skills/app-bug-hunt/coverage/build_executed.py
new file mode 100644
index 0000000..4ea0485
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/build_executed.py
@@ -0,0 +1,212 @@
+#!/usr/bin/env python3
+"""実行済み route の記録 (JSONL) を束ねて executed.json を作る。
+
+入力は BughuntExecutedRouteMiddleware が走行中に書いた
+storage/bughunt-executed/{run}-{shard}.jsonl (1 行 1 要求)。
+出力は照合器 correlate.py の主入力 executed.json。
+
+**主入力が揃わない走行は成功にしない** (終了コード 3)。詳細は README の終了コード規約。
+
+使い方:
+    python3 build_executed.py --run-id 20260618-082101 --shard 0 \
+      [--input-dir storage/bughunt-executed] \
+      --out devnotes/20260618-082101-bug-hunt/executed.json
+
+依存は標準ライブラリのみ。
+"""
+from __future__ import annotations
+
+import argparse
+import json
+import os
+import sys
+import tempfile
+from pathlib import Path
+
+# 終了コード規約 (scripts/bug-hunt-inventory-check.sh / correlate.py と同じ 3 = 契約違反)。
+EXIT_OK = 0
+EXIT_INPUT_ERROR = 1        # 引数・I/O の失敗
+EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない
+
+# 記録器が書く status の語彙。2 値だけを受け付ける。
+VALID_STATUSES = {"ok", "blocked"}
+
+
+class CaptureError(Exception):
+    """主入力の可用性違反。reason は README の理由コード。"""
+
+    def __init__(self, reason: str, detail: str) -> None:
+        super().__init__(f"{reason}: {detail}")
+        self.reason = reason
+        self.detail = detail
+
+
+def capture_path(input_dir: str, run_id: str, shard: str) -> Path:
+    return Path(input_dir) / f"{run_id}-{shard}.jsonl"
+
+
+def failure_path(input_dir: str, run_id: str, shard: str) -> Path:
+    return Path(input_dir) / f"{run_id}-{shard}.error"
+
+
+def _check_row(row: object, run_id: str, shard: str, where: str) -> dict:
+    """1 行の形を全項目検査する (壊れた入力を集計しない)。"""
+    if not isinstance(row, dict):
+        raise CaptureError("capture_row_invalid", f"{where}: 行が JSON object でない")
+    if row.get("run_id") != run_id:
+        raise CaptureError(
+            "run_id_mismatch",
+            f"{where}: run_id が --run-id と違う ({row.get('run_id')!r} != {run_id!r})",
+        )
+    if row.get("shard") != shard:
+        raise CaptureError(
+            "capture_row_invalid",
+            f"{where}: shard がファイル名と違う ({row.get('shard')!r} != {shard!r})",
+        )
+    status = row.get("status")
+    # isinstance を先に見る。dict / list の status を集合照合へ渡すと
+    # TypeError: unhashable type になり、main() の捕捉対象外なので終了コード規約から外れる。
+    if not isinstance(status, str) or status not in VALID_STATUSES:
+        raise CaptureError("capture_row_invalid", f"{where}: status が {sorted(VALID_STATUSES)} 以外 ({status!r})")
+    http_status = row.get("http_status")
+    # bool は int の派生なので明示的に除外する。
+    if isinstance(http_status, bool) or not isinstance(http_status, int):
+        raise CaptureError("capture_row_invalid", f"{where}: http_status が整数でない ({http_status!r})")
+    method = row.get("method")
+    if not isinstance(method, str) or method == "":
+        raise CaptureError("capture_row_invalid", f"{where}: method が非空文字列でない ({method!r})")
+    name = row.get("route_name")
+    if name is not None and (not isinstance(name, str) or name == ""):
+        raise CaptureError("capture_row_invalid", f"{where}: route_name が null でも非空文字列でもない ({name!r})")
+    return row
+
+
+def load_shard(input_dir: str, run_id: str, shard: str) -> tuple[list[dict], int]:
+    """1 shard 分の JSONL を読み、(検査済みの行, 全有効行数) を返す。
+
+    可用性違反はすべて CaptureError で送出する (静かに捨てない)。
+    """
+    if failure_path(input_dir, run_id, shard).exists():
+        raise CaptureError(
+            "capture_failed",
+            f"shard {shard}: 失敗マーカー {failure_path(input_dir, run_id, shard)} がある",
+        )
+    path = capture_path(input_dir, run_id, shard)
+    if not path.is_file():
+        raise CaptureError("capture_file_missing", f"shard {shard}: {path} が無い")
+
+    rows: list[dict] = []
+    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
+        if raw.strip() == "":
+            continue
+        where = f"{path}:L{lineno}"
+        try:
+            parsed = json.loads(raw)
+        except json.JSONDecodeError as e:
+            raise CaptureError("capture_line_broken", f"{where}: {e}") from e
+        rows.append(_check_row(parsed, run_id, shard, where))
+
+    named = [r for r in rows if r.get("route_name") is not None]
+    if not named:
+        raise CaptureError(
+            "capture_empty",
+            f"shard {shard}: 名前付き route の行が 1 件も無い (記録が採れていない)",
+        )
+    return rows, len(rows)
+
+
+def build(input_dir: str, run_id: str, shards: list[str]) -> tuple[dict, list[str]]:
+    """executed.json の中身と、stderr へ出す件数サマリを組み立てる。"""
+    # (route_name, shard, status) -> http_status の集合
+    folded: dict[tuple[str, str, str], set[int]] = {}
+    unresolved: dict[str, int] = {}
+    summary: list[str] = []
+
+    for shard in shards:
+        rows, total = load_shard(input_dir, run_id, shard)
+        names: set[str] = set()
+        for row in rows:
+            name = row.get("route_name")
+            if name is None:
+                unresolved[shard] = unresolved.get(shard, 0) + 1
+                continue
+            names.add(name)
+            key = (name, shard, row["status"])
+            folded.setdefault(key, set()).add(row["http_status"])
+        summary.append(
+            f"shard {shard}: 行 {total} / route {len(names)} / 名前なし {unresolved.get(shard, 0)}"
+        )
+
+    executed_routes = [
+        {
+            "route_name": name,
+            "shard": shard,
+            "status": status,
+            "http_statuses": sorted(statuses),
+        }
+        for (name, shard, status), statuses in sorted(folded.items())
+    ]
+
+    return {
+        "run_id": run_id,
+        "shards": list(shards),
+        "executed_routes": executed_routes,
+        "unresolved": unresolved,
+    }, summary
+
+
+def write_atomic(out: str, data: dict) -> None:
+    """一時ファイルへ書いて os.replace() で差し替える (壊れた成果物を残さない)。
+
+    一時ファイルは **--out と同じディレクトリ**に作る (別ファイルシステムだと
+    os.replace() が失敗し atomic rename の前提が崩れる)。
+    """
+    out_path = Path(out)
+    out_path.parent.mkdir(parents=True, exist_ok=True)
+    fd, tmp = tempfile.mkstemp(dir=str(out_path.parent), prefix=".executed-", suffix=".json")
+    try:
+        with os.fdopen(fd, "w", encoding="utf-8") as f:
+            json.dump(data, f, ensure_ascii=False, indent=2)
+            f.write("\n")
+        os.replace(tmp, out_path)
+    except BaseException:
+        Path(tmp).unlink(missing_ok=True)
+        raise
+
+
+def main(argv=None) -> int:
+    ap = argparse.ArgumentParser(
+        description="bug-hunt 実行済み route の記録 (JSONL) から executed.json を作る")
+    ap.add_argument("--run-id", required=True, help="run_id (記録行の run_id と一致すること)")
+    ap.add_argument("--shard", required=True, action="append", dest="shards",
+                    help="shard 番号 (provision した shard をすべて渡す。複数指定可)")
+    ap.add_argument("--input-dir", default="storage/bughunt-executed",
+                    help="記録 JSONL の置き場 (既定 storage/bughunt-executed)")
+    ap.add_argument("--out", required=True, help="出力する executed.json のパス")
+    args = ap.parse_args(argv)
+
+    try:
+        data, summary = build(args.input_dir, args.run_id, args.shards)
+    except CaptureError as e:
+        print(f"ERROR: 主入力が揃わない (reason={e.reason}) {e.detail}。"
+              " executed.json は書き出さない (揃わない走行を成功として返さないため)。",
+              file=sys.stderr)
+        return EXIT_INPUT_UNAVAILABLE
+    except OSError as e:
+        print(f"ERROR: {e}", file=sys.stderr)
+        return EXIT_INPUT_ERROR
+
+    try:
+        write_atomic(args.out, data)
+    except OSError as e:
+        print(f"ERROR: {e}", file=sys.stderr)
+        return EXIT_INPUT_ERROR
+
+    for line in summary:
+        print(line, file=sys.stderr)
+    print(f"executed.json を書き出した: {args.out}", file=sys.stderr)
+    return EXIT_OK
+
+
+if __name__ == "__main__":
+    raise SystemExit(main())
diff --git a/.claude/skills/app-bug-hunt/coverage/test_build_executed.py b/.claude/skills/app-bug-hunt/coverage/test_build_executed.py
new file mode 100644
index 0000000..e5b8a50
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/test_build_executed.py
@@ -0,0 +1,204 @@
+#!/usr/bin/env python3
+"""build_executed.py の単体テスト (stdlib unittest)。
+
+入出力は tempfile で作る。**主入力が揃わない走行を成功にしない**ことが本モジュールの契約なので、
+負の対照 (終了コード 3) を理由コードごとに 1 本ずつ置く。
+
+実行: python3 -m unittest test_build_executed -v
+"""
+from __future__ import annotations
+
+import json
+import tempfile
+import unittest
+from pathlib import Path
+
+import build_executed as B
+import correlate as C
+
+RUN = "20260618-082101"
+
+
+def row(shard: str, name: str | None, status: str = "ok", http: int = 200,
+        run_id: str = RUN, method: str = "GET") -> dict:
+    return {
+        "run_id": run_id,
+        "shard": shard,
+        "route_name": name,
+        "method": method,
+        "path": "/x",
+        "status": status,
+        "http_status": http,
+    }
+
+
+class BuildExecutedTest(unittest.TestCase):
+    def setUp(self) -> None:
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        self.base = Path(self.tmp.name)
+        self.input_dir = self.base / "capture"
+        self.input_dir.mkdir()
+        self.out = self.base / "executed.json"
+
+    # ------------------------------------------------------------------ #
+    # helpers
+    # ------------------------------------------------------------------ #
+    def write_jsonl(self, shard: str, rows: list[dict], *, raw: str | None = None) -> None:
+        path = self.input_dir / f"{RUN}-{shard}.jsonl"
+        if raw is not None:
+            path.write_text(raw, encoding="utf-8")
+            return
+        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows),
+                        encoding="utf-8")
+
+    def run_main(self, shards: list[str]) -> int:
+        args = ["--run-id", RUN, "--input-dir", str(self.input_dir), "--out", str(self.out)]
+        for s in shards:
+            args += ["--shard", s]
+        return B.main(args)
+
+    def loaded_out(self) -> dict:
+        return json.loads(self.out.read_text(encoding="utf-8"))
+
+    # ------------------------------------------------------------------ #
+    # 正の対照
+    # ------------------------------------------------------------------ #
+    def test_two_shards_folded(self):
+        self.write_jsonl("0", [
+            row("0", "projects.store", "ok", 302),
+            row("0", "projects.store", "ok", 302),   # 同一キーは畳まれる
+            row("0", "projects.store", "ok", 200),   # http_statuses は集合
+            row("0", "projects.update", "blocked", 403),
+        ])
+        self.write_jsonl("1", [row("1", "login.store", "ok", 302)])
+
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)
+        data = self.loaded_out()
+        self.assertEqual(data["run_id"], RUN)
+        self.assertEqual(data["shards"], ["0", "1"])
+        self.assertEqual(data["executed_routes"], [
+            {"route_name": "login.store", "shard": "1", "status": "ok", "http_statuses": [302]},
+            {"route_name": "projects.store", "shard": "0", "status": "ok", "http_statuses": [200, 302]},
+            {"route_name": "projects.update", "shard": "0", "status": "blocked", "http_statuses": [403]},
+        ])
+        self.assertEqual(data["unresolved"], {})
+
+    def test_unresolved_rows_are_counted_not_listed(self):
+        self.write_jsonl("0", [
+            row("0", "dashboard", "ok", 200),
+            row("0", None, "ok", 200),
+            row("0", None, "blocked", 500),
+        ])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_OK)
+        data = self.loaded_out()
+        self.assertEqual([r["route_name"] for r in data["executed_routes"]], ["dashboard"])
+        self.assertEqual(data["unresolved"], {"0": 2})
+
+    def test_output_is_valid_input_for_correlate(self):
+        # 生成器と照合器の契約が食い違っていないことを 1 本で固定する。
+        self.write_jsonl("0", [row("0", "dashboard", "ok", 200)])
+        self.write_jsonl("1", [row("1", "login.store", "blocked", 302)])
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)
+
+        ex = C.load_executed(str(self.out))
+        self.assertIsNone(C.validate_executed(ex, RUN))
+        self.assertTrue(ex.is_executed("dashboard"))
+        self.assertFalse(ex.is_executed("login.store"))
+
+    # ------------------------------------------------------------------ #
+    # 負の対照 (終了コード 3)
+    # ------------------------------------------------------------------ #
+    def test_missing_capture_file_returns_3(self):
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_failure_marker_returns_3(self):
+        self.write_jsonl("0", [row("0", "dashboard")])
+        (self.input_dir / f"{RUN}-0.error").write_text("disk full\n", encoding="utf-8")
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_broken_line_returns_3(self):
+        self.write_jsonl("0", [], raw='{"run_id": "x"\n')
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_other_run_row_returns_3(self):
+        self.write_jsonl("0", [
+            row("0", "dashboard"),
+            row("0", "login.store", run_id="20260101-000000"),
+        ])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_named_rows_absent_returns_3(self):
+        # route_name: null の行しか無い shard もここで落ちる。
+        self.write_jsonl("0", [row("0", None)])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_empty_file_returns_3(self):
+        self.write_jsonl("0", [], raw="")
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_unknown_status_returns_3(self):
+        self.write_jsonl("0", [row("0", "dashboard", status="whatever")])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_non_string_status_returns_3(self):
+        # dict / list を集合照合へ渡すと TypeError: unhashable type になる。
+        # **traceback ではなく終了コード 3** で落ちることを固定する。
+        for bad in [{}, [], 0, None]:
+            with self.subTest(status=bad):
+                r = row("0", "dashboard")
+                r["status"] = bad
+                self.write_jsonl("0", [r])
+                self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_shard_mismatch_in_row_returns_3(self):
+        # ファイル名は shard 0 なのに中身が shard 1 を名乗る
+        self.write_jsonl("0", [row("1", "dashboard")])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_non_int_http_status_returns_3(self):
+        for bad in ["200", True, None]:
+            with self.subTest(http_status=bad):
+                r = row("0", "dashboard")
+                r["http_status"] = bad
+                self.write_jsonl("0", [r])
+                self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_empty_route_name_returns_3(self):
+        r = row("0", "dashboard")
+        r["route_name"] = ""
+        self.write_jsonl("0", [r])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_missing_method_returns_3(self):
+        r = row("0", "dashboard")
+        del r["method"]
+        self.write_jsonl("0", [r])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_row_not_object_returns_3(self):
+        self.write_jsonl("0", [], raw='["not", "an", "object"]\n')
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_failure_does_not_overwrite_existing_out(self):
+        self.out.write_text('{"keep": "me"}', encoding="utf-8")
+        self.write_jsonl("0", [row("0", "dashboard")])
+        (self.input_dir / f"{RUN}-0.error").write_text("boom\n", encoding="utf-8")
+
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertEqual(self.out.read_text(encoding="utf-8"), '{"keep": "me"}')
+
+    def test_one_bad_shard_fails_the_whole_run(self):
+        # 揃っている shard があっても、揃わない shard が 1 つあれば成功にしない。
+        self.write_jsonl("0", [row("0", "dashboard")])
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
index 0850d40..818cd25 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
@@ -1,15 +1,22 @@
-"""操作到達/コード到達カバレッジへの命名統一の後退防止 self-test。
+"""操作到達/コード到達カバレッジの用語・契約の後退防止 self-test。
 
-旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名 (coverage-stage1.md / coverage-stage3.md) が
-skill 本文ファイルに再混入していないことを機械検知する。
+2 群のパターンを検知する。
 
-対象は `.claude/skills/app-bug-hunt/` 配下の本文 (.md / .py)。誤 fail を避けるため、
-devnotes (設計 migration note・履歴説明) とこの test 自身は対象外にする。
+1. STALE_PATTERNS — 旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名
+   (coverage-stage1.md / coverage-stage3.md)。対象は skill 配下の本文 (.md / .py) 全部。
+2. IMPLEMENTATION_ONLY_PATTERNS — 旧 fail-open の文言 (「--executed 省略時」「未実行 candidate」)
+   と旧語彙 (skipped_blocked_count / status 値としての skipped)。
+   **対象から test_*.py を除外する** — 旧値を拒否する負の対照テストは、入力 fixture として
+   その文字列を必要とするためである。.md は全部対象に残す。
+
+裸の `skipped` は禁止語にしない (unittest の skipTest や無関係な英文を巻き込むため)。
+誤 fail を避けるため、devnotes (設計 migration note・履歴説明) とこの test 自身は対象外。
 """
 
 from __future__ import annotations
 
 import re
+import tempfile
 import unittest
 from pathlib import Path
 
@@ -22,14 +29,24 @@ STALE_PATTERNS = [
     re.compile(r"coverage-stage[13]\.md"),
 ]
 
+# 旧 fail-open の文言と旧語彙。実装ファイル (と全 .md) にだけ禁じる。
+IMPLEMENTATION_ONLY_PATTERNS = [
+    # Markdown では `--executed` と backtick で囲むのが自然な表記なので許容する。
+    re.compile(r"`?--executed`?\s*(を)?省略"),
+    re.compile(r"未実行\s*candidate"),
+    re.compile(r"skipped_blocked_count"),
+    re.compile(r"ok\|blocked\|skipped"),
+    re.compile(r"['\"]skipped['\"]"),
+]
+
 # 対象外: 履歴・設計ノートは devnotes 側に隔離されている前提。skill 配下は本 test 自身のみ除外。
 EXCLUDE_NAMES = {"test_naming_no_stale.py"}
 
 
-def _target_files() -> list[Path]:
+def _target_files(root: Path = SKILL_ROOT) -> list[Path]:
     files: list[Path] = []
     for ext in ("*.md", "*.py"):
-        for p in SKILL_ROOT.rglob(ext):
+        for p in root.rglob(ext):
             if p.name in EXCLUDE_NAMES:
                 continue
             if "devnotes" in p.parts:
@@ -38,22 +55,94 @@ def _target_files() -> list[Path]:
     return files
 
 
+def _is_test_module(path: Path) -> bool:
+    return path.suffix == ".py" and path.name.startswith("test_")
+
+
+def scan(root: Path = SKILL_ROOT) -> tuple[list[str], list[str]]:
+    """(旧 Stage 付番の違反, 旧 fail-open 文言・旧語彙の違反) を返す。"""
+    stale: list[str] = []
+    impl: list[str] = []
+    for path in _target_files(root):
+        rel = path.relative_to(root)
+        text = path.read_text(encoding="utf-8")
+        for lineno, line in enumerate(text.splitlines(), start=1):
+            for pat in STALE_PATTERNS:
+                if pat.search(line):
+                    stale.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+            if _is_test_module(path):
+                continue
+            for pat in IMPLEMENTATION_ONLY_PATTERNS:
+                if pat.search(line):
+                    impl.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+    return stale, impl
+
+
 class StaleNamingTest(unittest.TestCase):
     def test_no_stage_terminology_in_skill_body(self) -> None:
-        offenders: list[str] = []
-        for path in _target_files():
-            text = path.read_text(encoding="utf-8")
-            for lineno, line in enumerate(text.splitlines(), start=1):
-                for pat in STALE_PATTERNS:
-                    if pat.search(line):
-                        rel = path.relative_to(SKILL_ROOT)
-                        offenders.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+        stale, _ = scan()
+        self.assertEqual(
+            stale,
+            [],
+            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(stale),
+        )
+
+    def test_no_stale_fail_open_wording_in_implementation(self) -> None:
+        _, impl = scan()
         self.assertEqual(
-            offenders,
+            impl,
             [],
-            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(offenders),
+            "旧 fail-open の文言 / 旧語彙が実装・文書に残存:\n" + "\n".join(impl),
         )
 
 
+class GateItselfTest(unittest.TestCase):
+    """gate 自身が空振りしていないことの検査 (合成ファイルで判定する)。"""
+
+    def setUp(self) -> None:
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        self.root = Path(self.tmp.name)
+
+    def _write(self, name: str, body: str) -> None:
+        (self.root / name).write_text(body, encoding="utf-8")
+
+    def test_detects_in_implementation_file(self) -> None:
+        self._write("impl.py", "x = 1  # --executed 省略時は全 in_scope を未実行 candidate 扱い\n")
+        _, impl = scan(self.root)
+        # 1 行に 2 パターン一致するので件数は数えず、検出されたことだけを固定する。
+        self.assertTrue(impl, "実装ファイルの旧 fail-open 文言を検出できていない")
+        self.assertTrue(all(v.startswith("impl.py:1:") for v in impl), impl)
+
+    def test_detects_old_vocabulary_in_markdown(self) -> None:
+        self._write("doc.md", "status は `ok|blocked|skipped`。\n")
+        _, impl = scan(self.root)
+        self.assertEqual(len(impl), 1, impl)
+
+    def test_detects_backticked_option_wording(self) -> None:
+        # Markdown 表記 (`--executed` 省略時) 単独でも検出できること。
+        self._write("doc.md", "`--executed` 省略時は全 in_scope を candidate 扱いにする。\n")
+        _, impl = scan(self.root)
+        self.assertEqual(len(impl), 1, impl)
+
+    def test_does_not_detect_in_test_module(self) -> None:
+        self._write("test_something.py", 'row = {"status": "skipped"}  # 旧値を拒否する負の対照\n')
+        _, impl = scan(self.root)
+        self.assertEqual(impl, [])
+
+    def test_stale_stage_patterns_still_apply_to_test_modules(self) -> None:
+        # 旧 Stage 付番は test_*.py も対象 (除外は IMPLEMENTATION_ONLY_PATTERNS だけ)。
+        self._write("test_something.py", "# Stage1 の名残\n")
+        stale, _ = scan(self.root)
+        self.assertEqual(len(stale), 1, stale)
+
+    def test_excluded_name_is_skipped(self) -> None:
+        # 自ファイル除外 (EXCLUDE_NAMES) が効いていること (依存を暗黙にしない)。
+        self._write("test_naming_no_stale.py", "# Stage1 と 未実行 candidate\n")
+        stale, impl = scan(self.root)
+        self.assertEqual(stale, [])
+        self.assertEqual(impl, [])
+
+
 if __name__ == "__main__":
     unittest.main()
diff --git a/tests/Architecture/BughuntExecutedRouteOrderingTest.php b/tests/Architecture/BughuntExecutedRouteOrderingTest.php
new file mode 100644
index 0000000..cd2cc75
--- /dev/null
+++ b/tests/Architecture/BughuntExecutedRouteOrderingTest.php
@@ -0,0 +1,137 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
+use App\Http\Middleware\RequireActiveSubscription;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Routing\MiddlewareShortCircuitInventory;
+use Tests\Support\Routing\NestedRouteDefenseInventory;
+
+/**
+ * 実行済み route の記録器 (BughuntExecutedRouteMiddleware) の位置に関する順序不変条件 (T164)。
+ *
+ * 記録器の出力は「その route まで実際に到達できた」ことの証拠として使う。したがって
+ * **短絡しうる middleware が記録器より後ろで走ると、遮断された要求まで実行済みに数える**
+ * (例: recent-auth の 302 は session に errors を残さないため ok と誤記録される)。
+ * これは本 TODO が消そうとしている偽陽性そのものなので、順序を機械的に固定する。
+ *
+ * 分類の正本は {@see MiddlewareShortCircuitInventory}。未分類クラスは
+ * **短絡しうる (true) 側の既定**で扱うため、分類漏れが偽陰性にならない。
+ *
+ * 違反したときの直し方: bootstrap/app.php の「記録器より前で走る短絡 middleware」の一覧へ
+ * その middleware を足す (= `prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡)`)。
+ * priority list は「載っている middleware 同士の相対順序」しか強制しないため、短絡側も
+ * priority list に載せる必要がある。
+ *
+ * ⚠ **append 側で書かない**。`appendToPriorityList($after, $append)` は
+ * `[$append => $after]` の連想配列で持つため、同じ記録器を複数の anchor で append すると
+ * 後勝ちで 1 本しか残らず、直したつもりで順序が閉じない。
+ */
+
+/**
+ * 解決後の middleware 列で「記録器より後ろに短絡しうる middleware がある」ものを列挙する。
+ *
+ * 記録器を含まない列 (api / Filament) は対象外として空を返す。
+ *
+ * @param  list<string>  $resolved
+ * @return list<string>
+ */
+function bughuntRecorderOrderViolations(array $resolved): array
+{
+    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
+    if ($recorderIndex === false) {
+        return [];
+    }
+
+    $classification = MiddlewareShortCircuitInventory::classification();
+    $violations = [];
+    foreach ($resolved as $index => $middleware) {
+        if ($index < $recorderIndex) {
+            continue;
+        }
+        if (($classification[$middleware] ?? true) === true) {
+            $violations[] = $middleware;
+        }
+    }
+
+    return $violations;
+}
+
+test('主契約: 記録器が付いた全 route で、短絡しうる middleware は記録器より前で走る', function (): void {
+    $violations = [];
+    $checked = 0;
+
+    /** @var RoutingRoute $route */
+    foreach (Route::getRoutes() as $route) {
+        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+        if (! in_array(BughuntExecutedRouteMiddleware::class, $resolved, true)) {
+            continue; // web グループ外 (api / Filament) は記録器を持たない
+        }
+        $checked++;
+
+        foreach (bughuntRecorderOrderViolations($resolved) as $middleware) {
+            // route 名は null になりうるので URI と method も出す (原因追跡のため)
+            $label = $route->getName() ?? '(無名)';
+            $violations[] = "{$label} [".implode('|', $route->methods())." /{$route->uri()}]: "
+                ."{$middleware} が記録器より後ろで走る";
+        }
+    }
+
+    $violations = array_values(array_unique($violations));
+    expect($violations)->toBe([],
+        '記録器より後ろで短絡すると、遮断された要求が「実行済み」と誤記録されます。'
+        .'bootstrap/app.php の「記録器より前で走る短絡 middleware」の一覧へ足してください '
+        .'(= prependToPriorityList(BughuntExecutedRouteMiddleware::class, $短絡middleware))。'
+        .'appendToPriorityList 側で書くと、同じ記録器を複数 anchor に付けたときに後勝ちで消えます。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+    // 配線消失の検出 (0 件なら記録器が web グループから外れている)
+    expect($checked)->toBeGreaterThan(0, '記録器が付いた route が 1 本も無い = web グループへの登録が消えている');
+});
+
+test('代表 route: 記録器は認証・テナント境界 404・課金ゲート・退会凍結より後ろにある', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('projects.update');
+    expect($route)->not->toBeNull("route 'projects.update' が存在しない");
+
+    $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
+    expect($recorderIndex)->not->toBeFalse('記録器が projects.update の解決後 middleware 列に無い');
+
+    foreach ([
+        Authenticate::class,
+        EnsureProjectBelongsToRouteOrganization::class,
+        RequireActiveSubscription::class,
+        EnsureAccountNotPendingDeletion::class,
+    ] as $upstream) {
+        $index = array_search($upstream, $resolved, true);
+        expect($index)->not->toBeFalse("{$upstream} が projects.update の列に無い");
+        expect($index)->toBeLessThan($recorderIndex, "{$upstream} が記録器より後ろで走る");
+    }
+});
+
+test('負の対照: 短絡クラスが記録器より後ろにある合成の列を違反として検出する', function (): void {
+    $shortCircuiting = MiddlewareShortCircuitInventory::shortCircuiting();
+    expect($shortCircuiting)->not->toBe([], '短絡しうると分類された middleware が 1 つも無い');
+    $shortCircuit = $shortCircuiting[0];
+
+    // 記録器の後ろに短絡クラスを置いた合成の列 = 違反として検出されること
+    expect(bughuntRecorderOrderViolations([
+        BughuntExecutedRouteMiddleware::class,
+        $shortCircuit,
+    ]))->toBe([$shortCircuit]);
+
+    // 前に置いた列は違反にならないこと (常に真を返す判定式でないことの対照)
+    expect(bughuntRecorderOrderViolations([
+        $shortCircuit,
+        BughuntExecutedRouteMiddleware::class,
+    ]))->toBe([]);
+
+    // 記録器を含まない列は対象外 (api / Filament を巻き込まない)
+    expect(bughuntRecorderOrderViolations([$shortCircuit]))->toBe([]);
+});

```

## Round 1 で提示していなかった施策 5 (手順・契約の文書更新) の差分

```diff
diff --git a/.claude/agents/bughunt-shard.md b/.claude/agents/bughunt-shard.md
index 0367641..5bdcc9c 100644
--- a/.claude/agents/bughunt-shard.md
+++ b/.claude/agents/bughunt-shard.md
@@ -89,6 +89,12 @@ ## 走行手順
    > **万一 Write が拒否されたら**最終メッセージに finding 全文 (完全な再現手順込み) を必ず返す。
 6. 走行終了時に `playwright-cli close` で自セッションを閉じる。
 
+> **走行ログは書かない。実行済み route の記録はアプリ側が自動で採る。**
+> 「どの操作を実際に叩けたか」は serve 側の記録器 (`BughuntExecutedRouteMiddleware`) が
+> 走行中に機械記録する (親が provision 時に仕込む)。あなたが executed.json / 実行済み route の
+> 一覧を手で書く必要は無く、書いてもカバレッジ突合には使われない。
+> レポートに書くのは finding と走行の所見だけである。
+
 ## 禁止事項 (SKILL.md「禁止事項」に従う。特に本ワーカーで重要なもの)
 
 - **自シャード以外への接続禁止**: 対象 URL は `127.0.0.1:801{i}` のみ。dev (:8000 系)・他 shard ポート・
diff --git a/.claude/skills/app-bug-hunt/SKILL.md b/.claude/skills/app-bug-hunt/SKILL.md
index 97743f8..6fdf8d1 100644
--- a/.claude/skills/app-bug-hunt/SKILL.md
+++ b/.claude/skills/app-bug-hunt/SKILL.md
@@ -420,8 +420,26 @@ ### Phase 4 後: カバレッジ突合 (operation-reach は毎回 / code-reach 
 
 レポート確定後、run の網羅を **未カバー worklist** として機械突合する (`coverage/README.md` が正本)。
 
-- **操作到達カバレッジ (operation-reach、毎回)** — `coverage/correlate.py`。run_id で executed / findings /
-  operations.md / graph.db を突合し「未実行機構 / ★cross / hotspot」を出す。pcov 不要。
+- **操作到達カバレッジ (operation-reach、毎回)** — 2 コマンド。走行中にアプリ側の記録器
+  (`BughuntExecutedRouteMiddleware`) が書いた JSONL を `coverage/build_executed.py` が束ね、
+  `coverage/correlate.py` が機構分母と突合して「未実行機構 / ★cross / hotspot」を出す。pcov 不要。
+
+  ```bash
+  # provision した shard をすべて --shard に渡す (manifest の shard 番号が正本。直列走行は 0)
+  python3 .claude/skills/app-bug-hunt/coverage/build_executed.py \
+    --run-id {ts} --shard 1 --shard 2 --shard 3 --shard 4 \
+    --out devnotes/{ts}-bug-hunt/executed.json
+  python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
+    --operations .claude/skills/app-bug-hunt/operations.md \
+    --findings 'devnotes/{ts}-bug-hunt/shard-*/findings.jsonl' \
+    --executed devnotes/{ts}-bug-hunt/executed.json \
+    --graph-db /workspace/.code-review-graph/graph.db \
+    --run-id {ts} > devnotes/{ts}-bug-hunt/coverage-operation-reach.md
+  ```
+
+  **どちらかが終了コード 3 で落ちたら、レポートに「カバレッジ突合できず」と明記する**
+  (理由コードを添える)。**未実行一覧は載せない** — 記録が揃っていない走行の一覧は
+  「全部やっていない」という嘘になるためである。
 - **コード到達カバレッジ (code-reach、`--coverage` 時のみ)** — `coverage/merge_pcov.py`。C3 middleware が吐く
   shard JSONL を union し uncovered を主出力する。**pcov 未導入なら OFF** (middleware が no-op)。
 
diff --git a/.claude/skills/app-bug-hunt/coverage/README.md b/.claude/skills/app-bug-hunt/coverage/README.md
index b8b5621..fceb802 100644
--- a/.claude/skills/app-bug-hunt/coverage/README.md
+++ b/.claude/skills/app-bug-hunt/coverage/README.md
@@ -45,16 +45,19 @@ ## 構成
 
 | ファイル | 役割 |
 |---|---|
+| `build_executed.py` | **実行済み route の記録の集約器**。記録器が書いた shard ごとの JSONL を束ねて `executed.json` を作る（stdlib のみ） |
 | `correlate.py` | **操作到達カバレッジ correlator**。run_id で executed / findings / operations / graph を突合し未カバー worklist を作る（stdlib のみ） |
 | `merge_pcov.py` | **コード到達カバレッジ pcov merge**。C3 middleware が吐く shard JSONL を union し uncovered を主出力する（stdlib のみ） |
+| `test_build_executed.py` | build_executed のテスト（`python3 -m unittest`、入出力は tempfile） |
 | `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を追加検証） |
 | `test_merge_pcov.py` | merge のテスト（全 fixture、pcov 不要） |
-| `test_naming_no_stale.py` | 旧 Stage 付番の後退防止 self-test |
+| `test_naming_no_stale.py` | 旧 Stage 付番と旧 fail-open 文言の後退防止 self-test |
 | `fixtures/` | サンプル入力（route-list / operations(5列+6列) / findings / executed）と `fixtures/pcov/` の shard JSONL |
 
 関連（このディレクトリ外）:
 - `../ledger/findings.schema.json` … Finding 台帳のスキーマ。findings.jsonl の正本。
 - `../operations.md` … 機構分母（name 列 / 区分）。
+- 記録器 = `app/Http/Middleware/BughuntExecutedRouteMiddleware.php`（実行済み route の記録。毎回 ON）。
 - C3 middleware = `app/Http/Middleware/BughuntCoverageMiddleware.php`、C5 = `scripts/bug-hunt-shard.sh --coverage`（pcov 導入時）。
 
 ---
@@ -69,24 +72,62 @@ ### 入力
 2. `operations.md` — 機構分母（5 列、name 列が join キー）。区分 `外`(対象外)=分母外、`逸`(逸脱のみ)=未実行でも警告しない材料。
 3. `findings.jsonl` — Finding Ledger（複数 shard を `cat` 連結 or glob 可、`-` で stdin）。`--run-id` で絞る。
    route 直結しない finding は `capability_tag` 経由で story 一致の機構群へブロードキャスト（`via_capability`）。
-4. `executed.json` — bug-hunt 子が「UI 経由で実際に叩いた route」を run_id・shard 単位で記録したもの。
-   複数 shard を union（どれか 1 shard で executed なら executed=true）。スキーマ:
+4. `executed.json` — **実行済み route の記録**（主入力）。走行中にアプリ側の記録器
+   （`BughuntExecutedRouteMiddleware`）が書いた shard ごとの JSONL を `build_executed.py` が束ねたもの。
+   複数 shard を union（どれか 1 shard で `ok` なら executed=true）。スキーマ:
    ```json
    {
      "run_id": "20260618-082101",
      "shards": ["0","1","2","3","4"],
      "executed_routes": [
-       {"route_name": "register.store", "shard": "1", "story": "S1", "status": "ok"}
-     ]
+       {"route_name": "register.store", "shard": "1", "status": "ok", "http_statuses": [302]}
+     ],
+     "unresolved": {"1": 3}
    }
    ```
-   `status` は `ok|blocked|skipped`。`ok` のみ executed 扱い。`--executed` 省略時は全 in_scope 機構を未実行 candidate 扱い。
+   `status` は **`ok` と `blocked` の 2 値**で、生成器が HTTP 応答から写像する
+   （2xx と errors の無い 3xx が `ok`、それ以外は `blocked`）。executed 扱いになるのは `ok` だけ。
+   `unresolved` は「記録器まで到達したが名前の無い route」の件数（shard 別）。
 5. `graph.db` — TESTED_BY を controller ファイル単位で引く（`/workspace/.code-review-graph/graph.db`）。
 
+### 記録 → 集約 → 突合 の流れ
+
+```
+serve (bughunt 環境)
+  └ BughuntExecutedRouteMiddleware        … 1 要求 1 行を追記
+       storage/bughunt-executed/{run}-{shard}.jsonl
+          └ build_executed.py             … shard を束ねて検証し executed.json を作る
+               devnotes/{run}-bug-hunt/executed.json
+                  └ correlate.py          … 機構分母と突合し未カバー worklist を出す
+```
+
+記録は `scripts/bug-hunt-shard.sh provision` が **毎回 ON** で仕込む（`--coverage` の有無に依らない）。
+provision は疎通確認の要求が記録された**ことを確認してから**その行を消して探索へ引き渡すため、
+記録器が配線されていなければ走行前に落ちる。
+
+### 終了コード規約（両ツール共通）
+
+| コード | 意味 | 理由コード（stderr に出る） |
+|---|---|---|
+| 0 | 成立 | — |
+| 1 | 読み込み・parse・I/O の失敗 | （例外メッセージ） |
+| 3 | **主入力の可用性違反**（検査を成立させられない） | `build_executed.py`: `capture_failed` / `capture_file_missing` / `capture_line_broken` / `capture_row_invalid` / `run_id_mismatch` / `capture_empty`<br>`correlate.py`: `executed_missing` / `executed_schema_invalid` / `executed_run_id_mismatch` / `executed_shards_missing` / `executed_no_rows` / `executed_shard_mismatch` |
+
+3 のときは worklist / executed.json を**書き出さない**。揃わない走行を「全件未実行」という
+嘘の一覧として返さないためである。`ok` が 1 件も無い（全操作が跳ねた）走行は 3 ではない
+—— 主入力としては成立しており、正しい結果は「全機構が未実行 worklist に残る」ことである。
+
 ### 使い方
 
 ```bash
 cd /workspace/.claude/worktrees/<worktree>   # CWD を明示
+
+# (1) 記録を束ねる (provision した shard をすべて --shard に渡す)
+python3 .claude/skills/app-bug-hunt/coverage/build_executed.py \
+  --run-id 20260618-082101 --shard 1 --shard 2 --shard 3 --shard 4 \
+  --out devnotes/20260618-082101-bug-hunt/executed.json
+
+# (2) 突合する
 python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
   --operations .claude/skills/app-bug-hunt/operations.md \
   --findings 'devnotes/20260618-082101-bug-hunt/shard-*/findings.jsonl' \
@@ -101,6 +142,7 @@ # 機械集計 (trend 用):
 
 複数 shard の findings は `cat ... | correlate.py --findings -` でも渡せる。
 `--hotspot-threshold N`（既定 2）で hotspot の閾値を変えられる。
+`--input-dir`（既定 `storage/bughunt-executed`）で記録の置き場を変えられる。
 
 ### 出力の読み方（主＝未カバー worklist、% は副）
 
@@ -124,6 +166,8 @@ ### 出力の読み方（主＝未カバー worklist、% は副）
 | `unknown_graph_gap_count` | PHP 等で TESTED_BY 判定不能（= Pest を別途見よ） | 注記 |
 | `in_scope_count` | 分母（gaming 防止のため明示） | 注記 |
 | `dropped_other_run` | run_id 不一致で捨てた行数（trend 汚染検知） | 注記 |
+| `executed_ok_count` | in_scope かつ status `ok` の機構数（内訳） | 注記 |
+| `blocked_count` | status が `blocked` だけの機構数（内訳） | 注記 |
 | `executed_pct` | 実行率 | **副・目標にしない** |
 
 > KPI の使い方は **worklist の逓減**（run を重ねて `unexecuted_count` / `cross_count` が減る）を見る。
@@ -184,9 +228,12 @@ ## テスト
 
 ```bash
 cd /workspace/.claude/worktrees/<worktree>/.claude/skills/app-bug-hunt/coverage
-python3 -m unittest test_correlate test_merge_pcov test_naming_no_stale
+python3 -m unittest test_correlate test_build_executed test_merge_pcov test_naming_no_stale
 ```
 
+`test_correlate` / `test_build_executed` / `test_naming_no_stale` の 3 本は
+`composer test`（`tests/Architecture/BughuntCoverageToolSelfTest.php`）からも実走する。
+
 いずれも **stdlib のみ・pcov 非依存**（graph は fixture sqlite を生成、pcov 入力は fixture JSONL）。
 実 `operations.md` / 実 `graph.db`（`/workspace/.code-review-graph/graph.db`）がある環境では、
 fix-gate #3（name 列 join）/ #4（PHP TESTED_BY=0 → unknown_graph_gap）の追加テストも自動で走る。
diff --git a/AGENTS.md b/AGENTS.md
index 18313ea..9e67bd8 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -281,6 +281,14 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
   `DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガードで、条件不成立なら no-op
   (dev DB に認証状態をばら撒かない)。判定側の regex は残留 DB も検出するため cap より広い。
+- **実行済み route の記録 (毎回 ON・fail-closed)**: 「どの操作を実際に叩けたか」は走行中に
+  `BughuntExecutedRouteMiddleware` が JSONL へ機械記録する (`config/bughunt.php` の `executed.*`。
+  env 既定 false + production 除外で**既定 no-op**)。web グループの**末尾**かつ priority list の
+  鎖の最後に固定してあるため、記録に現れることが「遮断 middleware をすべて通過した」証拠になる
+  (`BughuntExecutedRouteOrderingTest` が deny-by-default で位置を強制)。集約は
+  `coverage/build_executed.py`、突合は `coverage/correlate.py` で、**主入力が揃わない走行は
+  終了コード 3 で落ちる** (未実行 worklist を出さない)。テンプレートとの逸脱理由は
+  `docs/template-divergence.md` D14。
 - **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
   shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
   `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 82f0932..b372339 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -541,3 +541,64 @@ ### 関連
 - 実装: `app/Services/Auth/SocialAccountService.php` / `app/Actions/Fortify/UpdateUserPassword.php` /
   `app/Services/Auth/LoginMethodInventory.php`
 - 設計: `devnotes/20260805-1244-auth-method-and-passkey/` (施策 2)
+
+---
+
+## D14 ✅ 実行済み route の記録をアプリ側の観測器で採る (退避 → 正規化 → route 名解決の 3 段を置かない)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 「どの操作を叩けたか」の採取 | ブラウザの通信履歴を退避 → 正規化器 → artisan コマンドで route 名解決、の 3 段 | **serve のプロセス内で middleware が 1 要求 1 行を追記**する (`BughuntExecutedRouteMiddleware`) |
+| 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
+| 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
+| 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |
+
+### なぜ正当な差分か(logic-driven)
+
+操作到達カバレッジの出力は「次に何を叩くべきか」という作業指示であり、
+**記録が採れていないこと**と**本当に叩けていないこと**を取り違えると、
+一覧そのものが嘘になる (全機構が未実行に見える)。3 段方式はこの取り違えを
+2 か所で作っていた:
+
+1. **採取の起動が LLM に依存する**。退避コマンドを呼び忘れた走行は、
+   記録が空のまま「全部未実行」として成功終了する。
+2. **通信履歴は遮断された要求も含む**。認証・課金ゲート・step-up 再認証で
+   跳ねた 302 は「叩いた」ように見えるが、controller には到達していない。
+   route 名の再解決は URL からの逆引きなので、この差を後段では復元できない。
+
+アプリ側の観測器は、web グループの**末尾** (priority list の鎖の最後) に置くことで
+「ここに到達した = 遮断 middleware をすべて通過した」という機械的事実を得る。
+route 名は `$request->route()->getName()` でその場で確定するので逆引きも要らない。
+起動は `scripts/bug-hunt-shard.sh provision` が env で仕込むため LLM の手順に依存しない。
+
+### 揃えている不変条件(これは保証し続ける)
+
+> 「**主入力が揃わない走行は成功にしない**」
+
+- `scripts/bug-hunt-shard.sh provision` は疎通確認の要求が実際に記録されたことを
+  同期点として確認し、記録されなければ**走行前に**落ちる (`assert_executed_capture_wired`)
+- `coverage/build_executed.py` は失敗マーカー / ファイル欠落 / 壊れた行 / 別 run の混入 /
+  観測行 0 のいずれでも**終了コード 3** で落ち、`executed.json` を書き出さない
+- `coverage/correlate.py` は `--executed` 未指定 / 形が契約外 / run_id 不一致 /
+  shard 宣言と実測の食い違い / 観測行 0 のいずれでも**終了コード 3** で落ち、
+  未実行 worklist を出力しない
+- 記録器が遮断 middleware より内側に居ることは
+  `tests/Architecture/BughuntExecutedRouteOrderingTest.php` が deny-by-default で固定する
+  (短絡しうる middleware の分類は `tests/Support/Routing/MiddlewareShortCircuitInventory.php`)
+- 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
+  `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する
+
+### 保証しないもの (誇張しない)
+
+- **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
+  未実行側へ倒れる (過小申告の方向)
+- **部分欠測は検出しない**。分かるのは「1 行も無い」「別 run が混ざった」「失敗マーカーが残せた」まで
+- **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない
+
+### 関連
+
+- 実装: `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `config/bughunt.php` /
+  `bootstrap/app.php` / `scripts/bug-hunt-shard.sh` /
+  `.claude/skills/app-bug-hunt/coverage/build_executed.py` /
+  `.claude/skills/app-bug-hunt/coverage/correlate.py`
+- 設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/`

```

## 再検証結果

- `python3 -m unittest test_correlate test_build_executed test_merge_pcov test_naming_no_stale`: 104 tests OK (1 skipped)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `vendor/bin/pest tests/Architecture/BughuntExecutedRouteOrderingTest.php tests/Architecture/BughuntCoverageToolSelfTest.php`: 6 passed
- (`composer test` 全体・pnpm 系はこの後もう一度通し直す)

## 確認してほしいこと

1. Round 1 の [Critical] と 2 件の [Warning]、[Suggestion] が正しく閉じているか
2. 施策 5 の文書 (README / SKILL.md / bughunt-shard.md / AGENTS.md / template-divergence.md) が
   実装と食い違っていないか。特に「保証しないもの」を誇張していないか
3. 文言 gate (test_naming_no_stale) と文書が矛盾していないか (gate が自分の文書で赤くならないか)

全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示すること。
