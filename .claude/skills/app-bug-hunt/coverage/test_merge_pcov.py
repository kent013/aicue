#!/usr/bin/env python3
"""merge_pcov.py のテスト (stdlib unittest, pcov 非依存・fixture のみ)。

実行: python3 -m unittest test_merge_pcov -v
      (このディレクトリ内で)

pcov 実体は不要。入力は C3 出力形の JSON fixture。複数 shard の union を必ず検証する。
"""
from __future__ import annotations

import io
import json
import unittest
from pathlib import Path

import merge_pcov as m

FIX = Path(__file__).parent / "fixtures" / "pcov"
SHARD1 = str(FIX / "20260618-082101-1.json")
SHARD2 = str(FIX / "20260618-082101-2.json")
SHARD3 = str(FIX / "20260618-082101-3.json")
GLOB = str(FIX / "20260618-082101-*.json")

RUC = "app/Http/Controllers/Auth/RegisteredUserController.php"
USER = "app/Models/User.php"
INVITE = "app/Http/Controllers/Org/InviteController.php"


class TestLoadShard(unittest.TestCase):
    def test_jsonl_append_folds_same_file(self):
        # 同一ファイルが複数行で現れる JSONL を 1 FileCov に畳む。
        shard = m.load_shard(SHARD1)
        ruc = shard[RUC]
        # shard1 内: covered {12,13} ∪ {18} = {12,13,18}
        self.assertEqual(ruc.covered, {12, 13, 18})
        # all {12,13,14,15,18,20} ∪ {12,18} = {12,13,14,15,18,20}
        self.assertEqual(ruc.all, {12, 13, 14, 15, 18, 20})

    def test_fully_uncovered_in_single_shard(self):
        shard = m.load_shard(SHARD1)
        self.assertEqual(shard[INVITE].covered, set())
        self.assertEqual(shard[INVITE].all, {30, 31, 32})

    def test_comment_and_blank_lines_skipped(self):
        shard = m.load_shard(SHARD1)
        # コメント行 (#) は record にならない。RUC は 1 つに畳まれる。
        self.assertEqual(set(shard.keys()), {RUC, USER, INVITE})


class TestMergeUnion(unittest.TestCase):
    def test_union_covered_across_shards(self):
        # shard1 covered {12,13,18}, shard2 covered {13,18} → {12,13,18}。
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        self.assertEqual(res.files[RUC].covered, {12, 13, 18})

    def test_union_all_and_uncovered(self):
        # all union → uncovered = all - covered。
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        ruc = res.files[RUC]
        # all = {12,13,14,15,18,20} ∪ {12,13,18,25} = {12,13,14,15,18,20,25}
        self.assertEqual(ruc.all, {12, 13, 14, 15, 18, 20, 25})
        # covered = {12,13,18} → uncovered = {14,15,20,25}
        self.assertEqual(ruc.uncovered, {14, 15, 20, 25})

    def test_user_model_union(self):
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        user = res.files[USER]
        self.assertEqual(user.covered, {5, 6})
        self.assertEqual(user.all, {5, 6, 7, 8})
        self.assertEqual(user.uncovered, {7, 8})

    def test_fully_uncovered_files(self):
        # covered 空のファイルが fully_uncovered_files に入る。
        # shard1 のみだと InviteController は covered 空。
        res = m.merge_shards([SHARD1], run_id="r", only="app/")
        self.assertIn(INVITE, res.fully_uncovered_files)

    def test_fully_uncovered_becomes_covered_after_union(self):
        # shard3 で InviteController の 30 が covered になる → fully_uncovered から外れる。
        res = m.merge_shards([SHARD1, SHARD3], run_id="r", only="app/")
        self.assertNotIn(INVITE, res.fully_uncovered_files)
        self.assertEqual(res.files[INVITE].covered, {30})
        self.assertEqual(res.files[INVITE].uncovered, {31, 32})


class TestOnlyFilter(unittest.TestCase):
    def test_only_app_drops_vendor(self):
        # --only app/ で非 app 行を落とす。
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        self.assertNotIn("vendor/laravel/framework/src/Foo.php", res.files)

    def test_no_only_keeps_vendor(self):
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only=None)
        self.assertIn("vendor/laravel/framework/src/Foo.php", res.files)


class TestSummaryAndRender(unittest.TestCase):
    def test_summary_uncovered_is_primary(self):
        # line_pct は副。summary に出るが uncovered 件数が主。
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        s = m.to_summary(res)
        self.assertIn("uncovered_line_count", s)
        self.assertIn("fully_uncovered_count", s)
        self.assertIn("line_pct", s)
        self.assertGreater(s["uncovered_line_count"], 0)

    def test_line_pct_value(self):
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        # covered total = RUC{12,13,18}=3 + USER{5,6}=2 + INVITE{}=0 = 5
        # all total = RUC 7 + USER 4 + INVITE{30,31,32} 3 = 14
        self.assertEqual(res.total_covered, 5)
        self.assertEqual(res.total_all, 14)
        self.assertAlmostEqual(res.line_pct(), 5 / 14, places=6)

    def test_render_uncovered_heading_first(self):
        # render の主見出しは uncovered (% より先)。
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        out = m.render_uncovered(res)
        idx_uncov = out.index("一度も到達しないファイル")
        idx_pct = out.index("summary")
        self.assertLess(idx_uncov, idx_pct)
        # 「品質」「機能カバレッジ」表現を出さない。
        self.assertNotIn("品質", out)
        self.assertNotIn("機能カバレッジ", out)

    def test_render_verbose_expands_lines(self):
        res = m.merge_shards([SHARD1, SHARD2], run_id="r", only="app/")
        out = m.render_uncovered(res, verbose=True)
        # RUC uncovered {14,15,20,25} のいずれかが行展開される。
        self.assertIn("14", out)
        self.assertIn("25", out)


