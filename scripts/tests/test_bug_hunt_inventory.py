#!/usr/bin/env python3
"""scripts/bug-hunt-inventory.py の自己テスト (標準ライブラリのみ)。

実 `php` を呼ばない。抽出は fake scanner (固定の JSON を返す callable) で駆動するので
決定論で速く、DB にも APP_KEY にも依存しない。

    cd scripts/tests && python3 -m unittest test_bug_hunt_inventory

`composer test` からは tests/Architecture/BughuntInventoryToolSelfTest.php が起動する。
"""
from __future__ import annotations

import importlib.util
import io
import shutil
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
GENERATOR_PATH = REPO_ROOT / "scripts/bug-hunt-inventory.py"


def _load_generator():
    """ファイル名にハイフンを含むので通常の import ができない (この読み込み自体もテストの一部)。"""
    spec = importlib.util.spec_from_file_location("bug_hunt_inventory", GENERATOR_PATH)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    # dataclass の遅延注釈解決が sys.modules を引くので、実行前に登録する。
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)

    return module


inv = _load_generator()


# --------------------------------------------------------------------------- #
# fixture
# --------------------------------------------------------------------------- #
def route(name, uri, methods, middleware=("web",), title=None, action="App\\Http\\C@m"):
    return {
        "name": name,
        "uri": uri,
        "methods": list(methods),
        "middleware": list(middleware),
        "action": action,
        "title": title,
    }


BASE_ROUTES = [
    route("dashboard", "dashboard", ["GET", "HEAD"], title="ダッシュボード"),
    route("session.status", "session/status", ["GET", "HEAD"]),
    route("seo.robots", "robots.txt", ["GET", "HEAD"]),
    route("projects.store", "projects", ["POST"]),
    route("projects.destroy", "projects/{project}", ["DELETE"]),
    # web 面ではないもの (面の判定の負の対照)
    route("cashier.webhook", "stripe/webhook", ["POST"], middleware=["throttle:webhook-stripe"]),
    route("passport.token", "oauth/token", ["POST"], middleware=["web"]),
    route("livewire.update", "livewire-18f43797/update", ["POST"], middleware=["web"]),
    route(None, "vendor/asset.js", ["GET", "HEAD"], middleware=[]),
]

BASE_ANNOTATIONS = """schema_version = 1

[routes."dashboard"]
kind = "画面"
story = "S1"
kubun = "通常"

[routes."projects.destroy"]
story = "S4"
kubun = "通常"

[routes."projects.store"]
story = "S4"
kubun = "通常"

[routes."seo.robots"]
kind = "JSON"
kubun = "外"
reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない"

[routes."session.status"]
kind = "JSON"
story = "S6"
kubun = "通常"
"""

BASE_CATALOG = """# Capability Catalog

## capability_id 索引

| id | 機能 (actor→outcome) | 代表機構 (route name) |
|---|---|---|
| PROJ-01 | owner→プロジェクト CRUD | `projects.store` / `projects.*` |
| PLAT-01 | platform→管理パネル | (admin panel) |
| AK-04 | automation→REST API | `routes/api.php` |
"""


def fake_scanner(routes=None, *, schema_version=1, condition=inv.EXTRACTION_CONDITION, payload=None):
    """抽出コマンドの代わり。`payload` を渡すと生の値をそのまま返す。"""

    def scanner(_repo_root):
        if payload is not None:
            return payload

        return {
            "schema_version": schema_version,
            "extraction_condition": condition,
            "routes": BASE_ROUTES if routes is None else routes,
        }

    return scanner


