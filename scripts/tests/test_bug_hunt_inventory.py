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
kubun = "通常"

[routes."projects.destroy"]
kubun = "通常"

[routes."projects.store"]
kubun = "通常"

[routes."seo.robots"]
kind = "JSON"
kubun = "外"
reason = "クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない"

[routes."session.status"]
kind = "JSON"
kubun = "通常"
"""


def card(
    card_id="S1",
    *,
    title="見本カード",
    surface="signup_funnel",
    lane="parallel_browser",
    priority="P1",
    applicability="applicable",
    reason=None,
    depends_on=(),
    reseed_before=True,
    accounts=("guest",),
    setup=(),
    screens=(),
    operations=(),
    capabilities=(),
    body=None,
):
    """合成のシナリオカード 1 枚 (前付けは正準順序で書く)。"""
    def arr(values):
        return "[" + ", ".join(values) + "]"

    lines = [
        "---",
        f"id: {card_id}",
        f"title: {title}",
        f"surface: {surface}",
        f"lane: {lane}",
        f"priority: {priority}",
        f"applicability: {applicability}",
    ]
    if reason is not None:
        lines.append(f"not_applicable_reason: {reason}")
    lines += [
        f"depends_on: {arr(depends_on)}",
        f"reseed_before: {'true' if reseed_before else 'false'}",
        f"accounts: {arr(accounts)}",
        f"setup: {arr(setup)}",
        f"covers_screens: {arr(screens)}",
        f"covers_operations: {arr(operations)}",
        f"covers_capabilities: {arr(capabilities)}",
        "---",
        "",
        f"# {card_id}: {title}",
        "",
        "## 目的",
        "見本のカードである。",
        "",
    ]
    if body is None:
        lines += ["## 手順", "1. 開く → 見える", ""]
    else:
        lines += body
    lines += ["## 逸脱アイデア (--deviate 時)", "- 二重送信してみる", ""]

    return "\n".join(lines)


# 既定のカード束: 対象内の 4 route (dashboard / session.status / projects.store /
# projects.destroy) をちょうど覆う。`seo.robots` は区分 外 なのでどのカードにも載せない。
BASE_CARDS = {
    "S1-signup.md": card("S1", screens=("dashboard",), capabilities=("PROJ-01",)),
    "S2-invitation.md": card(
        "S2", surface="invitation", screens=("session.status",),
        operations=("projects.store", "projects.destroy"),
    ),
}

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
    """生成器が読む入力一式 (注釈 / 散文 / カタログ / カード) を持つ sandbox を組み立てる。"""

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
        self.write_cards(BASE_CARDS)

    def write_cards(self, cards: dict) -> None:
        """カードの置き場を作り直す (前付けが割当の正本)。"""
        stories = self.root / inv.STORIES_DIR
        if stories.is_dir():
            shutil.rmtree(stories)
        stories.mkdir(parents=True, exist_ok=True)
        for name, text in cards.items():
            (stories / name).write_text(text, encoding="utf-8", newline="\n")

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
    def _correlate(self):
        sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
        try:
            import correlate
        finally:
            sys.path.pop(0)

        return correlate

    def test_区分の語彙が_correlate_と一致する(self):
        correlate = self._correlate()
        self.assertEqual(correlate.KUBUN_OUT_OF_SCOPE, inv.KUBUN_OUT_OF_SCOPE)
        self.assertEqual(correlate.KUBUN_DEVIATE, inv.KUBUN_DEVIATE)

    def test_割当セルの値域が_correlate_と一致する(self):
        # 書き出し側 (ここ) と読み手 (correlate) が別モジュールに同じ値域を持つ。
        # 共有モジュール化は採らない (CLI スクリプトはハイフンを含み import 対象にならない /
        # 照合器は共有ファイルなのでアプリ固有モジュールへの依存を増やすと乖離が深くなる)。
        # 代わりに**両側の定数が一致すること**をここで固定する。
        correlate = self._correlate()
        self.assertEqual(correlate.STORY_CELL_RE.pattern, inv.STORY_CELL_RE.pattern)
        self.assertEqual(correlate.STORY_CELL_SEPARATOR, inv.STORY_CELL_SEPARATOR)
        self.assertEqual(correlate.STORY_CELL_EMPTY, inv.STORY_CELL_EMPTY)

    def test_生成側が書くセルを読み手が同じ値に分解する(self):
        # 同一ケースを両側で列挙する (値域が 2 形に閉じていることの担保)。
        correlate = self._correlate()
        for value, expected in (
            (frozenset(), []),
            (frozenset({"S3"}), ["S3"]),
            (frozenset({"S7", "S3"}), ["S3", "S7"]),
            (frozenset({"S10", "S9"}), ["S9", "S10"]),
        ):
            with self.subTest(value=value):
                cell = inv._story_cell(value)
                self.assertEqual(expected, correlate.parse_story_cell(cell, "r"))


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
        self.replace('[routes."projects.store"]\nkubun = "通常"\n', "")
        self.assert_drift("未注釈の route: projects.store")

    def test_実装に無いrouteの注釈残置(self):
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."gone.index"]\nkind = "画面"\nkubun = "通常"\n')
        self.assert_drift("実装に無い route の注釈が残っている: gone.index")

    def test_未知の区分(self):
        self.replace('[routes."dashboard"]\nkind = "画面"\nkubun = "通常"',
                     '[routes."dashboard"]\nkind = "画面"\nkubun = "重要"')
        self.assert_drift("未知の区分")

    def test_未知の項目(self):
        self.replace('[routes."dashboard"]\nkind = "画面"', '[routes."dashboard"]\nmemo = "x"\nkind = "画面"')
        self.assert_drift("未知の項目: memo")

    def test_理由が30文字未満(self):
        self.replace("クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない", "短い理由")
        self.assert_drift("30 文字未満")

    def test_注釈にstoryを書き戻すと未知の項目(self):
        # 割当の正本はカードの前付けなので、注釈へ書き戻す道は deny-by-default で塞ぐ。
        self.replace('[routes."projects.store"]\n', '[routes."projects.store"]\nstory = "S1"\n')
        self.assert_drift("未知の項目: story")

    def test_画面routeのkind欠落(self):
        self.replace('[routes."dashboard"]\nkind = "画面"\n', '[routes."dashboard"]\n')
        self.assert_drift("kind が要る")

    def test_操作routeにkindは書けない(self):
        self.replace('[routes."projects.store"]\n', '[routes."projects.store"]\nkind = "画面"\n')
        self.assert_drift("kind は書けない")

    def test_セル値に表を壊す文字(self):
        self.replace('kind = "画面"\nkubun = "通常"', 'kind = "画|面"\nkubun = "通常"')
        self.assert_drift("表を壊す文字")

    def test_機械事実側のセル値に表を壊す文字(self):
        routes = [r for r in BASE_ROUTES if r["name"] != "dashboard"]
        routes.append(route("dashboard", "dash|board", ["GET", "HEAD"]))
        code, output = self.run_check(fake_scanner(routes))
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn("表を壊す文字", output)

    def test_複合methodはドリフト(self):
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nkubun = "通常"\n')
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
# 段 2: 割当 (カードの前付けが正本)
# --------------------------------------------------------------------------- #
class AssignmentTest(SandboxCase):
    """`covers_*` と目録の母集合の突合 (I1〜I4) と、生成器単体の fail-closed。"""

    def with_cards(self, **overrides):
        """S2 のカードを差し替えた束を置く (S1 は dashboard を覆ったまま)。"""
        cards = dict(BASE_CARDS)
        cards["S2-invitation.md"] = card("S2", surface="invitation", **overrides)
        self.write_cards(cards)

    def assert_drift(self, needle: str, scanner=None):
        code, output = self.run_check(scanner)
        self.assertEqual(inv.EXIT_DRIFT, code, output)
        self.assertIn(needle, output)

        return output

    def test_実在しないrouteを載せるとドリフト(self):
        self.with_cards(
            screens=("session.status", "nowhere.index"),
            operations=("projects.store", "projects.destroy"),
        )
        self.assert_drift("covers_screens に実在しない route: nowhere.index")

    def test_画面欄に非safeなrouteを載せるとドリフト(self):
        self.with_cards(
            screens=("session.status", "projects.store"),
            operations=("projects.store", "projects.destroy"),
        )
        self.assert_drift("covers_screens に欄違いの route: projects.store")

    def test_操作欄にsafeなrouteを載せるとドリフト(self):
        self.with_cards(
            screens=("session.status",),
            operations=("projects.store", "projects.destroy", "dashboard"),
        )
        self.assert_drift("covers_operations に欄違いの route: dashboard")

    def test_対象外のrouteを載せるとドリフト(self):
        self.with_cards(
            screens=("session.status", "seo.robots"),
            operations=("projects.store", "projects.destroy"),
        )
        self.assert_drift("covers_screens に対象外の route: seo.robots")

    def test_どのカードにも載っていない対象内routeはドリフト(self):
        self.with_cards(screens=(), operations=("projects.store", "projects.destroy"))
        self.assert_drift("どのカードにも載っていない画面: session.status")

    def test_実在しないcapabilityを挙げるとドリフト(self):
        self.with_cards(
            screens=("session.status",),
            operations=("projects.store", "projects.destroy"),
            capabilities=("ZZZ-99",),
        )
        self.assert_drift("実在しない capability を挙げている: ZZZ-99")

    def test_not_applicableのカードの割当は数えない(self):
        # 手順を持たないカードは消化カードとして数えない (F2)。
        cards = {
            "S1-signup.md": card(
                "S1", applicability="not_applicable",
                reason="本アプリに該当する面が無いため実走しない",
                reseed_before=False, accounts=(), screens=("dashboard",), body=[],
            ),
            "S2-invitation.md": card(
                "S2", surface="invitation", screens=("session.status",),
                operations=("projects.store", "projects.destroy"),
            ),
        }
        self.write_cards(cards)
        self.assert_drift("どのカードにも載っていない画面: dashboard")

    def test_複合methodのrouteは操作欄として扱われる(self):
        routes = BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])]
        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nkubun = "通常"\n')
        self.with_cards(
            screens=("session.status",),
            operations=("projects.store", "projects.destroy", "both"),
        )
        output = self.assert_drift("併せ持つ route", fake_scanner(routes))
        # 欄判定を誤らない (操作表に居るので covers_operations が正しい)。
        self.assertNotIn("欄違い", output)
        self.assertNotIn("どのカードにも載っていない", output)

    def test_未注釈のrouteがあってもKeyErrorで落ちない(self):
        self.write(
            inv.ANNOTATIONS_PATH,
            BASE_ANNOTATIONS.replace('[routes."dashboard"]\nkind = "画面"\nkubun = "通常"\n\n', ""),
        )
        output = self.assert_drift("未注釈の route: dashboard")
        self.assertNotIn("Traceback", output)

    def test_区分終は対象内なので割当が要る(self):
        annotations = BASE_ANNOTATIONS.replace(
            '[routes."projects.destroy"]\nkubun = "通常"',
            '[routes."projects.destroy"]\nkubun = "終"\n'
            'reason = "実行するとプロジェクトが消えて後続の手順が成立しなくなる終端の操作である"',
        )
        self.write(inv.ANNOTATIONS_PATH, annotations)
        self.with_cards(screens=("session.status",), operations=("projects.store",))
        self.assert_drift("どのカードにも載っていない操作: projects.destroy")

    def test_複数値セルでも段3のbyte一致が成立する(self):
        # 1 route を 2 枚のカードが消化する = セルが `S1 S2` になる。
        cards = dict(BASE_CARDS)
        cards["S1-signup.md"] = card(
            "S1", screens=("dashboard",), operations=("projects.store",),
            capabilities=("PROJ-01",),
        )
        self.write_cards(cards)
        self.generate_then()
        rows = [
            line for line in self.read(inv.OPERATIONS_PATH).splitlines()
            if "projects.store" in line and line.startswith("|")
        ]
        self.assertEqual(1, len(rows))
        self.assertEqual("S1 S2", [c.strip() for c in rows[0].strip("|").split("|")][3])
        code, output = self.run_check()
        self.assertEqual(inv.EXIT_OK, code, output)

    def test_区分終のrouteはカードに載せてよい(self):
        annotations = BASE_ANNOTATIONS.replace(
            '[routes."projects.destroy"]\nkubun = "通常"',
            '[routes."projects.destroy"]\nkubun = "終"\n'
            'reason = "実行するとプロジェクトが消えて後続の手順が成立しなくなる終端の操作である"',
        )
        self.write(inv.ANNOTATIONS_PATH, annotations)
        code, output = self.run_generate()
        self.assertEqual(inv.EXIT_OK, code, output)
        rows = [
            line for line in self.read(inv.OPERATIONS_PATH).splitlines()
            if "projects.destroy" in line and line.startswith("|")
        ]
        self.assertEqual(1, len(rows))
        self.assertEqual("S2", [c.strip() for c in rows[0].strip("|").split("|")][3])
        # `終` は対象外件数にも対象外節にも入らない。
        self.assertIn("うち対象外 1 件", self.read(inv.SCREENS_PATH))
        self.assertNotIn("`projects.destroy` —", self.read(inv.OPERATIONS_PATH))


class StoryCellTest(unittest.TestCase):
    """割当セルの表記 (書き出し側の値域の正本)。"""

    def test_単一値(self):
        self.assertEqual("S3", inv._story_cell(frozenset({"S3"})))

    def test_空集合はハイフン(self):
        self.assertEqual(inv.STORY_CELL_EMPTY, inv._story_cell(frozenset()))

    def test_複数値は番号の昇順で半角空白区切り(self):
        self.assertEqual("S3 S7", inv._story_cell(frozenset({"S7", "S3"})))

    def test_辞書順でなく数値順(self):
        # sorted() の既定は辞書順で S10 < S9 になる。S10 を足した瞬間に壊れる形を残さない。
        self.assertEqual("S9 S10", inv._story_cell(frozenset({"S10", "S9"})))

    def test_出力が値域に収まる(self):
        for value in (frozenset(), frozenset({"S1"}), frozenset({"S1", "S2", "S10"})):
            with self.subTest(value=value):
                self.assertIsNotNone(inv.STORY_CELL_RE.fullmatch(inv._story_cell(value)))


class ExitCodeContractTest(SandboxCase):
    """終了コードを原因別に固定する (「3 か 2 のどちらか」では後退を検出できない)。"""

    def assert_untouched(self, before):
        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))

    def both_entries(self, expected_code: int, needle: str):
        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
        for entry in (self.run_check, self.run_generate):
            with self.subTest(entry=entry.__name__):
                code, output = entry()
                self.assertEqual(expected_code, code, output)
                self.assertIn(needle, output)
                self.assert_untouched(before)

    def test_前付けの形式違反はドリフト(self):
        cards = dict(BASE_CARDS)
        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
            "title: 見本カード", 'title: "見本カード"'
        )
        self.write_cards(cards)
        self.both_entries(inv.EXIT_DRIFT, "スカラーに使えない文字がある")

    def test_前付けの語彙違反はドリフト(self):
        cards = dict(BASE_CARDS)
        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
            "applicability: applicable", "applicability: maybe"
        )
        self.write_cards(cards)
        self.both_entries(inv.EXIT_DRIFT, "未知の applicability")

    def test_配列内の重複はドリフト(self):
        cards = dict(BASE_CARDS)
        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
            "covers_screens: [dashboard]", "covers_screens: [dashboard, dashboard]"
        )
        self.write_cards(cards)
        self.both_entries(inv.EXIT_DRIFT, "covers_screens に重複した要素がある")

    def test_カードの置き場が無いのは致命(self):
        shutil.rmtree(self.root / inv.STORIES_DIR)
        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")

    def test_カードが1枚も無いのは致命(self):
        self.write_cards({})
        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")

    def test_カードが読み取り不能なのは致命(self):
        (self.root / inv.STORIES_DIR / "S1-signup.md").write_bytes(b"\xff\xfe\x00broken")
        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")


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
        self.assertEqual("S2", loaded["projects.store"]["story"])
        self.assertEqual("通常", loaded["projects.store"]["kubun"])
        self.assertEqual("projects", loaded["projects.store"]["operation"])

        # load_operations() は重複を「最初の定義を優先」で畳むので、重複が隠れないことを見る。
        text = self.read(inv.OPERATIONS_PATH)
        for name in loaded:
            self.assertEqual(1, text.count(f"| {name} |"), name)


if __name__ == "__main__":
    unittest.main()
