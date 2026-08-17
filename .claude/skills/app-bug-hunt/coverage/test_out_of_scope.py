"""コード到達カバレッジの「対象外の面」の宣言 (out-of-scope.json) の契約テスト。

1 契約 1 テスト。実データ (実宣言) の妥当性と、読み取り器の拒否契約と、
CLI の終了コード契約 (実プロセス起動) を固定する。

依存は標準ライブラリのみ。実行:
    cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import out_of_scope
from out_of_scope import (
    AUDIT_DOC_REL_PATH,
    DECLARATION_REL_PATH,
    DEFAULT_DECLARATION,
    DEFAULT_REPO_ROOT,
    DeclarationError,
    covers,
    load,
    normalize,
)

MODULE_PATH = Path(out_of_scope.__file__).resolve()

# 承認済み範囲のスナップショット (施策 3 の 17 番)。
# **宣言から生成しない** — テスト側に独立に書くことで、対象外の増減が必ずこの定数の
# diff としてレビューに出るようにする。運用上の正本は JSON の側である。
APPROVED_SCOPE: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("filament-admin", ("app/Filament", "app/Providers/Filament", "app/Http/Controllers/Admin")),
    ("seo-static-delivery", ("app/Http/Controllers/Seo", "app/Providers/SeoServiceProvider.php")),
    ("inbound-webhook", ("app/Http/Controllers/Webhooks",)),
    ("mcp-oauth-interface", ("app/Mcp", "app/Passport")),
    ("rest-api", ("app/Http/Controllers/Api",)),
    ("artisan-command", ("app/Console",)),
    ("queued-job", ("app/Jobs",)),
    (
        "bughunt-external-fake",
        ("app/Http/Controllers/Testing", "app/Providers/BughuntFakesServiceProvider.php"),
    ),
)


def _long(text: str) -> str:
    """30 文字以上の説明文を作る (閾値そのものの検査は専用テストで行う)。"""
    filler = "この面をブラウザ走行で検査できない事情をここに十分な長さで説明する。"
    return text + filler


def _split_md_row(row: str) -> list[str]:
    """markdown の 1 行を、退避された区切り (\\|) を区別して分解する。

    素の split('|') では退避された区切りまで数えてしまい、表の崩壊を検出できない。
    """
    cells: list[str] = []
    buffer = ""
    escaped = False
    for char in row:
        if escaped:
            buffer += char
            escaped = False
            continue
        if char == "\\":
            escaped = True
            continue
        if char == "|":
            cells.append(buffer.strip())
            buffer = ""
            continue
        buffer += char
    cells.append(buffer.strip())
    # 先頭と末尾の縦棒による空セルを落とす。
    return cells[1:-1]


def _is_tracked(rel_path: str, tracked: frozenset[str]) -> bool:
    """追跡集合に対し、ファイルは完全一致・ディレクトリはパス要素の境界で判定する。"""
    if rel_path in tracked:
        return True
    prefix = tuple(rel_path.split("/"))
    for candidate in tracked:
        parts = tuple(candidate.split("/"))
        if len(parts) > len(prefix) and parts[: len(prefix)] == prefix:
            return True
    return False


class SyntheticRepo:
    """層 2 (実在・symlink・追跡) の検査に使う合成リポジトリ。"""

    def __init__(self, root: Path) -> None:
        self.root = root
        for rel in (
            "app/Alpha",
            "app/Beta",
            "app/Http/Controllers/Gamma",
            "tests/Feature/Alpha",
            "tests/Feature/Beta",
            ".claude/skills/app-bug-hunt/coverage",
        ):
            (root / rel).mkdir(parents=True, exist_ok=True)
        (root / "app/Alpha/Alpha.php").write_text("<?php\n", encoding="utf-8")
        (root / "app/Beta/Beta.php").write_text("<?php\n", encoding="utf-8")
        (root / "app/Http/Controllers/Gamma/Gamma.php").write_text("<?php\n", encoding="utf-8")
        (root / "tests/Feature/Alpha/AlphaTest.php").write_text("<?php\n", encoding="utf-8")
        (root / "tests/Feature/Beta/BetaTest.php").write_text("<?php\n", encoding="utf-8")
        (root / DECLARATION_REL_PATH).write_text("{}\n", encoding="utf-8")
        (root / AUDIT_DOC_REL_PATH).write_text("# audit\n", encoding="utf-8")

    def payload(self) -> dict:
        return {
            "version": 1,
            "note": "合成リポジトリ向けの宣言 (テスト用)。",
            "entries": [
                {
                    "id": "alpha",
                    "title": "アルファ面",
                    "reason": _long("アルファ面は利用者が到達しない。"),
                    "alternative_verification": _long("アルファ面の挙動は Feature テストが見る。"),
                    "verification_refs": ["tests/Feature/Alpha"],
                    "path_prefixes": ["app/Alpha"],
                },
                {
                    "id": "beta",
                    "title": "ベータ面",
                    "reason": _long("ベータ面はブラウザ操作では発火しない。"),
                    "alternative_verification": _long("ベータ面の挙動は Feature テストが見る。"),
                    "verification_refs": ["tests/Feature/Beta"],
                    "path_prefixes": ["app/Beta"],
                },
            ],
        }

    def write(self, payload: dict) -> Path:
        target = self.root / "declaration.json"
        target.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        return target

    def load(self, payload: dict):
        return load(self.write(payload), self.root)


class SyntheticCase(unittest.TestCase):
    """合成リポジトリを持つテストの土台。"""

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = SyntheticRepo(Path(self.tmp.name))

    def assertRejects(self, payload: dict, hint: str) -> None:
        with self.assertRaises(DeclarationError, msg=hint):
            self.repo.load(payload)

    def valid(self) -> dict:
        return self.repo.payload()


class RealDeclarationTest(unittest.TestCase):
    """1 / 17 / 23: 実データそのものの契約。"""

    def setUp(self) -> None:
        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)

    def test_1_real_declaration_loads(self) -> None:
        self.assertEqual(self.declaration.version, 1)
        self.assertTrue(self.declaration.entries)

    def test_17_matches_approved_scope_snapshot(self) -> None:
        actual = tuple((e.id, e.path_prefixes) for e in self.declaration.entries)
        self.assertEqual(
            actual,
            APPROVED_SCOPE,
            "対象外の面が承認済み範囲と食い違う (増減はどちらでも赤にする)",
        )

    def test_23_audit_document_does_not_copy_the_list(self) -> None:
        audit = (DEFAULT_REPO_ROOT / AUDIT_DOC_REL_PATH).read_text(encoding="utf-8")
        leaked: list[str] = []
        for entry in self.declaration.entries:
            for literal in (entry.id, entry.title, *entry.path_prefixes):
                if literal in audit:
                    leaked.append(literal)
        self.assertEqual(leaked, [], "監査文書に対象外の面の一覧が複製されている: " + str(leaked))


class TrackedRefsTest(unittest.TestCase):
    """14 / 14b: 代替検証と対象パスが git の追跡下にあること。"""

    def setUp(self) -> None:
        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)

    def _tracked(self) -> frozenset[str]:
        proc = subprocess.run(
            ["git", "-C", str(DEFAULT_REPO_ROOT), "ls-files", "-z"],
            capture_output=True,
        )
        # git が使えない環境は skip ではなく fail にする (環境不備を隠さない)。
        self.assertEqual(proc.returncode, 0, "git ls-files を実行できない: " + proc.stderr.decode())
        return frozenset(p for p in proc.stdout.decode("utf-8").split("\0") if p)

    def test_14_refs_and_prefixes_are_tracked(self) -> None:
        tracked = self._tracked()
        untracked: list[str] = []
        for entry in self.declaration.entries:
            for rel in (*entry.verification_refs, *entry.path_prefixes):
                if not _is_tracked(rel, tracked):
                    untracked.append(rel)
        self.assertEqual(untracked, [], "追跡下にないパスが宣言されている: " + str(untracked))

    def test_14b_directory_tracking_uses_segment_boundary(self) -> None:
        tracked = frozenset({"tests/Foobar/Test.php"})
        self.assertFalse(_is_tracked("tests/Foo", tracked))
        self.assertTrue(_is_tracked("tests/Foobar", tracked))


class RequiredKeysTest(SyntheticCase):
    """2 / 3: 必須キーの欠落と未知キー。"""

    def test_2_missing_top_level_key_is_rejected(self) -> None:
        for key in ("version", "note", "entries"):
            payload = self.valid()
            del payload[key]
            self.assertRejects(payload, f"トップレベル {key} の欠落を通した")

    def test_2_missing_entry_key_is_rejected(self) -> None:
        for key in (
            "id",
            "title",
            "reason",
            "alternative_verification",
            "verification_refs",
            "path_prefixes",
        ):
            payload = self.valid()
            del payload["entries"][0][key]
            self.assertRejects(payload, f"entry の {key} 欠落を通した")

    def test_3_unknown_key_is_rejected(self) -> None:
        payload = self.valid()
        payload["extra"] = 1
        self.assertRejects(payload, "トップレベルの未知キーを通した")

        payload = self.valid()
        payload["entries"][0]["extra"] = 1
        self.assertRejects(payload, "entry の未知キーを通した")


class TypeContractTest(SyntheticCase):
    """4 / 5: 型の厳密判定。"""

    def test_4_wrong_types_are_rejected(self) -> None:
        self.assertRejects([], "トップレベルが配列でも通した")

        payload = self.valid()
        payload["entries"] = {"alpha": {}}
        self.assertRejects(payload, "entries が object でも通した")

        payload = self.valid()
        payload["entries"] = []
        self.assertRejects(payload, "entries が空でも通した")

        payload = self.valid()
        payload["entries"][0]["title"] = 12345
        self.assertRejects(payload, "文字列欄が数値でも通した")

        payload = self.valid()
        payload["entries"][0]["verification_refs"] = [12345]
        self.assertRejects(payload, "配列要素が非文字列でも通した")

        payload = self.valid()
        payload["entries"][0]["title"] = "   "
        self.assertRejects(payload, "空白だけの文字列を通した")

        payload = self.valid()
        payload["entries"][0] = "alpha"
        self.assertRejects(payload, "entry が文字列でも通した")

    def test_5_version_must_be_the_integer_one(self) -> None:
        for bad in (2, 0, "1", 1.0, True):
            payload = self.valid()
            payload["version"] = bad
            self.assertRejects(payload, f"version={bad!r} を通した")


class IdentifierTest(SyntheticCase):
    """6: id の書式と一意性。"""

    def test_6_bad_id_format_is_rejected(self) -> None:
        for bad in (
            "Alpha",
            "-alpha",
            "alpha-",
            "alpha--beta",
            "alpha_beta",
            "alpha/beta",
            "",
            "アルファ",
        ):
            payload = self.valid()
            payload["entries"][0]["id"] = bad
            self.assertRejects(payload, f"id={bad!r} を通した")

    def test_6_duplicate_id_is_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][1]["id"] = payload["entries"][0]["id"]
        self.assertRejects(payload, "id の重複を通した")


class StatementTest(SyntheticCase):
    """7: 理由と代替検証の中身。"""

    def test_7_short_statement_is_rejected(self) -> None:
        for key in ("reason", "alternative_verification"):
            payload = self.valid()
            payload["entries"][0][key] = "短い理由"
            self.assertRejects(payload, f"{key} が 30 文字未満でも通した")

    def test_7_hollow_statement_is_rejected(self) -> None:
        for hollow in ("対象外", "なし", "-", "N/A", "TBD"):
            payload = self.valid()
            payload["entries"][0]["reason"] = hollow
            self.assertRejects(payload, f"無内容な理由 {hollow!r} を通した")


class PathPrefixTest(SyntheticCase):
    """8 / 9 / 10 / 11: 対象パスの制約。"""

    def test_8_empty_missing_or_outside_app_is_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = []
        self.assertRejects(payload, "path_prefixes が空でも通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/NoSuchDirectory"]
        self.assertRejects(payload, "不在の対象パスを通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["tests/Feature/Alpha"]
        self.assertRejects(payload, "app/ の外の対象パスを通した")

    def test_9_symlinks_and_missing_paths_are_rejected(self) -> None:
        outside = Path(self.tmp.name).parent / "outside-target"
        outside.mkdir(exist_ok=True)
        self.addCleanup(outside.rmdir)

        (self.repo.root / "app/OutsideLink").symlink_to(outside, target_is_directory=True)
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/OutsideLink"]
        self.assertRejects(payload, "repo の外を指す symlink を通した")

        (self.repo.root / "app/InsideLink").symlink_to(
            self.repo.root / "app/Beta", target_is_directory=True
        )
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/InsideLink"]
        self.assertRejects(payload, "repo の内を指す symlink を通した")

        (self.repo.root / "app/LinkedParent").symlink_to(
            self.repo.root / "app/Http", target_is_directory=True
        )
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/LinkedParent/Controllers/Gamma"]
        self.assertRejects(payload, "親ディレクトリが symlink の対象パスを通した")

    def test_10_containment_and_duplicates_across_entries_are_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][1]["path_prefixes"] = ["app/Alpha/Deeper"]
        (self.repo.root / "app/Alpha/Deeper").mkdir()
        self.assertRejects(payload, "entry を跨いだ包含関係を通した")

        payload = self.valid()
        payload["entries"][1]["path_prefixes"] = ["app/Alpha"]
        self.assertRejects(payload, "entry を跨いだ完全重複を通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/Alpha", "app/Alpha"]
        self.assertRejects(payload, "entry 内の完全重複を通した")

    def test_11_trunk_prefixes_are_rejected(self) -> None:
        for trunk in ("app", "app/Http", "app/Http/Controllers"):
            payload = self.valid()
            payload["entries"][0]["path_prefixes"] = [trunk]
            self.assertRejects(payload, f"幹 {trunk} を通した")


class VerificationRefsTest(SyntheticCase):
    """12 / 13: 代替検証の参照。"""

    def test_12_empty_missing_or_duplicated_refs_are_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][0]["verification_refs"] = []
        self.assertRejects(payload, "verification_refs が空でも通した")

        payload = self.valid()
        payload["entries"][0]["verification_refs"] = ["tests/Feature/NoSuchTest.php"]
        self.assertRejects(payload, "不在の代替検証を通した")

        payload = self.valid()
        payload["entries"][1]["verification_refs"] = ["tests/Feature/Alpha"]
        self.assertRejects(payload, "宣言内での重複を通した")

    def test_13_self_referencing_refs_are_rejected(self) -> None:
        for circular in (DECLARATION_REL_PATH, AUDIT_DOC_REL_PATH, "app/Beta"):
            payload = self.valid()
            payload["entries"][0]["verification_refs"] = [circular]
            self.assertRejects(payload, f"循環参照 {circular} を通した")

    def test_13_ancestor_of_self_reference_is_rejected(self) -> None:
        # 子方向だけを見ると、対象外の面や宣言自身を内包する**祖先**を書いてすり抜けられる。
        for ancestor in ("app", ".claude/skills/app-bug-hunt/coverage", ".claude"):
            payload = self.valid()
            payload["entries"][0]["verification_refs"] = [ancestor]
            self.assertRejects(payload, f"祖先による自己言及 {ancestor} を通した")


class NormalizeTest(unittest.TestCase):
    """15: 層 1 (字句の正規形) と covers のセグメント境界。"""

    def test_15_non_canonical_paths_are_rejected(self) -> None:
        for bad in (
            "/app/Filament",
            "app/../../etc",
            "app/./Filament",
            "app//Filament",
            "app/Filament/",
            "app\\Filament",
            "..",
            ".",
            "",
            "   ",
        ):
            with self.assertRaises(DeclarationError, msg=f"{bad!r} を通した"):
                normalize(bad)

    def test_15_normalize_returns_segments(self) -> None:
        self.assertEqual(normalize("app/Http/Controllers/Api"), ("app", "Http", "Controllers", "Api"))

    def test_15_covers_uses_segment_boundary(self) -> None:
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        matched = covers(declaration, "app/Filament/Resources/Foo.php")
        self.assertIsNotNone(matched)
        self.assertIsNone(covers(declaration, "app/Filamentary/Foo.php"))
        self.assertIsNone(covers(declaration, "app/Services/Manual/ScenarioService.php"))

    def test_15_covers_rejects_non_canonical_argument(self) -> None:
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        with self.assertRaises(DeclarationError):
            covers(declaration, "app/../app/Filament")


class InputFailureTest(SyntheticCase):
    """16: 入力障害が DeclarationError へ収束すること。"""

    def test_16_missing_file(self) -> None:
        with self.assertRaises(DeclarationError):
            load(self.repo.root / "no-such-file.json", self.repo.root)

    def test_16_invalid_utf8(self) -> None:
        target = self.repo.root / "broken.json"
        target.write_bytes(b"\xff\xfe{ invalid }")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_broken_json(self) -> None:
        target = self.repo.root / "broken2.json"
        target.write_text("{ this is not json", encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_deeply_nested_json(self) -> None:
        target = self.repo.root / "deep.json"
        target.write_text("[" * 200000, encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_unresolvable_repo_root_is_converted(self) -> None:
        # パス解決そのものの失敗 (ここでは埋め込み NUL) も DeclarationError へ収束する。
        # 素の resolve() へ戻すと ValueError が漏れてこのテストが赤くなる (負の対照)。
        declaration_path = self.repo.write(self.valid())
        with self.assertRaises(DeclarationError):
            load(declaration_path, str(self.repo.root) + "/broken\x00root")

    def test_16_duplicate_json_keys_are_rejected(self) -> None:
        # json.loads は重複キーを黙って後勝ちで畳む。レビューで見えている値と
        # 実際に採用される値がずれるので拒否する。
        valid = json.dumps(self.valid(), ensure_ascii=False)
        top_level = valid[:-1] + ', "version": 1}'
        target = self.repo.root / "dup-top.json"
        target.write_text(top_level, encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

        entry = json.dumps(self.valid()["entries"][0], ensure_ascii=False)
        duplicated = entry[:-1] + ', "id": "gamma"}'
        payload = json.dumps(self.valid(), ensure_ascii=False)
        target = self.repo.root / "dup-entry.json"
        target.write_text(
            payload.replace(entry, duplicated, 1),
            encoding="utf-8",
        )
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)


class CliTest(SyntheticCase):
    """1b / 18 / 19 / 20 / 21 / 22: CLI の契約 (実プロセス起動)。"""

    def _run(self, args: list[str]) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(MODULE_PATH), *args],
            capture_output=True,
            text=True,
            cwd=str(MODULE_PATH.parent),
        )

    def test_1b_runs_with_default_paths(self) -> None:
        proc = self._run([])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertTrue(proc.stdout.strip())

    def test_18_emit_json_matches_normalized_data(self) -> None:
        proc = self._run(["--emit", "json"])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        payload = json.loads(proc.stdout)
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        self.assertEqual(payload["version"], declaration.version)
        self.assertEqual(payload["note"], declaration.note)
        self.assertEqual(
            [e["id"] for e in payload["entries"]],
            [e.id for e in declaration.entries],
        )
        self.assertEqual(
            [tuple(e["path_prefixes"]) for e in payload["entries"]],
            [e.path_prefixes for e in declaration.entries],
        )

    def test_19_emit_markdown_contains_every_entry(self) -> None:
        proc = self._run(["--emit", "markdown"])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        for entry in declaration.entries:
            for literal in (
                entry.title,
                entry.reason,
                entry.alternative_verification,
                *entry.path_prefixes,
            ):
                self.assertIn(literal, proc.stdout, f"markdown に {literal} が現れない")

    def test_19_emit_markdown_keeps_column_count(self) -> None:
        payload = self.valid()
        # 縦棒・素の改行・Unicode の行区切り (U+2028) をすべて 1 つのセルへ入れる。
        payload["entries"][0]["reason"] = _long("縦棒 | と\n改行と\u2028行区切りを含む理由。")
        declaration_path = self.repo.write(payload)
        proc = self._run(
            [
                "--declaration",
                str(declaration_path),
                "--repo-root",
                str(self.repo.root),
                "--emit",
                "markdown",
            ]
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        rows = [line for line in proc.stdout.splitlines() if line.startswith("|")]
        self.assertGreaterEqual(len(rows), 4)
        widths = {len(_split_md_row(row)) for row in rows}
        self.assertEqual(len(widths), 1, f"列数が揃っていない: {widths}")

    def test_20_invalid_declaration_is_fail_closed(self) -> None:
        payload = self.valid()
        payload["entries"][0]["reason"] = "短い"
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertTrue(proc.stderr.strip())
        self.assertEqual(len(proc.stderr.strip().splitlines()), 1, proc.stderr)
        self.assertNotIn("Traceback", proc.stderr)

    def test_20_symlink_loop_is_fail_closed(self) -> None:
        # symlink の輪も symlink の禁止で先に落ちる (パス解決まで到達しない)。
        # 解決そのものの失敗が DeclarationError へ収束することは
        # test_16_unresolvable_repo_root_is_converted が担当する。
        (self.repo.root / "app/LoopA").symlink_to(self.repo.root / "app/LoopB")
        (self.repo.root / "app/LoopB").symlink_to(self.repo.root / "app/LoopA")
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/LoopA"]
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertNotIn("Traceback", proc.stderr)

    def test_20_unicode_line_separator_keeps_single_line_stderr(self) -> None:
        # 値に混ぜられた行区切り 1 文字で「stderr は 1 行」の契約を壊されないこと。
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/Alpha\u2028Missing"]
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertEqual(len(proc.stderr.strip().splitlines()), 1, proc.stderr)

    def test_21_unknown_emit_value_is_fail_closed(self) -> None:
        proc = self._run(["--emit", "no-such-format"])
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertNotIn("Traceback", proc.stderr)

    def test_22_wrong_repo_root_fails(self) -> None:
        proc = self._run(["--repo-root", str(self.repo.root)])
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")


if __name__ == "__main__":
    unittest.main()