class SandboxCase(unittest.TestCase):
    """生成器が読む 6 ファイルを持つ sandbox を組み立てる。"""

    def setUp(self):
        self.root = Path(tempfile.mkdtemp(prefix="bhi-"))
        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
        (self.root / inv.ANNOTATIONS_PATH).parent.mkdir(parents=True, exist_ok=True)
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS)
        self.write(inv.NOTES_SCREENS_PATH, "## 画面の散文\n\nここは人が書く。\n")
        self.write(inv.NOTES_OPERATIONS_PATH, "## 操作の散文\n\nここは人が書く。\n")
        self.write(inv.CATALOG_PATH, BASE_CATALOG)
        self.write(inv.SCREENS_PATH, "placeholder\n")
        self.write(inv.OPERATIONS_PATH, "placeholder\n")

    def write(self, relative: Path, content: str) -> Path:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8", newline="\n")

        return path

    def read(self, relative: Path) -> str:
        return (self.root / relative).read_text(encoding="utf-8")

    def run_check(self, scanner=None) -> tuple[int, str]:
        return self._capture(inv.run_check, scanner)

    def run_generate(self, scanner=None) -> tuple[int, str]:
        return self._capture(inv.run_generate, scanner)

    def _capture(self, entry, scanner) -> tuple[int, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            try:
                code = entry(self.root, scanner=scanner or fake_scanner())
            except inv.FatalError as exc:
                return inv.EXIT_FATAL, f"{out.getvalue()}{err.getvalue()}{exc}"

        return code, out.getvalue() + err.getvalue()

    def generate_then(self, scanner=None):
        code, output = self.run_generate(scanner)
        self.assertEqual(inv.EXIT_OK, code, output)


# --------------------------------------------------------------------------- #
# 段 1: 抽出 (致命)
# --------------------------------------------------------------------------- #
class ExtractionTest(SandboxCase):
    def test_非0終了の抽出コマンドは致命(self):
        def failing(_root):
            raise inv.FatalError("[抽出] 抽出コマンドが非 0 終了")

        code, output = self.run_check(failing)
        self.assertEqual(inv.EXIT_FATAL, code, output)

    def test_抽出条件の不一致は致命(self):
        code, output = self.run_check(fake_scanner(condition="production"))
        self.assertEqual(inv.EXIT_FATAL, code)
        self.assertIn("抽出条件", output)

    def test_schema_version_の不一致は致命(self):
        code, _ = self.run_check(fake_scanner(schema_version=2))
        self.assertEqual(inv.EXIT_FATAL, code)

    def test_routes_が並びでないのは致命(self):
        code, _ = self.run_check(fake_scanner(payload={
            "schema_version": 1, "extraction_condition": inv.EXTRACTION_CONDITION, "routes": {},
        }))
        self.assertEqual(inv.EXIT_FATAL, code)

    def test_抽出結果が表でないのは致命(self):
        code, _ = self.run_check(fake_scanner(payload=["not", "a", "table"]))
        self.assertEqual(inv.EXIT_FATAL, code)

    def test_母集合0件は致命(self):
        code, output = self.run_check(fake_scanner([route("api.ping", "api/ping", ["GET"], middleware=[])]))
        self.assertEqual(inv.EXIT_FATAL, code)
        self.assertIn("母集合が 0 件", output)

    def test_web面の無名routeは致命(self):
        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route(None, "anon", ["GET", "HEAD"])]))
        self.assertEqual(inv.EXIT_FATAL, code)
        self.assertIn("名前が無い", output)

    def test_名前の重複は致命(self):
        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route("dashboard", "dash2", ["GET"])]))
        self.assertEqual(inv.EXIT_FATAL, code)
        self.assertIn("重複", output)

    def test_面の外の無名routeは許容(self):
        # vendor の資材配信のような無名 route は抽出結果に出るが目録には入らない。
        self.generate_then()
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_OK, code, output)

    def test_注釈ファイル不在は致命(self):
        (self.root / inv.ANNOTATIONS_PATH).unlink()
        code, _ = self.run_check()
        self.assertEqual(inv.EXIT_FATAL, code)

    def test_壊れたTOMLは致命(self):
        self.write(inv.ANNOTATIONS_PATH, "schema_version = 1\n[routes.\n")
        code, _ = self.run_check()
        self.assertEqual(inv.EXIT_FATAL, code)

    def test_散文ノート不在は致命(self):
        (self.root / inv.NOTES_OPERATIONS_PATH).unlink()
        code, _ = self.run_check()
        self.assertEqual(inv.EXIT_FATAL, code)


