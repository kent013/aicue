#!/usr/bin/env python3
"""build_executed.py の単体テスト (stdlib unittest)。

入出力は tempfile で作る。**主入力が揃わない走行を成功にしない**ことが本モジュールの契約なので、
負の対照 (終了コード 3) を理由コードごとに 1 本ずつ置く。

実行: python3 -m unittest test_build_executed -v
"""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

import build_executed as B
import correlate as C

RUN = "20260618-082101"


def row(shard: str, name: str | None, status: str = "ok", http: int = 200,
        run_id: str = RUN, method: str = "GET") -> dict:
    return {
        "run_id": run_id,
        "shard": shard,
        "route_name": name,
        "method": method,
        "path": "/x",
        "status": status,
        "http_status": http,
    }


class BuildExecutedTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.input_dir = self.base / "capture"
        self.input_dir.mkdir()
        self.out = self.base / "executed.json"

    # ------------------------------------------------------------------ #
    # helpers
    # ------------------------------------------------------------------ #
    def write_jsonl(self, shard: str, rows: list[dict], *, raw: str | None = None) -> None:
        path = self.input_dir / f"{RUN}-{shard}.jsonl"
        if raw is not None:
            path.write_text(raw, encoding="utf-8")
            return
        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows),
                        encoding="utf-8")

    def run_main(self, shards: list[str]) -> int:
        args = ["--run-id", RUN, "--input-dir", str(self.input_dir), "--out", str(self.out)]
        for s in shards:
            args += ["--shard", s]
        return B.main(args)

    def loaded_out(self) -> dict:
        return json.loads(self.out.read_text(encoding="utf-8"))

    # ------------------------------------------------------------------ #
    # 正の対照
    # ------------------------------------------------------------------ #
    def test_two_shards_folded(self):
        self.write_jsonl("0", [
            row("0", "projects.store", "ok", 302),
            row("0", "projects.store", "ok", 302),   # 同一キーは畳まれる
            row("0", "projects.store", "ok", 200),   # http_statuses は集合
            row("0", "projects.update", "blocked", 403),
        ])
        self.write_jsonl("1", [row("1", "login.store", "ok", 302)])

        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)
        data = self.loaded_out()
        self.assertEqual(data["run_id"], RUN)
        self.assertEqual(data["shards"], ["0", "1"])
        self.assertEqual(data["executed_routes"], [
            {"route_name": "login.store", "shard": "1", "status": "ok", "http_statuses": [302]},
            {"route_name": "projects.store", "shard": "0", "status": "ok", "http_statuses": [200, 302]},
            {"route_name": "projects.update", "shard": "0", "status": "blocked", "http_statuses": [403]},
        ])
        self.assertEqual(data["unresolved"], {})

    def test_unresolved_rows_are_counted_not_listed(self):
        self.write_jsonl("0", [
            row("0", "dashboard", "ok", 200),
            row("0", None, "ok", 200),
            row("0", None, "blocked", 500),
        ])
        self.assertEqual(self.run_main(["0"]), B.EXIT_OK)
        data = self.loaded_out()
        self.assertEqual([r["route_name"] for r in data["executed_routes"]], ["dashboard"])
        self.assertEqual(data["unresolved"], {"0": 2})

    def test_output_is_valid_input_for_correlate(self):
        # 生成器と照合器の契約が食い違っていないことを 1 本で固定する。
        self.write_jsonl("0", [row("0", "dashboard", "ok", 200)])
        self.write_jsonl("1", [row("1", "login.store", "blocked", 302)])
        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)

        ex = C.load_executed(str(self.out))
        self.assertIsNone(C.validate_executed(ex, RUN))
        self.assertTrue(ex.is_executed("dashboard"))
        self.assertFalse(ex.is_executed("login.store"))

    # ------------------------------------------------------------------ #
    # 負の対照 (終了コード 3)
    # ------------------------------------------------------------------ #
    def test_missing_capture_file_returns_3(self):
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())

    def test_failure_marker_returns_3(self):
        self.write_jsonl("0", [row("0", "dashboard")])
        (self.input_dir / f"{RUN}-0.error").write_text("disk full\n", encoding="utf-8")
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())

    def test_broken_line_returns_3(self):
        self.write_jsonl("0", [], raw='{"run_id": "x"\n')
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())

    def test_other_run_row_returns_3(self):
        self.write_jsonl("0", [
            row("0", "dashboard"),
            row("0", "login.store", run_id="20260101-000000"),
        ])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())

    def test_named_rows_absent_returns_3(self):
        # route_name: null の行しか無い shard もここで落ちる。
        self.write_jsonl("0", [row("0", None)])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())

    def test_empty_file_returns_3(self):
        self.write_jsonl("0", [], raw="")
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_unknown_status_returns_3(self):
        self.write_jsonl("0", [row("0", "dashboard", status="whatever")])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_non_string_status_returns_3(self):
        # dict / list を集合照合へ渡すと TypeError: unhashable type になる。
        # **traceback ではなく終了コード 3** で落ちることを固定する。
        for bad in [{}, [], 0, None]:
            with self.subTest(status=bad):
                r = row("0", "dashboard")
                r["status"] = bad
                self.write_jsonl("0", [r])
                self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_shard_mismatch_in_row_returns_3(self):
        # ファイル名は shard 0 なのに中身が shard 1 を名乗る
        self.write_jsonl("0", [row("1", "dashboard")])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_non_int_http_status_returns_3(self):
        for bad in ["200", True, None]:
            with self.subTest(http_status=bad):
                r = row("0", "dashboard")
                r["http_status"] = bad
                self.write_jsonl("0", [r])
                self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_empty_route_name_returns_3(self):
        r = row("0", "dashboard")
        r["route_name"] = ""
        self.write_jsonl("0", [r])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_missing_method_returns_3(self):
        r = row("0", "dashboard")
        del r["method"]
        self.write_jsonl("0", [r])
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_row_not_object_returns_3(self):
        self.write_jsonl("0", [], raw='["not", "an", "object"]\n')
        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)

    def test_failure_does_not_overwrite_existing_out(self):
        self.out.write_text('{"keep": "me"}', encoding="utf-8")
        self.write_jsonl("0", [row("0", "dashboard")])
        (self.input_dir / f"{RUN}-0.error").write_text("boom\n", encoding="utf-8")

        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertEqual(self.out.read_text(encoding="utf-8"), '{"keep": "me"}')

    def test_one_bad_shard_fails_the_whole_run(self):
        # 揃っている shard があっても、揃わない shard が 1 つあれば成功にしない。
        self.write_jsonl("0", [row("0", "dashboard")])
        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_INPUT_UNAVAILABLE)
        self.assertFalse(self.out.exists())


if __name__ == "__main__":
    unittest.main()
