#!/usr/bin/env python3
"""correlate.py の単体テスト (stdlib unittest)。

graph.db はテスト用 temp sqlite を実 DB のスキーマ(edges.kind/source_qualified)で
都度生成する (バイナリ commit は避ける)。pcov 非依存。

実行: python3 -m unittest test_correlate -v
"""
from __future__ import annotations

import contextlib
import io
import json
import os
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path

import correlate as C

# fix-gate #3/#4 検証用: 実 operations.md と実 graph.db。存在時のみ走らせる。
_SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/
REAL_OPERATIONS = _SKILL_ROOT / "operations.md"
REAL_GRAPH_DB = Path("/workspace/.code-review-graph/graph.db")
_REPO_ROOT = _SKILL_ROOT.parent.parent.parent  # リポジトリルート (worktree でも自 worktree を指す)
ARTISAN = _REPO_ROOT / "artisan"


# --------------------------------------------------------------------------- #
# helpers: temp graph.db を実スキーマで作る
# --------------------------------------------------------------------------- #
def make_graph_db(path: str, tested_by: list[tuple[str, str]]) -> None:
    """tested_by = [(source_qualified, target_qualified), ...] を TESTED_BY edge で投入。

    source_qualified は '/workspace/<file>::Symbol' 形式 (実 DB と同じ)。
    """
    conn = sqlite3.connect(path)
    conn.execute(
        "CREATE TABLE edges (id INTEGER PRIMARY KEY, kind TEXT, "
        "source_qualified TEXT, target_qualified TEXT, file_path TEXT, line INTEGER, "
        "extra TEXT, confidence REAL, confidence_tier TEXT, updated_at TEXT)"
    )
    for src, tgt in tested_by:
        conn.execute(
            "INSERT INTO edges (kind, source_qualified, target_qualified) VALUES (?,?,?)",
            ("TESTED_BY", src, tgt),
        )
    conn.commit()
    conn.close()


class ActionToFileTest(unittest.TestCase):
    def test_method(self):
        self.assertEqual(
            C.action_to_file("App\\Http\\Controllers\\Auth\\RegisteredUserController@store"),
            "app/Http/Controllers/Auth/RegisteredUserController.php",
        )

    def test_invoke_no_method(self):
        # @ 無しの単一 action (__invoke 相当) も FQCN からファイル化
        self.assertEqual(
            C.action_to_file("App\\Http\\Controllers\\HomeController"),
            "app/Http/Controllers/HomeController.php",
        )

    def test_closure(self):
        self.assertIsNone(C.action_to_file("Closure"))

    def test_null(self):
        self.assertIsNone(C.action_to_file(None))

    def test_deep_namespace(self):
        self.assertEqual(
            C.action_to_file("App\\Http\\Controllers\\Org\\Teams\\ProjectController@update"),
            "app/Http/Controllers/Org/Teams/ProjectController.php",
        )

    def test_vendor_namespace_unmapped(self):
        # 非 App namespace (Filament 等) は join 不能
        self.assertIsNone(C.action_to_file("Filament\\Http\\Controllers\\RedirectToHome@__invoke"))