# --------------------------------------------------------------------------- #
# 面の判定
# --------------------------------------------------------------------------- #
class SurfaceTest(unittest.TestCase):
    def facts(self, routes=None):
        return inv.split_surface(fake_scanner(routes)(Path(".")))

    def test_throttle_webhook_stripe_を_web_面と誤認しない(self):
        # middleware の**要素**を見る (文字列化した部分一致にしない)。
        self.assertNotIn("cashier.webhook", {f.name for f in self.facts().operations})

    def test_oauth_と_livewire_ハッシュは面から外す(self):
        names = {f.name for f in self.facts().surface}
        self.assertNotIn("passport.token", names)
        self.assertNotIn("livewire.update", names)

    def test_画面表と操作表の直和が_web_面になる(self):
        facts = self.facts()
        self.assertEqual(
            {f.name for f in facts.screens} | {f.name for f in facts.operations},
            {"dashboard", "session.status", "seo.robots", "projects.store", "projects.destroy"},
        )
        self.assertEqual(len(facts.screens) + len(facts.operations), len(facts.surface))
        self.assertEqual(set(), {f.name for f in facts.screens} & {f.name for f in facts.operations})

    def test_全route名は面に限らない(self):
        # 段 4 の照合母集合は admin / api 面も含む。
        self.assertIn("cashier.webhook", self.facts().all_names)

    def test_複合methodは操作表へ入れたうえで印を付ける(self):
        facts = self.facts(BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])])
        self.assertIn("both", {f.name for f in facts.operations})
        self.assertEqual(("both",), facts.compound)


class VocabularyParityTest(unittest.TestCase):
    def test_区分の語彙が_correlate_と一致する(self):
        sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
        try:
            import correlate
        finally:
            sys.path.pop(0)
        self.assertEqual(correlate.KUBUN_OUT_OF_SCOPE, inv.KUBUN_OUT_OF_SCOPE)
        self.assertEqual(correlate.KUBUN_DEVIATE, inv.KUBUN_DEVIATE)


# --------------------------------------------------------------------------- #
# 段 2: 注釈 (ドリフト)
# --------------------------------------------------------------------------- #
class AnnotationTest(SandboxCase):
    def assert_drift(self, needle: str):
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn(needle, output)

    def replace(self, old: str, new: str):
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS.replace(old, new))

    def test_未注釈のroute(self):
        self.replace('[routes."projects.store"]\nstory = "S4"\nkubun = "通常"\n', "")
        self.assert_drift("未注釈の route: projects.store")

    def test_実装に無いrouteの注釈残置(self):
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."gone.index"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"\n')
        self.assert_drift("実装に無い route の注釈が残っている: gone.index")

    def test_未知の区分(self):
        self.replace('[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"',
                     '[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "重要"')
        self.assert_drift("未知の区分")

    def test_未知の項目(self):
        self.replace('[routes."dashboard"]\nkind = "画面"', '[routes."dashboard"]\nmemo = "x"\nkind = "画面"')
        self.assert_drift("未知の項目: memo")

    def test_理由が30文字未満(self):
        self.replace("クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない", "短い理由")
        self.assert_drift("30 文字未満")

    def test_story欠落(self):
        self.replace('[routes."projects.store"]\nstory = "S4"\n', '[routes."projects.store"]\n')
        self.assert_drift("story が要る")

    def test_区分外にstoryを書けない(self):
        self.replace('[routes."seo.robots"]\nkind = "JSON"', '[routes."seo.robots"]\nkind = "JSON"\nstory = "S1"')
        self.assert_drift("story は書けない")

    def test_画面routeのkind欠落(self):
        self.replace('[routes."dashboard"]\nkind = "画面"\n', '[routes."dashboard"]\n')
        self.assert_drift("kind が要る")

    def test_操作routeにkindは書けない(self):
        self.replace('[routes."projects.store"]\n', '[routes."projects.store"]\nkind = "画面"\n')
        self.assert_drift("kind は書けない")

    def test_セル値に表を壊す文字(self):
        self.replace('story = "S1"\nkubun = "通常"', 'story = "S1|S2"\nkubun = "通常"')
        self.assert_drift("表を壊す文字")

    def test_機械事実側のセル値に表を壊す文字(self):
        routes = [r for r in BASE_ROUTES if r["name"] != "dashboard"]
        routes.append(route("dashboard", "dash|board", ["GET", "HEAD"]))
        code, output = self.run_check(fake_scanner(routes))
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("表を壊す文字", output)

    def test_複合methodはドリフト(self):
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nstory = "S1"\nkubun = "通常"\n')
        code, output = self.run_check(fake_scanner(BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])]))
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("併せ持つ route", output)

    def test_未知のトップレベル項目はドリフト(self):
        self.write(inv.ANNOTATIONS_PATH, 'version = 1\n' + BASE_ANNOTATIONS)
        self.assert_drift("未知のトップレベル項目: version")

    def test_散文ノートの表はドリフト(self):
        # 互換ヘッダでなくても列割当は据え置かれるので、表そのものを置かせない。
        for path, table in (
            (inv.NOTES_OPERATIONS_PATH, "| name | story | 区分 |\n|---|---|---|\n| fake.route | S1 | 通常 |\n"),
            (inv.NOTES_SCREENS_PATH, "| 何か | 別の | 表 | です | ね |\n|---|---|---|---|---|\n| a | b | c | d | e |\n"),
        ):
            with self.subTest(path=str(path)):
                self.setUp()
                self.generate_then()
                self.write(path, "## 散文\n\n" + table)
                code, output = self.run_check()
                self.assertEqual(inv.EXIT_DRIFT, code, output)
                self.assertIn("散文ノートに表を置かない", output)


