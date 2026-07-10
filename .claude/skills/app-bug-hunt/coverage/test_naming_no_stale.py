"""操作到達/コード到達カバレッジへの命名統一の後退防止 self-test。

旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名 (coverage-stage1.md / coverage-stage3.md) が
skill 本文ファイルに再混入していないことを機械検知する。

対象は `.claude/skills/app-bug-hunt/` 配下の本文 (.md / .py)。誤 fail を避けるため、
devnotes (設計 migration note・履歴説明) とこの test 自身は対象外にする。
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/

# 旧用語パターン: 単語境界付き Stage1/Stage3 (Stage 1 / Stage 3 表記も) と旧出力ファイル名。
STALE_PATTERNS = [
    re.compile(r"Stage\s?1\b"),
    re.compile(r"Stage\s?3\b"),
    re.compile(r"coverage-stage[13]\.md"),
]

# 対象外: 履歴・設計ノートは devnotes 側に隔離されている前提。skill 配下は本 test 自身のみ除外。
EXCLUDE_NAMES = {"test_naming_no_stale.py"}


def _target_files() -> list[Path]:
    files: list[Path] = []
    for ext in ("*.md", "*.py"):
        for p in SKILL_ROOT.rglob(ext):
            if p.name in EXCLUDE_NAMES:
                continue
            if "devnotes" in p.parts:
                continue
            files.append(p)
    return files


class StaleNamingTest(unittest.TestCase):
    def test_no_stage_terminology_in_skill_body(self) -> None:
        offenders: list[str] = []
        for path in _target_files():
            text = path.read_text(encoding="utf-8")
            for lineno, line in enumerate(text.splitlines(), start=1):
                for pat in STALE_PATTERNS:
                    if pat.search(line):
                        rel = path.relative_to(SKILL_ROOT)
                        offenders.append(f"{rel}:{lineno}: {line.strip()[:80]}")
        self.assertEqual(
            offenders,
            [],
            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(offenders),
        )


if __name__ == "__main__":
    unittest.main()