class LoadOperationsTest(unittest.TestCase):
    """fix-gate #3: app operations.md は 5 列。join キー = name 列 (index 2)。"""

    def _write(self, body: str) -> str:
        d = Path(self.tmp.name) / "operations.md"
        d.write_text(body, encoding="utf-8")
        return str(d)

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def test_five_column_uses_name_not_route_url(self):
        # 5 列: method | route(URL) | name | story | 区分。
        # join キーは name 列であり route(URL) 列ではない (fix-gate #3 の核心)。
        path = self._write(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | register | register.store | S1 | ◎ |\n"
            "| POST | organizations/{organization}/transfer | organizations.transfer | S4 | 逸 |\n"
            "| POST | billing/change-plan | billing.changePlan | S5 | 外 (UI 未参照) |\n"
            "| DELETE | settings/account | settings.deleteAccount | S6 | 終 |\n"
        )
        ops = C.load_operations(path)
        # name 列の route 名が拾われる。
        self.assertIn("register.store", ops)
        self.assertEqual(ops["register.store"]["kubun"], "◎")
        self.assertEqual(ops["register.store"]["story"], "S1")
        self.assertEqual(ops["organizations.transfer"]["kubun"], "逸")
        self.assertEqual(ops["billing.changePlan"]["kubun"], "外")
        self.assertEqual(ops["settings.deleteAccount"]["kubun"], "終")
        # URL 列 (route) は join キーにならない。
        self.assertNotIn("register", ops)
        self.assertNotIn("settings/account", ops)

    def test_six_column_s8_api_section(self):
        # S8 の API/CLI 面は 6 列: method | route | api route name | CLI | story | 区分。
        # name 列ヘッダ 'api route name' を動的に検出し backtick を剥がす。
        path = self._write(
            "| method | route | api route name | CLI コマンド | story | 区分 |\n"
            "|---|---|---|---|---|---|\n"
            "| POST | /api/v1/projects | `api.v1.projects.store` | `project:create` | S8 | ◎ |\n"
            "| DELETE | /me/session | `api.v1.me.session.revoke` | `logout` | S8 | ○ |\n"
        )
        ops = C.load_operations(path)
        self.assertIn("api.v1.projects.store", ops)
        self.assertEqual(ops["api.v1.projects.store"]["story"], "S8")
        self.assertEqual(ops["api.v1.projects.store"]["kubun"], "◎")
        self.assertIn("api.v1.me.session.revoke", ops)
        self.assertEqual(ops["api.v1.me.session.revoke"]["kubun"], "○")

    def test_mixed_5_and_6_column_sections(self):
        # 5 列節と 6 列節が同一ファイルに混在しても各節のヘッダで列を判定する。
        path = self._write(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | login | login.store | S1 | ◎ |\n"
            "\n## API\n\n"
            "| method | route | api route name | CLI コマンド | story | 区分 |\n"
            "|---|---|---|---|---|---|\n"
            "| POST | /api/v1/projects | `api.v1.projects.store` | `project:create` | S8 | ◎ |\n"
        )
        ops = C.load_operations(path)
        self.assertIn("login.store", ops)
        self.assertIn("api.v1.projects.store", ops)
        # 誤って URL 列を拾っていないこと。
        self.assertNotIn("login", ops)
        self.assertNotIn("projects", ops)

    def test_multi_route_cell_and_footnote(self):
        path = self._write(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | login | login.store / logout | S1 | ◎ |\n"
            "| PUT | x | organizations.members.update-{billing,api-key}-permission | S4 | ○ |\n"
            "| POST | y | recent-auth.password (注: step-up 再認証) | S6 | ◎ |\n"
        )
        ops = C.load_operations(path)
        self.assertIn("login.store", ops)
        self.assertIn("logout", ops)
        self.assertIn("organizations.members.update-billing-permission", ops)
        self.assertIn("organizations.members.update-api-key-permission", ops)
        self.assertIn("recent-auth.password", ops)  # 脚注除去

    # 生成器 (scripts/bug-hunt-inventory.py) が書く 5 列固定ヘッダ (operations.md の契約)。
    # オラクルのヘッダ認識に C._header_indices() を使わない — 実装をオラクルにすると
    # ヘッダ検出の退行時に期待値と実値が同時に 0 件になり共倒れする。
    _REAL_OPERATIONS_HEADER = ["method", "route", "name", "story", "区分"]

    @unittest.skipUnless(REAL_OPERATIONS.is_file(), "real operations.md not present")
    def test_real_operations_md_name_column_join_keys(self):
        """実 operations.md の join キー集合を独立オラクルとの完全一致で固定する。

        オラクル: 生成器が書く 5 列固定ヘッダ `| method | route | name | story | 区分 |`
        の厳密一致で表の内部を判定し、データ行の第 3 列 (name) を _parse_route_cell で
        分解して期待キー集合を作る (検証対象は**列選択とヘッダ認識**。セル分解の検出力は
        合成テスト群が担う)。集合の完全一致は対称なので、load_operations 側の
        ヘッダ認識・列選択が壊れても、生成器がヘッダを変えても、どちらでも赤になる。

        「全行一致 = 列の取り違え」の集約形 (正典 t2) は補助として併置する。
        単一セグメント route では URL と route 名が正当に一致しうるため、
        行単位の不一致 assert は使わない (誤検知するため)。

        前提: aicue の operations.md は生成物で、route 操作の 5 列表だけを含む
        (ファイル冒頭に「5 列固定 (coverage/correlate.py の入力契約)」と明記されている)。
        6 列表など別形の表が正当に足されたときは本検査が集合不一致の赤になる —
        静かな見逃しではなく前提の見直しを促す赤であり、そのときはオラクルを
        生成物の実態に合わせて更新する。スケルトン (データ行 0) のときだけ静かに通る。
        """
        expected: set[str] = set()
        candidate_lines = 0  # 表らしい行の存在をヘッダ認識と独立に数える (共倒れ防止)
        in_table = False
        for raw in REAL_OPERATIONS.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line.startswith("|"):
                in_table = False  # 表は先頭パイプ行の連続。非パイプ行で表を抜ける
                continue
            if "---" in line:
                continue
            cols = [c.strip() for c in line.strip("|").split("|")]
            if [c.lower() for c in cols] == self._REAL_OPERATIONS_HEADER:
                in_table = True
                continue
            # 認識できないヘッダの行も含め、空でない表らしい行を独立に記録する。
            candidate_lines += 1
            if not in_table or len(cols) < 3:
                continue
            expected.update(C._parse_route_cell(cols[2]))

        ops = C.load_operations(str(REAL_OPERATIONS))
        if candidate_lines == 0:
            # 真のスケルトン (表らしい行が 1 行も無い) だけが静かに通る。
            self.assertEqual(ops, {}, "表らしい行が 0 なのに join キーが出ている")
            return

        # ヘッダ契約の消滅をスケルトンと誤認しない: 行があるのにオラクルが
        # ヘッダを認識できないなら、実装側の認識と共倒れせずここで赤にする。
        self.assertGreater(
            len(expected), 0,
            f"パイプ形式の行が {candidate_lines} 行あるのに 5 列固定ヘッダを認識できない "
            "(生成物のヘッダ契約が変わった — オラクルと前提の見直しが要る)",
        )
        self.assertEqual(
            set(ops), expected,
            "load_operations の join キーが name 列 (5 列固定契約の第 3 列) と食い違う "
            "(列の取り違え・ヘッダ認識の退行・生成物の契約変更のいずれか)",
        )
        for name in ops:
            self.assertTrue(name.strip(), "空の join キーが混入している")

        # 補助 (正典 t2 の集約形): 「全行が一致 = 列の取り違え」だけを落とし、
        # 正例 (不一致行) 1 件以上を要求する。
        mismatched = [name for name, info in ops.items() if name != info.get("operation")]
        self.assertGreater(
            len(mismatched), 0,
            "全 {} 行で join キーが URL 列と一致 = name 列でなく URL 列を拾っている "
            "(fix-gate #3 違反)".format(len(ops)),
        )


class TestedByIndexTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.db = str(Path(self.tmp.name) / "graph.db")

    def test_php_zero_means_unknown_gap(self):
        # 実環境再現: TESTED_BY は全て TS。PHP は 0 件。
        make_graph_db(self.db, [
            ("/workspace/resources/js/lib/foo.ts::foo",
             "/workspace/resources/js/lib/foo.test.ts::t"),
        ])
        idx = C.tested_by_index(self.db)
        self.assertFalse(idx.php_has_any)
        self.assertEqual(
            idx.status_for("app/Http/Controllers/Auth/RegisteredUserController.php"),
            C.UNKNOWN_GAP,
        )

    def test_php_tested_and_untested(self):
        # 仮に PHP TESTED_BY があるケース (将来の graph 対応)
        make_graph_db(self.db, [
            ("/workspace/app/Http/Controllers/Auth/RegisteredUserController.php::store",
             "/workspace/tests/Feature/Auth/RegisterTest.php::t"),
        ])
        idx = C.tested_by_index(self.db)
        self.assertTrue(idx.php_has_any)
        self.assertEqual(
            idx.status_for("app/Http/Controllers/Auth/RegisteredUserController.php"),
            C.TESTED,
        )
        self.assertEqual(
            idx.status_for("app/Http/Controllers/Other/UntestedController.php"),
            C.UNTESTED,
        )

    def test_none_controller_is_gap(self):
        make_graph_db(self.db, [
            ("/workspace/app/X.php::a", "/workspace/tests/X.php::t"),
        ])
        idx = C.tested_by_index(self.db)
        self.assertEqual(idx.status_for(None), C.UNKNOWN_GAP)

    @unittest.skipUnless(REAL_GRAPH_DB.is_file(), "real graph.db not present")
    def test_real_graph_db_php_is_zero_unknown_gap(self):
        # fix-gate #4: 実測 (2026-06-20) TESTED_BY=15787 全て TS、PHP=0。
        # よって PHP web route は全件 unknown_graph_gap に落ちる、が成立する。
        # (3703 等の stale 値は assert しない。PHP=0 という load-bearing claim のみ pin。)
        idx = C.tested_by_index(str(REAL_GRAPH_DB))
        self.assertFalse(idx.php_has_any, "実 graph.db の PHP TESTED_BY は 0 のはず")
        self.assertEqual(
            idx.status_for("app/Http/Controllers/Auth/RegisteredUserController.php"),
            C.UNKNOWN_GAP,
        )