# --------------------------------------------------------------------------- #
# 段 3: 生成物
# --------------------------------------------------------------------------- #
class GeneratedFilesTest(SandboxCase):
    def test_生成してから検査すると一致する(self):
        self.generate_then()
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_OK, code, output)

    def test_目録が未生成でも初回生成できる(self):
        (self.root / inv.SCREENS_PATH).unlink()
        (self.root / inv.OPERATIONS_PATH).unlink()
        self.generate_then()
        self.assertEqual(inv.EXIT_OK, self.run_check()[0])

    def test_生成物のbyte不一致はドリフト(self):
        self.generate_then()
        self.write(inv.SCREENS_PATH, self.read(inv.SCREENS_PATH) + "手で足した行\n")
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("screens.md", output)

    def test_checkは1バイトも書かない(self):
        self.generate_then()
        before = {
            path: ((self.root / path).read_bytes(), (self.root / path).stat().st_mtime_ns)
            for path in (inv.SCREENS_PATH, inv.OPERATIONS_PATH, inv.ANNOTATIONS_PATH)
        }
        self.assertEqual(inv.EXIT_OK, self.run_check()[0])
        for path, (content, mtime) in before.items():
            self.assertEqual(content, (self.root / path).read_bytes())
            self.assertEqual(mtime, (self.root / path).stat().st_mtime_ns)
        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*.tmp-generate")))

    def test_生成物に生成物である宣言がある(self):
        self.generate_then()
        for path in (inv.SCREENS_PATH, inv.OPERATIONS_PATH):
            self.assertIn("このファイルは生成物である", self.read(path))

    def test_操作表は5列でヘッダ名を変えない(self):
        self.generate_then()
        self.assertIn("| method | route | name | story | 区分 |", self.read(inv.OPERATIONS_PATH))

    def test_generateは段2違反のとき1ファイルも書かない(self):
        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS.replace('kubun = "通常"', 'kubun = "重要"'))
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))

    def test_区分外のstory欄はハイフンになる(self):
        self.generate_then()
        rows = [l for l in self.read(inv.SCREENS_PATH).splitlines() if "seo.robots" in l and l.startswith("|")]
        self.assertEqual(1, len(rows))
        self.assertEqual("-", [c.strip() for c in rows[0].strip("|").split("|")][4])