class TestParseErrors(unittest.TestCase):
    def test_invalid_lines_skipped_and_counted(self):
        # 不正行 (JSON でない/必須キー欠落) を skip し parse_errors に計上。
        res = m.merge_shards([SHARD3], run_id="r", only=None)
        # shard3 の不正行: "this-is-not-json", {covered のみ}, {file 欠落} = 3 件。
        self.assertEqual(len(res.parse_errors), 3)
        # 正常行 (InviteController) は取り込まれる。
        self.assertIn(INVITE, res.files)

    def test_main_returns_zero_with_parse_errors(self):
        # parse error でも exit は warning に留める (0)。
        rc = m.main(["--shard", SHARD3, "--run-id", "r", "--only", "-", "--json"])
        self.assertEqual(rc, 0)


class TestEmptyAndCli(unittest.TestCase):
    def test_empty_shard_set_no_exception(self):
        # 空 shard 集合で例外を出さず空結果。
        res = m.merge_shards([], run_id="r", only="app/")
        self.assertEqual(res.files, {})
        self.assertEqual(res.fully_uncovered_files, [])
        self.assertEqual(res.partial_files, [])
        s = m.to_summary(res)
        self.assertEqual(s["line_pct"], 0.0)
        self.assertEqual(s["uncovered_line_count"], 0)

    def test_glob_expansion_unions_all_shards(self):
        # glob で 3 shard を展開して union。
        res = m.merge_shards(m._expand_shards([GLOB]), run_id="r", only="app/")
        # RUC covered {12,13,18}, InviteController 30 covered (shard3)。
        self.assertEqual(res.files[RUC].covered, {12, 13, 18})
        self.assertEqual(res.files[INVITE].covered, {30})

    def test_main_json_output(self):
        import contextlib

        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = m.main(["--shard", GLOB, "--run-id", "20260618-082101", "--json"])
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["run_id"], "20260618-082101")
        self.assertIn("fully_uncovered_count", data)
        # vendor は --only app/ 既定で除外される。
        self.assertNotIn("vendor", json.dumps(data))

    def test_main_default_only_is_app(self):
        import contextlib

        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            m.main(["--shard", GLOB, "--run-id", "r"])
        out = buf.getvalue()
        self.assertNotIn("vendor/laravel", out)


class TestParseRecordGuards(unittest.TestCase):
    def test_bool_not_accepted_as_line(self):
        with self.assertRaises(ValueError):
            m.parse_record({"file": "app/X.php", "covered": [True], "all": [1]})

    def test_non_list_lines_rejected(self):
        with self.assertRaises(ValueError):
            m.parse_record({"file": "app/X.php", "covered": "1,2", "all": [1]})

    def test_empty_file_rejected(self):
        with self.assertRaises(ValueError):
            m.parse_record({"file": "", "covered": [1], "all": [1]})


class TestRobustness(unittest.TestCase):
    """file-grain only 形 fixture + 壊れ入力 + glob 規律の追加耐性。"""

    def test_directory_match_skipped_no_crash(self):
        # glob がディレクトリにマッチしても open() で死なず除外される。
        import tempfile, os
        with tempfile.TemporaryDirectory() as d:
            subdir = os.path.join(d, "sub-1.json")  # ディレクトリだが json glob にヒットする名前
            os.makedirs(subdir)
            real = os.path.join(d, "real-1.json")
            with open(real, "w") as f:
                f.write(json.dumps({"file": "app/X.php", "covered": [1], "all": [1]}) + "\n")
            paths, unmatched = m._expand_shards_diag([os.path.join(d, "*.json")])
            self.assertIn(real, paths)
            self.assertNotIn(subdir, paths)  # ディレクトリは除外
            res = m.merge_shards(paths, run_id="", only=None)  # クラッシュしない
            self.assertIn("app/X.php", res.files)

    def test_run_id_mismatch_dropped_in_main(self):
        # basename が run-id 接頭辞でない shard は main で除外され dropped に計上。
        import contextlib
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = m.main(["--shard", GLOB, "--run-id", "29991231-235959", "--json"])
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["dropped_other_run_shards"], 3)
        self.assertTrue(data["no_shards_matched"])

    def test_zero_match_pattern_reported(self):
        # 0 件マッチの --shard は unmatched_patterns に出る。
        import contextlib
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            rc = m.main(["--shard", str(FIX / "no-such-run-*.json"),
                         "--run-id", "no-such-run", "--json"])
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["unmatched_patterns"], 1)
        self.assertTrue(data["no_shards_matched"])

    def test_all_equals_covered_flag_on_file_grain_only_form(self):
        # file-grain only 形 (全 file all==covered) を検出しフラグを立てる。
        fc = m.FileCov(file="app/X.php", covered={1, 2, 3}, all={1, 2, 3})
        res = m.MergeResult(run_id="r", files={"app/X.php": fc})
        self.assertTrue(res.all_equals_covered)
        s = m.to_summary(res)
        self.assertTrue(s["all_equals_covered_file_grain_only"])
        self.assertEqual(s["line_pct"], 1.0)  # アーティファクト 100%
        self.assertEqual(s["uncovered_line_count"], 0)
        out = m.render_uncovered(res)
        self.assertIn("アーティファクト", out)  # 注記が出る

    def test_all_equals_covered_false_on_partial(self):
        res = m.merge_shards([SHARD1, SHARD2], run_id="20260618-082101", only="app/")
        self.assertFalse(res.all_equals_covered)  # fixture は all!=covered (合成)


if __name__ == "__main__":
    unittest.main()