# --------------------------------------------------------------------------- #
# correlate end-to-end (fixture inputs)
# --------------------------------------------------------------------------- #
class CorrelateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.run_id = "20260618-test"
        self.routes = [
            {"name": "register.store", "method": "POST", "uri": "register",
             "action": "App\\Http\\Controllers\\Auth\\RegisteredUserController@store"},
            {"name": "login.store", "method": "POST", "uri": "login",
             "action": "App\\Http\\Controllers\\Auth\\AuthenticatedSessionController@store"},
            {"name": "organizations.store", "method": "POST", "uri": "organizations",
             "action": "App\\Http\\Controllers\\Org\\OrganizationController@store"},
            {"name": "home", "method": "GET", "uri": "/", "action": "Closure"},
        ]
        self.operations = {
            "register.store": {"operation": "register", "story": "S1", "kubun": "◎"},
            "login.store": {"operation": "login", "story": "S1", "kubun": "◎"},
            "organizations.store": {"operation": "organizations", "story": "S1", "kubun": "◎"},
            "organizations.transfer": {"operation": "transfer", "story": "S4", "kubun": "逸"},
            "billing.changePlan": {"operation": "change-plan", "story": "S5", "kubun": "外"},
        }
        # graph: PHP 0 件 (= unknown_graph_gap), 実環境再現
        self.db = str(Path(self.tmp.name) / "graph.db")
        make_graph_db(self.db, [
            ("/workspace/resources/js/lib/x.ts::x", "/workspace/resources/js/lib/x.test.ts::t"),
        ])
        self.tb = C.tested_by_index(self.db)

    def _executed(self, routes_executed):
        # status は生成器が必ず付ける (ok|blocked の 2 値)。ここでは実走 = ok を組む。
        ex = C.Executed(run_id=self.run_id, shards=["0", "1"])
        for name, shard in routes_executed:
            ex.row_count += 1
            ex.routes.setdefault(name, set()).add(shard)
            ex.statuses.setdefault(name, set()).add("ok")
        return ex

    def test_unexecuted_excludes_deviate_and_out_of_scope(self):
        ex = self._executed([("register.store", "0")])
        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                           run_id=self.run_id)
        names = {r.route_name for r in corr.unexecuted}
        # login.store, organizations.store は未実行 in_scope
        self.assertIn("login.store", names)
        self.assertIn("organizations.store", names)
        # register.store は executed
        self.assertNotIn("register.store", names)
        # 逸 / 外 は除外
        self.assertNotIn("organizations.transfer", names)
        self.assertNotIn("billing.changePlan", names)

    def test_union_across_shards(self):
        ex = self._executed([("login.store", "1")])
        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                           run_id=self.run_id)
        names = {r.route_name for r in corr.unexecuted}
        self.assertNotIn("login.store", names)  # shard1 で executed

    def test_tested_by_three_values_php_is_gap(self):
        ex = self._executed([])
        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                           run_id=self.run_id)
        # 全 PHP route は unknown_graph_gap (php_has_any False)
        self.assertEqual(len(corr.untested_real), 0)
        self.assertGreater(corr.unknown_graph_gap_count, 0)

    def test_finding_hotspot_threshold_boundary(self):
        findings = [
            {"finding_id": "F-1", "run_id": self.run_id, "route_name": "register.store",
             "species_key": "a", "severity": "high"},
        ]
        ex = self._executed([("register.store", "0"), ("login.store", "0"),
                             ("organizations.store", "0")])
        corr = C.correlate(self.routes, self.operations, ex, findings, self.tb,
                           run_id=self.run_id, hotspot_threshold=2)
        # 1 件では hotspot に出ない
        self.assertEqual(len(corr.finding_hotspots), 0)
        # 2 件 (異なる species) で出る
        findings.append({"finding_id": "F-2", "run_id": self.run_id,
                         "route_name": "register.store", "species_key": "b",
                         "severity": "critical"})
        corr = C.correlate(self.routes, self.operations, ex, findings, self.tb,
                           run_id=self.run_id, hotspot_threshold=2)
        self.assertEqual(len(corr.finding_hotspots), 1)
        self.assertEqual(corr.finding_hotspots[0].route_name, "register.store")

    def test_species_key_dedup(self):
        # 同一 species_key の finding は二重計上しない
        findings = [
            {"finding_id": "F-1", "run_id": self.run_id, "route_name": "register.store",
             "species_key": "same", "severity": "high"},
            {"finding_id": "F-2", "run_id": self.run_id, "route_name": "register.store",
             "species_key": "same", "severity": "high"},
        ]
        ex = self._executed([])
        corr = C.correlate(self.routes, self.operations, ex, findings, self.tb,
                           run_id=self.run_id, hotspot_threshold=2)
        reg = next(r for r in corr.rows if r.route_name == "register.store")
        self.assertEqual(reg.finding_count, 1)

    def test_capability_broadcast(self):
        # route 名を持たない finding は story 一致機構へ capability 経由でブロードキャスト
        findings = [
            {"finding_id": "F-1", "run_id": self.run_id, "story_id": "S1",
             "capability_tag": "AUTH-03", "species_key": "x", "severity": "high"},
            {"finding_id": "F-2", "run_id": self.run_id, "story_id": "S1",
             "capability_tag": "AUTH-03", "species_key": "y", "severity": "medium"},
        ]
        ex = self._executed([])
        corr = C.correlate(self.routes, self.operations, ex, findings, self.tb,
                           run_id=self.run_id, hotspot_threshold=2)
        s1_rows = [r for r in corr.rows if r.story == "S1"]
        # S1 の各機構に 2 finding がブロードキャストされ via_capability が立つ
        for r in s1_rows:
            self.assertEqual(r.finding_count, 2)
            self.assertTrue(r.via_capability)
            self.assertIn("AUTH-03", r.capability_tags)

    def test_複数値行は両方のstoryへブロードキャストされる(self):
        operations = dict(self.operations)
        operations["organizations.store"] = {
            "operation": "organizations", "story": "S1 S4", "kubun": "◎",
        }
        findings = [
            {"finding_id": "F-1", "run_id": self.run_id, "story_id": "S4",
             "capability_tag": "ORG-04", "species_key": "x", "severity": "high"},
        ]
        corr = C.correlate(self.routes, operations, self._executed([]), findings, self.tb,
                           run_id=self.run_id)
        row = next(r for r in corr.rows if r.route_name == "organizations.store")
        self.assertEqual(1, row.finding_count)
        self.assertTrue(row.via_capability)
        # 単一値の S4 機構にも同じ finding が届く (従来の挙動が変わっていない)。
        transfer = next(r for r in corr.rows if r.route_name == "organizations.transfer")
        self.assertEqual(1, transfer.finding_count)
        # S1 の finding も複数値行へ届く。
        s1 = [{"finding_id": "F-2", "run_id": self.run_id, "story_id": "S1",
               "capability_tag": "AUTH-03", "species_key": "y", "severity": "low"}]
        corr = C.correlate(self.routes, operations, self._executed([]), s1, self.tb,
                           run_id=self.run_id)
        row = next(r for r in corr.rows if r.route_name == "organizations.store")
        self.assertEqual(1, row.finding_count)

    def test_契約外の割当セルを持つ目録は走行を止める(self):
        operations = dict(self.operations)
        operations["login.store"] = {"operation": "login", "story": "S1  S4", "kubun": "◎"}
        with self.assertRaises(C.FatalError):
            C.correlate(self.routes, operations, self._executed([]), [], self.tb,
                        run_id=self.run_id)

    def test_cross_unexec_findingful(self):
        # 未実行 ∧ finding≥2 の積集合
        findings = [
            {"finding_id": "F-1", "run_id": self.run_id, "route_name": "login.store",
             "species_key": "a", "severity": "high"},
            {"finding_id": "F-2", "run_id": self.run_id, "route_name": "login.store",
             "species_key": "b", "severity": "critical"},
        ]
        ex = self._executed([("register.store", "0"), ("organizations.store", "0")])
        # login.store は未実行 + finding 2 件 -> cross
        corr = C.correlate(self.routes, self.operations, ex, findings, self.tb,
                           run_id=self.run_id, hotspot_threshold=2)
        cross_names = {r.route_name for r in corr.cross_unexec_findingful}
        self.assertEqual(cross_names, {"login.store"})

    def test_blocked_status_not_executed(self):
        # status=blocked の route は executed=false で未実行 worklist に残る。
        ex = C.Executed(run_id=self.run_id, shards=["0"])
        ex.routes.setdefault("login.store", set()).add("0")
        ex.statuses.setdefault("login.store", set()).add("blocked")
        ex.routes.setdefault("register.store", set()).add("0")
        ex.statuses.setdefault("register.store", set()).add("ok")
        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                           run_id=self.run_id)
        names = {r.route_name for r in corr.unexecuted}
        self.assertIn("login.store", names)       # blocked = 未実走扱い
        self.assertNotIn("register.store", names)  # ok = 実走
        self.assertEqual(corr.blocked_count, 1)

    def test_row_without_status_is_rejected(self):
        # 旧「status 未記録なら ok とみなす」救済は無い。status 欠落行は
        # load_executed が契約違反として弾き、集計に載らない。
        path = Path(self.tmp.name) / "no-status.json"
        path.write_text(json.dumps({
            "run_id": self.run_id,
            "shards": ["0"],
            "executed_routes": [{"route_name": "login.store", "shard": "0"}],
        }), encoding="utf-8")
        ex = C.load_executed(str(path))
        self.assertIsNotNone(ex.schema_error)
        self.assertFalse(ex.is_executed("login.store"))
        self.assertIn("executed_schema_invalid", C.validate_executed(ex, self.run_id) or "")

    def test_summary_unexecuted_count_is_primary(self):
        ex = self._executed([("register.store", "0")])
        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                           run_id=self.run_id)
        s = C.to_summary(corr)
        self.assertGreater(s["unexecuted_count"], 0)
        self.assertIn("executed_pct", s)  # % は副フィールドとして存在
        self.assertEqual(s["unexecuted_count"], len(corr.unexecuted))


class LoadFindingsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def _write(self, lines: list[str]) -> str:
        p = Path(self.tmp.name) / "findings.jsonl"
        p.write_text("\n".join(lines) + "\n", encoding="utf-8")
        return str(p)

    def test_run_id_filter_and_dropped(self):
        path = self._write([
            '{"finding_id":"F-1","run_id":"R1","species_key":"a"}',
            '{"finding_id":"F-2","run_id":"R2","species_key":"b"}',
            '# comment',
            '',
            '{"finding_id":"F-3","run_id":"R1","species_key":"c"}',
        ])
        findings, dropped = C.load_findings(path, "R1")
        self.assertEqual({f["finding_id"] for f in findings}, {"F-1", "F-3"})
        self.assertEqual(dropped, 1)

    def test_parse_error_raises(self):
        path = self._write(['{"bad json'])
        with self.assertRaises(ValueError):
            C.load_findings(path, None)

    def test_glob_expands_multiple_shards(self):
        # glob で複数 shard の findings.jsonl を連結読込する。
        base = Path(self.tmp.name)
        (base / "shard-1").mkdir()
        (base / "shard-2").mkdir()
        (base / "shard-1" / "findings.jsonl").write_text(
            '{"finding_id":"F-1","run_id":"R1","species_key":"a"}\n', encoding="utf-8")
        (base / "shard-2" / "findings.jsonl").write_text(
            '{"finding_id":"F-2","run_id":"R1","species_key":"b"}\n', encoding="utf-8")
        findings, dropped = C.load_findings(str(base / "shard-*" / "findings.jsonl"), "R1")
        self.assertEqual({f["finding_id"] for f in findings}, {"F-1", "F-2"})

    def test_glob_zero_match_raises(self):
        # 1 件もマッチしない glob は FileNotFoundError (OSError) を投げる。
        with self.assertRaises(FileNotFoundError):
            C.load_findings(str(Path(self.tmp.name) / "no-such-*" / "findings.jsonl"), "R1")


class MainTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name)
        self.route_path = base / "route.json"
        self.route_path.write_text(
            '[{"name":"register.store","method":"POST","uri":"register",'
            '"action":"App\\\\Http\\\\Controllers\\\\Auth\\\\RegisteredUserController@store"}]',
            encoding="utf-8",
        )
        self.ops_path = base / "operations.md"
        # app 5 列形 (fix-gate #3)
        self.ops_path.write_text(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | register | register.store | S1 | ◎ |\n",
            encoding="utf-8",
        )
        self.findings_path = base / "findings.jsonl"
        self.findings_path.write_text(
            '{"finding_id":"F-1","run_id":"R1","route_name":"register.store","species_key":"a"}\n',
            encoding="utf-8",
        )
        self.executed_path = base / "executed.json"
        # 主入力は「有効な観測行を 1 件以上持つ」ことが成立条件 (fail-closed 契約)。
        self.executed_path.write_text(json.dumps({
            "run_id": "R1", "shards": ["0"],
            "executed_routes": [
                {"route_name": "register.store", "shard": "0", "status": "ok",
                 "http_statuses": [302]},
            ],
        }), encoding="utf-8")
        self.db = str(base / "graph.db")
        make_graph_db(self.db, [("/workspace/resources/js/x.ts::x", "/workspace/resources/js/t.ts::t")])

    def _args(self, extra=None):
        a = [
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(self.findings_path),
            "--executed", str(self.executed_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ]
        return a + (extra or [])

    def test_main_ok_markdown(self):
        rc = C.main(self._args())
        self.assertEqual(rc, 0)

    def test_main_ok_json(self):
        rc = C.main(self._args(["--json"]))
        self.assertEqual(rc, 0)

    def test_main_parse_error_returns_1(self):
        bad = Path(self.tmp.name) / "bad.jsonl"
        bad.write_text("{not json\n", encoding="utf-8")
        rc = C.main([
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(bad),
            "--executed", str(self.executed_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ])
        self.assertEqual(rc, 1)

    def test_main_empty_findings_no_exception(self):
        empty = Path(self.tmp.name) / "empty.jsonl"
        empty.write_text("", encoding="utf-8")
        rc = C.main([
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(empty),
            "--executed", str(self.executed_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ])
        self.assertEqual(rc, 0)

    def test_main_contract_violating_story_cell_returns_3(self):
        """契約外の割当セルを持つ目録で main() が 3 を返し worklist を出さないこと。

        ★ `parse_story_cell()` / `correlate()` が FatalError を投げることだけを見ても、
          **main() の捕捉と終了コードへの写像**は裏取りできない (catch を壊しても緑になる)。
        """
        import contextlib
        import io

        # 前後空白だけは表ローダが strip するのでここには到達しない
        # (その形は parse_story_cell() の単体検査が押さえる)。
        for cell in ("S1  S4", "S1,S4", "S0", "S4 S1", "S1 S1"):
            with self.subTest(cell=cell):
                self.ops_path.write_text(
                    "| method | route | name | story | 区分 |\n"
                    "|---|---|---|---|---|\n"
                    f"| POST | register | register.store | {cell} | ◎ |\n",
                    encoding="utf-8",
                )
                out, err = io.StringIO(), io.StringIO()
                with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                    rc = C.main(self._args())
                self.assertEqual(C.EXIT_INPUT_UNAVAILABLE, rc, out.getvalue() + err.getvalue())
                self.assertIn("契約に反している", err.getvalue())
                # worklist を 1 行も出さない。
                self.assertEqual("", out.getvalue())

    def test_main_multi_value_story_cell_is_accepted(self):
        """正の対照: 契約どおりの複数値セルは 0 で通ること (値域を狭めすぎていない)。"""
        self.ops_path.write_text(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | register | register.store | S1 S4 | ◎ |\n",
            encoding="utf-8",
        )
        self.assertEqual(C.EXIT_OK, C.main(self._args(["--json"])))

    # ------------------------------------------------------------------ #
    # fail-closed 契約: 主入力が揃わない走行は成功にしない (終了コード 3)
    # ------------------------------------------------------------------ #
    def _write_executed(self, payload) -> str:
        path = Path(self.tmp.name) / "custom-executed.json"
        path.write_text(json.dumps(payload) if not isinstance(payload, str) else payload,
                        encoding="utf-8")
        return str(path)

    def _main_with_executed(self, payload) -> int:
        return C.main([
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(self.findings_path),
            "--executed", self._write_executed(payload),
            "--graph-db", self.db,
            "--run-id", "R1",
        ])

    def test_main_missing_executed_returns_3(self):
        rc = C.main([
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(self.findings_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ])
        self.assertEqual(rc, C.EXIT_INPUT_UNAVAILABLE)

    def test_main_run_id_mismatch_returns_3(self):
        self.assertEqual(self._main_with_executed({
            "run_id": "OTHER", "shards": ["0"],
            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
        }), C.EXIT_INPUT_UNAVAILABLE)

    def test_main_empty_executed_returns_3(self):
        self.assertEqual(self._main_with_executed({
            "run_id": "R1", "shards": ["0"], "executed_routes": [],
        }), C.EXIT_INPUT_UNAVAILABLE)

    def test_main_shard_mismatch_returns_3(self):
        self.assertEqual(self._main_with_executed({
            "run_id": "R1", "shards": ["0", "1"],
            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
        }), C.EXIT_INPUT_UNAVAILABLE)

    def test_main_shards_missing_returns_3(self):
        self.assertEqual(self._main_with_executed({
            "run_id": "R1", "shards": [],
            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
        }), C.EXIT_INPUT_UNAVAILABLE)

    def test_main_all_blocked_is_valid_input(self):
        # `ok` が 0 件でも主入力としては成立している (全件が未実行 worklist に残るのが正)。
        rc = self._main_with_executed({
            "run_id": "R1", "shards": ["0"],
            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "blocked"}],
        })
        self.assertEqual(rc, C.EXIT_OK)

    def test_main_schema_violations_return_3(self):
        # 契約外の形は **traceback ではなく終了コード 3** で落ちること。
        ok_row = {"route_name": "register.store", "shard": "0", "status": "ok"}
        cases = {
            "root が object でない": [1, 2, 3],
            "shards が配列でない": {"run_id": "R1", "shards": "0", "executed_routes": [ok_row]},
            "executed_routes が配列でない": {"run_id": "R1", "shards": ["0"], "executed_routes": {}},
            "行が object でない": {"run_id": "R1", "shards": ["0"], "executed_routes": ["x"]},
            "status が未知値": {"run_id": "R1", "shards": ["0"],
                            "executed_routes": [{**ok_row, "status": "skipped"}]},
            "status が非文字列": {"run_id": "R1", "shards": ["0"],
                             "executed_routes": [{**ok_row, "status": {"a": 1}}]},
            "route_name が空": {"run_id": "R1", "shards": ["0"],
                             "executed_routes": [{**ok_row, "route_name": ""}]},
            "shard が非文字列": {"run_id": "R1", "shards": ["0"],
                            "executed_routes": [{**ok_row, "shard": 0}]},
            "run_id が null": {"run_id": None, "shards": ["0"], "executed_routes": [ok_row]},
            "run_id が空文字": {"run_id": "", "shards": ["0"], "executed_routes": [ok_row]},
            "run_id が数値": {"run_id": 1, "shards": ["0"], "executed_routes": [ok_row]},
        }
        for label, payload in cases.items():
            with self.subTest(case=label):
                self.assertEqual(self._main_with_executed(payload), C.EXIT_INPUT_UNAVAILABLE)

    def test_main_broken_json_returns_1(self):
        # 構文として読めない入力は従来どおり 1 (可用性違反 3 とは分ける)。
        self.assertEqual(self._main_with_executed('{"run_id": '), C.EXIT_INPUT_ERROR)

    def test_run_id_shape_violation_is_schema_error_not_mismatch(self):
        # run_id が非文字列のときは run_id 不一致ではなく形の違反として報告する。
        path = self._write_executed({"run_id": 1, "shards": ["0"], "executed_routes": []})
        ex = C.load_executed(path)
        reason = C.validate_executed(ex, "R1")
        self.assertIsNotNone(reason)
        self.assertIn("executed_schema_invalid", reason)


class MainInputAvailabilityTest(unittest.TestCase):
    """主入力 6 点の欠落を 1 点ずつ pin する (家系正典 t2 要素 1 の aicue 形)。

    aicue の照合器の主入力は 6 点 — 目録 (--operations) / 所見 (--findings) /
    実行済み (--executed) / graph.db (--graph-db) / 走行 id (--run-id) /
    route 一覧 (--route-list。省略は欠落ではなく実ルーター fallback = RealRouterTest が担当)。

    守る不変条件は「主入力が揃わない走行を成功にしない・worklist を 1 行も出さない」で、
    終了コードはその写像 (契約の正本は coverage/README.md):
      - オプション欠落: argparse required の 4 点は usage エラー (SystemExit 2)、
        --executed は main 内の可用性検査 (return 3 = executed_missing)
      - ファイル不在・glob 0 件: 読み込みの失敗 (return 1)
    正典実装は全欠落を 3 へ写像するが、aicue は D14 の別実装として上記の既存契約を pin する
    (終了コードの写像替えは README / SKILL.md 運用文まで波及する別議題)。

    将来 main() が stdout へ診断を出す設計に変わる場合は、「worklist を出さない」と
    「stdout 完全無出力」を別契約として本クラスの assert を再検討すること。
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name)
        self.route_path = base / "route.json"
        self.route_path.write_text(
            '[{"name":"register.store","method":"POST","uri":"register",'
            '"action":"App\\\\Http\\\\Controllers\\\\Auth\\\\RegisteredUserController@store"}]',
            encoding="utf-8",
        )
        self.ops_path = base / "operations.md"
        self.ops_path.write_text(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | register | register.store | S1 | ◎ |\n",
            encoding="utf-8",
        )
        self.findings_path = base / "findings.jsonl"
        self.findings_path.write_text(
            '{"finding_id":"F-1","run_id":"R1","route_name":"register.store","species_key":"a"}\n',
            encoding="utf-8",
        )
        self.executed_path = base / "executed.json"
        self.executed_path.write_text(json.dumps({
            "run_id": "R1", "shards": ["0"],
            "executed_routes": [
                {"route_name": "register.store", "shard": "0", "status": "ok"},
            ],
        }), encoding="utf-8")
        self.db = str(base / "graph.db")
        make_graph_db(self.db, [
            ("/workspace/resources/js/x.ts::x", "/workspace/resources/js/t.ts::t"),
        ])
        self.argv = [
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(self.findings_path),
            "--executed", str(self.executed_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ]

    def _run(self, argv):
        """main() を実行し (終了コード, stdout, stderr) を返す。

        argparse required の欠落は SystemExit を投げるので、その code も
        終了コードとして扱う (usage エラー 2)。
        """
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            try:
                rc = C.main(argv)
            except SystemExit as e:
                rc = e.code
        return rc, out.getvalue(), err.getvalue()

    def _drop_option(self, opt):
        idx = self.argv.index(opt)
        return self.argv[:idx] + self.argv[idx + 2:]

    def _replace_value(self, opt, value):
        argv = list(self.argv)
        argv[argv.index(opt) + 1] = value
        return argv

    def assert_no_worklist(self, rc, out, expected_rc):
        self.assertEqual(rc, expected_rc)          # (α) 期待する非 0 終了コード
        self.assertEqual(out, "")                  # (β) stdout に何も出さない
        self.assertNotIn("未実行機構", out)          # (γ) 契約の意図の明示 ((β) に包含)

    def test_baseline_is_green(self):
        # 正の対照: 全部揃っていれば 0 で worklist が出る (欠落検査の前提)。
        rc, out, err = self._run(self.argv)
        self.assertEqual(rc, 0, err)
        self.assertIn("未実行機構", out)

    def test_option_missing_is_rejected_per_input(self):
        # (--route-list は required でない: 省略 = 実ルーター fallback で欠落ではない)
        cases = {
            "--operations": 2, "--findings": 2, "--graph-db": 2, "--run-id": 2,
            "--executed": 3,
        }
        for opt, expected in cases.items():
            with self.subTest(option=opt):
                rc, out, err = self._run(self._drop_option(opt))
                self.assert_no_worklist(rc, out, expected)
                if expected == 3:
                    self.assertIn("executed_missing", err)

    def test_file_missing_is_rejected_per_input(self):
        base = Path(self.tmp.name)
        for opt in ("--route-list", "--operations", "--findings",
                    "--executed", "--graph-db"):
            with self.subTest(option=opt):
                rc, out, err = self._run(
                    self._replace_value(opt, str(base / "no-such-input")))
                self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
                self.assertIn("ERROR", err)

    def test_findings_glob_matching_nothing_is_rejected(self):
        rc, out, err = self._run(
            self._replace_value("--findings", str(Path(self.tmp.name) / "shard-*" / "findings.jsonl")))
        self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
        self.assertIn("ERROR", err)


class ExecutedValidationTest(unittest.TestCase):
    """validate_executed() の単体検査 (成立 → None / 各違反 → 理由文字列)。"""

    def _executed(self, **kwargs) -> C.Executed:
        ex = C.Executed(run_id=kwargs.pop("run_id", "R1"), shards=kwargs.pop("shards", ["0"]))
        for name, shard, status in kwargs.pop("rows", [("a", "0", "ok")]):
            ex.row_count += 1
            ex.routes.setdefault(name, set()).add(shard)
            ex.statuses.setdefault(name, set()).add(status)
        ex.schema_error = kwargs.pop("schema_error", None)
        return ex

    def test_valid_input_returns_none(self):
        self.assertIsNone(C.validate_executed(self._executed(), "R1"))

    def test_schema_error_wins_over_run_id_mismatch(self):
        ex = self._executed(run_id="OTHER", schema_error="root が JSON object でない")
        reason = C.validate_executed(ex, "R1")
        self.assertIn("executed_schema_invalid", reason)

    def test_run_id_mismatch(self):
        self.assertIn("executed_run_id_mismatch",
                      C.validate_executed(self._executed(run_id="OTHER"), "R1"))

    def test_shards_missing(self):
        self.assertIn("executed_shards_missing",
                      C.validate_executed(self._executed(shards=[]), "R1"))

    def test_no_rows(self):
        ex = C.Executed(run_id="R1", shards=["0"])
        self.assertIn("executed_no_rows", C.validate_executed(ex, "R1"))

    def test_shard_mismatch(self):
        ex = self._executed(shards=["0", "1"])
        self.assertIn("executed_shard_mismatch", C.validate_executed(ex, "R1"))

    def test_all_blocked_is_valid(self):
        ex = self._executed(rows=[("a", "0", "blocked")])
        self.assertIsNone(C.validate_executed(ex, "R1"))


class RenderWorklistTest(unittest.TestCase):
    """旧 fail-open の注記が二度と出力に現れないこと。"""

    def test_no_missing_executed_notice(self):
        corr = C.Correlation(
            run_id="R1", rows=[], unexecuted=[], untested_real=[],
            finding_hotspots=[], cross_unexec_findingful=[], unknown_graph_gap_count=0,
        )
        out = C.render_worklist(corr)
        self.assertNotIn("executed.json 未指定", out)
        self.assertNotIn("未実行 candidate", out)


class StoryCellParseTest(unittest.TestCase):
    """割当セルの分解 (目録が複数値セルを書けるようになったことへの追従)。

    実在 (そのカードが在るか) は見ない。目録は生成物であり、割当列は実在するカードの
    前付けからしか作られない (生成器側の検査が担う)。
    """

    def test_単一値は従来どおり(self):
        self.assertEqual(["S3"], C.parse_story_cell("S3", "r"))

    def test_複数値は全部に索引される(self):
        self.assertEqual(["S3", "S7"], C.parse_story_cell("S3 S7", "r"))

    def test_対象外はどのstoryにも索引されない(self):
        self.assertEqual([], C.parse_story_cell("-", "r"))

    def test_実在しないカードでも通す(self):
        # 責務外 (生成器側が出さないことを test_bug_hunt_inventory.py が固定する)。
        self.assertEqual(["S8"], C.parse_story_cell("S8", "r"))

    def test_契約外のセルは致命(self):
        # **寛容に正規化しない**。str.split() は前後空白も連続空白も黙って吸収する。
        for cell in (" S3", "S3 ", "S3  S7", "", "SX", "S0", "S03", "s3", "S3,S7", "S3 S7 "):
            with self.subTest(cell=cell):
                with self.assertRaises(C.FatalError):
                    C.parse_story_cell(cell, "r")

    def test_降順と重複は致命(self):
        for cell in ("S7 S3", "S3 S3"):
            with self.subTest(cell=cell):
                with self.assertRaises(C.FatalError):
                    C.parse_story_cell(cell, "r")


class RealRouterTest(unittest.TestCase):
    """実ルーター経路 (--route-list 省略時の本番経路) の検査 (家系正典 t2 要素 3 の aicue 形)。

    正典の (c) は生成器コマンド (bughunt:resolve-executed) の実登録検査だが、aicue は
    D14 の別実装で生成器を持たない。照合器が実際に依存するコマンドは
    `php artisan route:list --json` (load_route_list(None) の subprocess fallback) なので、
    その実登録と実走を固定し、壊れたとき「何が壊れたか読めない赤」にならないようにする。

    gate: リポジトリルートの artisan 実在 (aicue の checkout では常に実在 = 常時実走。
    skip になるのは coverage/ を Laravel checkout の外へ単独コピーした場合だけ)。
    """

    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
    def test_route_list_command_is_registered(self):
        # コマンド実登録の確認 (前段)。行頭比較や部分一致は使わない:
        # 各行を strip() し、空白区切りの第 1 トークンの完全一致で route:list を探す。
        try:
            proc = subprocess.run(
                ["php", "artisan", "list", "--raw"],
                cwd=str(_REPO_ROOT), capture_output=True, text=True, timeout=60,
            )
        except subprocess.TimeoutExpired:
            self.fail("php artisan list --raw が 60 秒で応答しない (アプリの boot が進まない)")
        self.assertEqual(
            proc.returncode, 0,
            "php artisan list --raw が失敗 (アプリが起動できない):\n"
            f"rc={proc.returncode}\nstdout:\n{proc.stdout[:2000]}\nstderr:\n{proc.stderr[:2000]}",
        )
        registered = {
            line.strip().split()[0]
            for line in proc.stdout.splitlines() if line.strip()
        }
        self.assertIn(
            "route:list", registered,
            "route:list コマンドが実登録されていない (artisan list --raw に現れない)",
        )

    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
    def test_load_route_list_fallback_returns_named_routes(self):
        # 実ルーター経路の実走 (本命)。本命呼び出しそのものに診断を付ける
        # (事前の自前実行は置かない — 事前の成功は本命の診断可能性を保証しない)。
        try:
            routes = C.load_route_list(None, project_dir=str(_REPO_ROOT))
        except subprocess.CalledProcessError as e:
            self.fail(
                "php artisan route:list --json が失敗:\n"
                f"rc={e.returncode}\nstderr:\n{(e.stderr or '')[:2000]}"
            )
        except json.JSONDecodeError as e:
            self.fail(
                f"route:list の出力が JSON として読めない: {e} "
                f"(本文先頭: {e.doc[:200]!r})"
            )
        self.assertIsInstance(routes, list)
        named = [r for r in routes if isinstance(r, dict) and r.get("name")]
        self.assertGreater(
            len(named), 0,
            "name を持つ route が 0 件 = 実ルーター経路が壊れている",
        )


if __name__ == "__main__":
    unittest.main()