class ReplaceFailureTest(SandboxCase):
    """置換に失敗しても、呼び出し前の 2 ファイルが byte 単位で保たれること。"""

    def patch_replace(self, failing_calls: set[int]):
        original = inv.os.replace
        calls = {"n": 0}

        def fake(src, dst):
            calls["n"] += 1
            if calls["n"] in failing_calls:
                raise OSError(f"注入した失敗 (呼び出し {calls['n']} 回目)")

            return original(src, dst)

        inv.os.replace = fake
        self.addCleanup(setattr, inv.os, "replace", original)

        return calls

    def test_1本目の置換失敗で2ファイルとも無傷(self):
        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
        self.patch_replace({1})
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_FATAL, code, output)
        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate")))

    def test_2本目の置換失敗は控えから戻す(self):
        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
        self.patch_replace({2})
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_FATAL, code, output)
        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
        self.assertEqual([], sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate")))

    def test_未生成からの初回生成で2本目が失敗したら1本目も消す(self):
        (self.root / inv.SCREENS_PATH).unlink()
        (self.root / inv.OPERATIONS_PATH).unlink()
        self.patch_replace({2})
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_FATAL, code, output)
        self.assertFalse((self.root / inv.SCREENS_PATH).exists())
        self.assertFalse((self.root / inv.OPERATIONS_PATH).exists())

    def test_復元にも失敗したら何も消さずに全パスを出す(self):
        self.patch_replace({2, 3})
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_FATAL, code, output)
        self.assertIn("復元に失敗", output)
        leftovers = sorted(p.name for p in (self.root / inv.SKILL_DIR).glob("*generate"))
        self.assertIn("screens.md.bak-generate", leftovers)
        self.assertIn("operations.md.tmp-generate", leftovers)


# --------------------------------------------------------------------------- #
# 段 4: 機能カタログ
# --------------------------------------------------------------------------- #
class CatalogTest(SandboxCase):
    def setUp(self):
        super().setUp()
        self.generate_then()

    def test_実在しない代表機構はドリフト(self):
        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.store`", "`projects.missing`"))
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("実在しない route 名", output)

    def test_idの重複はドリフト(self):
        self.write(inv.CATALOG_PATH, BASE_CATALOG + "| PROJ-01 | 重複 | `projects.store` |\n")
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("重複", output)

    def test_前方一致が1件も当たらないとドリフト(self):
        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.*`", "`nowhere.*`"))
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("前方一致", output)

    def test_括弧書きとパスのセルは無視される(self):
        # BASE_CATALOG は (admin panel) と `routes/api.php` を含む。これらで落ちないこと。
        self.assertEqual(inv.EXIT_OK, self.run_check()[0])

    def test_パスに見えないスラッシュ入りの記載はドリフト(self):
        # `/` を含むだけで候補から外すと、URL の打ち間違いが素通りしてしまう。
        self.write(inv.CATALOG_PATH, BASE_CATALOG.replace("`projects.store`", "`projects/store`"))
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("実在しない route 名", output)

    def test_表が見つからないのは致命(self):
        self.write(inv.CATALOG_PATH, "# Capability Catalog\n\n表が無い。\n")
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_FATAL, code, output)


# --------------------------------------------------------------------------- #
# 下流ローダとの結合
# --------------------------------------------------------------------------- #
class CorrelateIntegrationTest(SandboxCase):
    def test_生成した操作表を_correlate_が同じ集合として読める(self):
        self.generate_then()
        sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
        try:
            import correlate
        finally:
            sys.path.pop(0)

        loaded = correlate.load_operations(str(self.root / inv.OPERATIONS_PATH))
        self.assertEqual({"projects.store", "projects.destroy"}, set(loaded))
        self.assertEqual("S4", loaded["projects.store"]["story"])
        self.assertEqual("通常", loaded["projects.store"]["kubun"])
        self.assertEqual("projects", loaded["projects.store"]["operation"])

        # load_operations() は重複を「最初の定義を優先」で畳むので、重複が隠れないことを見る。
        text = self.read(inv.OPERATIONS_PATH)
        for name in loaded:
            self.assertEqual(1, text.count(f"| {name} |"), name)


if __name__ == "__main__":
    unittest.main()
