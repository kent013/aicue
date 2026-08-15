"""操作到達/コード到達カバレッジの用語・契約の後退防止 self-test。

2 群のパターンを検知する。

1. STALE_PATTERNS — 旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名
   (coverage-stage1.md / coverage-stage3.md)。対象は skill 配下の本文 (.md / .py) 全部。
2. IMPLEMENTATION_ONLY_PATTERNS — 旧 fail-open の文言 (「--executed 省略時」「未実行 candidate」)
   と旧語彙 (skipped_blocked_count / status 値としての skipped)。
   **対象から test_*.py を除外する** — 旧値を拒否する負の対照テストは、入力 fixture として
   その文字列を必要とするためである。.md は全部対象に残す。

裸の `skipped` は禁止語にしない (unittest の skipTest や無関係な英文を巻き込むため)。
誤 fail を避けるため、devnotes (設計 migration note・履歴説明) とこの test 自身は対象外。
"""

from __future__ import annotations

import re
import tempfile
import unittest
from pathlib import Path

SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/

# 旧用語パターン: 単語境界付き Stage1/Stage3 (Stage 1 / Stage 3 表記も) と旧出力ファイル名。
STALE_PATTERNS = [
    re.compile(r"Stage\s?1\b"),
    re.compile(r"Stage\s?3\b"),
    re.compile(r"coverage-stage[13]\.md"),
]

# 旧 fail-open の文言と旧語彙。実装ファイル (と全 .md) にだけ禁じる。
IMPLEMENTATION_ONLY_PATTERNS = [
    # Markdown では `--executed` と backtick で囲むのが自然な表記なので許容する。
    re.compile(r"`?--executed`?\s*(を)?省略"),
    re.compile(r"未実行\s*candidate"),
    re.compile(r"skipped_blocked_count"),
    re.compile(r"ok\|blocked\|skipped"),
    re.compile(r"['\"]skipped['\"]"),
]

# 対象外: 履歴・設計ノートは devnotes 側に隔離されている前提。skill 配下は本 test 自身のみ除外。
EXCLUDE_NAMES = {"test_naming_no_stale.py"}


def _target_files(root: Path = SKILL_ROOT) -> list[Path]:
    files: list[Path] = []
    for ext in ("*.md", "*.py"):
        for p in root.rglob(ext):
            if p.name in EXCLUDE_NAMES:
                continue
            if "devnotes" in p.parts:
                continue
            files.append(p)
    return files


def _is_test_module(path: Path) -> bool:
    return path.suffix == ".py" and path.name.startswith("test_")


def scan(root: Path = SKILL_ROOT) -> tuple[list[str], list[str]]:
    """(旧 Stage 付番の違反, 旧 fail-open 文言・旧語彙の違反) を返す。"""
    stale: list[str] = []
    impl: list[str] = []
    for path in _target_files(root):
        rel = path.relative_to(root)
        text = path.read_text(encoding="utf-8")
        for lineno, line in enumerate(text.splitlines(), start=1):
            for pat in STALE_PATTERNS:
                if pat.search(line):
                    stale.append(f"{rel}:{lineno}: {line.strip()[:80]}")
            if _is_test_module(path):
                continue
            for pat in IMPLEMENTATION_ONLY_PATTERNS:
                if pat.search(line):
                    impl.append(f"{rel}:{lineno}: {line.strip()[:80]}")
    return stale, impl


class StaleNamingTest(unittest.TestCase):
    def test_no_stage_terminology_in_skill_body(self) -> None:
        stale, _ = scan()
        self.assertEqual(
            stale,
            [],
            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(stale),
        )

    def test_no_stale_fail_open_wording_in_implementation(self) -> None:
        _, impl = scan()
        self.assertEqual(
            impl,
            [],
            "旧 fail-open の文言 / 旧語彙が実装・文書に残存:\n" + "\n".join(impl),
        )


class GateItselfTest(unittest.TestCase):
    """gate 自身が空振りしていないことの検査 (合成ファイルで判定する)。"""

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def _write(self, name: str, body: str) -> None:
        (self.root / name).write_text(body, encoding="utf-8")

    def test_detects_in_implementation_file(self) -> None:
        self._write("impl.py", "x = 1  # --executed 省略時は全 in_scope を未実行 candidate 扱い\n")
        _, impl = scan(self.root)
        # 1 行に 2 パターン一致するので件数は数えず、検出されたことだけを固定する。
        self.assertTrue(impl, "実装ファイルの旧 fail-open 文言を検出できていない")
        self.assertTrue(all(v.startswith("impl.py:1:") for v in impl), impl)

    def test_detects_old_vocabulary_in_markdown(self) -> None:
        self._write("doc.md", "status は `ok|blocked|skipped`。\n")
        _, impl = scan(self.root)
        self.assertEqual(len(impl), 1, impl)

    def test_detects_backticked_option_wording(self) -> None:
        # Markdown 表記 (`--executed` 省略時) 単独でも検出できること。
        self._write("doc.md", "`--executed` 省略時は全 in_scope を candidate 扱いにする。\n")
        _, impl = scan(self.root)
        self.assertEqual(len(impl), 1, impl)

    def test_does_not_detect_in_test_module(self) -> None:
        self._write("test_something.py", 'row = {"status": "skipped"}  # 旧値を拒否する負の対照\n')
        _, impl = scan(self.root)
        self.assertEqual(impl, [])

    def test_stale_stage_patterns_still_apply_to_test_modules(self) -> None:
        # 旧 Stage 付番は test_*.py も対象 (除外は IMPLEMENTATION_ONLY_PATTERNS だけ)。
        self._write("test_something.py", "# Stage1 の名残\n")
        stale, _ = scan(self.root)
        self.assertEqual(len(stale), 1, stale)

    def test_excluded_name_is_skipped(self) -> None:
        # 自ファイル除外 (EXCLUDE_NAMES) が効いていること (依存を暗黙にしない)。
        self._write("test_naming_no_stale.py", "# Stage1 と 未実行 candidate\n")
        stale, impl = scan(self.root)
        self.assertEqual(stale, [])
        self.assertEqual(impl, [])


if __name__ == "__main__":
    unittest.main()
