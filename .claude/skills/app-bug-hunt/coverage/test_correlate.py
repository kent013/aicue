#!/usr/bin/env python3
"""correlate.py の単体テスト (stdlib unittest)。

graph.db はテスト用 temp sqlite を実 DB のスキーマ(edges.kind/source_qualified)で
都度生成する (バイナリ commit は避ける)。pcov 非依存。

実行: python3 -m unittest test_correlate -v
"""
from __future__ import annotations

import json
import os
import sqlite3
import tempfile
import unittest
from pathlib import Path

import correlate as C

# fix-gate #3/#4 検証用: 実 operations.md と実 graph.db。存在時のみ走らせる。
_SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/
REAL_OPERATIONS = _SKILL_ROOT / "operations.md"
REAL_GRAPH_DB = Path("/workspace/.code-review-graph/graph.db")


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
            "| POST | /projects | `api.v1.projects.store` | `project:create` | S8 | ◎ |\n"
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
            "| POST | /projects | `api.v1.projects.store` | `project:create` | S8 | ◎ |\n"
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

    @unittest.skipUnless(REAL_OPERATIONS.is_file(), "real operations.md not present")
    def test_real_operations_md_name_column_join_keys(self):
        # fix-gate #3 の load-bearing claim: 実 operations.md の join キー = name 列 (URL 列ではない)。
        # テンプレート汎用化: 特定アプリの route 名を hardcode せず、構造で検証する
        # (アプリが operations.md を埋めた後も、slug 非依存で機能する)。
        ops = C.load_operations(str(REAL_OPERATIONS))
        if not ops:
            self.skipTest("operations.md はスケルトン (データ行なし)。route:list から生成後に有効化される")

        for name in ops:
            # route 名は通常ドット区切り (resource.action)。少なくとも空でないこと。
            self.assertTrue(name.strip(), "空の join キーが混入している")

        # join キー (name 列) と URL 列 (load_operations は 'operation' に格納) の一致を
        # **集約で**判定する。
        #
        # 検出したい failure mode は「load_operations が name 列でなく URL 列を join キーに
        # している」ことであり、それが起きると **全行が一致する**。
        # 一方、単一セグメント route は route 名と URL が正当に同値になる
        # (Laravel の `Route::post('logout', ...)->name('logout')` 等)。
        # 行単位の assertNotEqual だとこの正当なケースを偽陽性で落とすため、
        # 「**全行が一致していないこと**」を条件にする (検出力は維持される)。
        matched = [name for name, info in ops.items() if name == info.get("operation")]
        self.assertNotEqual(
            len(matched), len(ops),
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


if __name__ == "__main__":
    unittest.main()
