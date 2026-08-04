"""spec-ledger.md の腐り検知 (stdlib のみ)。

`spec-ledger.md` は機械 registry (`adjudications.jsonl`) の「対」であり、人間向け申し送りの正本。
台帳は放置すると腐る (根拠に書いたファイルが消える / registry に「登録済」と書いたのに実体が無い)
ため、次の 3 点だけを機械検知する:

 (1) 確定項目の必須欄が揃っているか (初回登録テンプレートの「欄を削らない」の機械化)
 (2) 根拠欄に書いたファイルが実在するか (**行番号は見ない**)
 (3) 「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在するか

(2) で行番号を検証しないのは意図的である。通常のリファクタで台帳テストが壊れる保守負債になるため。
旧 registry 18 件が「実在しないパス」を指し watch_globs invalidation が永久に発火しなかった事故
(`ledger/README.md` 運用ガード (d)) の再発防止が目的なので、**実在**だけを見れば足りる。

台帳が空 (エントリ 0 件) のときは 3 つとも vacuous に PASS する (テンプレート初期状態を壊さない)。

実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
"""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

LEDGER_DIR = Path(__file__).resolve().parent
SKILL_ROOT = LEDGER_DIR.parent
REPO_ROOT = SKILL_ROOT.parents[2]  # .claude/skills/app-bug-hunt -> repo root
SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"

ENTRY_RE = re.compile(r"^#### (?P<fid>\S+) — (?P<title>.+)$")
HEADING_RE = re.compile(r"^#{1,6} ")
FENCE_RE = re.compile(r"^\s*```")

# 初回登録テンプレートの全 9 欄。テンプレートを直したらこの定数も直す (1 対 1 の関係)。
REQUIRED_FIELDS = (
    "判定",
    "根拠 (file:line)",
    "なぜ誤検知に見えたか",
    "driver 側の再発防止",
    "watch_globs (機械 registry に載せる場合)",
    "review_after_days",
    "確定した run_id",
    "再オープン条件",
    "機械 registry",
)
# 照合は「キー文字列が本文のどこかにある」ではなく **行形式** で行う
# (本文中に同じ語が出ただけで PASS する誤検知を避ける)。
FIELD_LINE = "- **{name}**:"
FIELD_START_RE = re.compile(r"^- \*\*(?P<name>[^*]+)\*\*:")

BACKTICK_RE = re.compile(r"`([^`]+)`")
# 位置指定 (`:123-125` / `:12:5` / `#L12` / `#anchor`) は**捨てて**パス部だけを実在確認する。
# 位置記法を許容集合に入れておかないと、その記法で書かれた根拠が丸ごと検査対象外に
# すり抜けてしまう (腐りの見逃し)。
PATH_LIKE = re.compile(
    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|json|ya?ml|py|sh))(?:[:#][\w.-]*)*$"
)
ADJ_ID_RE = re.compile(r"\bA-\d{3}\b")


def _lines_outside_fences(text: str) -> list[str]:
    """コードフェンス (```) の内側を空行に潰した行リスト。

    `## 初回登録テンプレート` のプレースホルダ (`path/to/File.php` 等) を
    実エントリとして拾わないため。行番号を保つよう「除去」ではなく「空行化」する。
    """
    out: list[str] = []
    in_fence = False
    for line in text.splitlines():
        if FENCE_RE.match(line):
            in_fence = not in_fence
            out.append("")
            continue
        out.append("" if in_fence else line)
    return out


def _entries() -> list[tuple[str, str]]:
    """(finding_id, 本文) のリスト。テンプレート節 (フェンス内) は除外済み。"""
    if not SPEC_LEDGER.exists():
        raise AssertionError(f"spec-ledger.md が見つからない: {SPEC_LEDGER}")
    lines = _lines_outside_fences(SPEC_LEDGER.read_text(encoding="utf-8"))
    entries: list[tuple[str, str]] = []
    current_id: str | None = None
    body: list[str] = []
    for line in lines:
        match = ENTRY_RE.match(line)
        if match:
            if current_id is not None:
                entries.append((current_id, "\n".join(body)))
            current_id = match.group("fid")
            body = []
            continue
        if current_id is not None and HEADING_RE.match(line):
            entries.append((current_id, "\n".join(body)))
            current_id = None
            body = []
            continue
        if current_id is not None:
            body.append(line)
    if current_id is not None:
        entries.append((current_id, "\n".join(body)))
    return entries


def _field_body(entry_body: str, name: str) -> str:
    """`- **{name}**:` 欄の本文 (次の欄が始まるまでの継続行を含む)。無ければ空文字。"""
    prefix = FIELD_LINE.format(name=name)
    collected: list[str] = []
    capturing = False
    for line in entry_body.splitlines():
        if capturing:
            if FIELD_START_RE.match(line):
                break
            collected.append(line)
            continue
        if line.startswith(prefix):
            capturing = True
            collected.append(line[len(prefix) :])
    return "\n".join(collected)


def _registered_adjudication_ids() -> set[str]:
    if not ADJUDICATIONS.exists():
        return set()
    ids: set[str] = set()
    for raw in ADJUDICATIONS.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        record = json.loads(line)
        adjudication_id = record.get("adjudication_id")
        if isinstance(adjudication_id, str):
            ids.add(adjudication_id)
    return ids


class SpecLedgerTest(unittest.TestCase):
    def test_required_fields_present(self) -> None:
        """確定項目はテンプレートの全 9 欄を `- **欄名**:` の行形式で持つ。"""
        missing: list[str] = []
        for finding_id, body in _entries():
            for name in REQUIRED_FIELDS:
                prefix = FIELD_LINE.format(name=name)
                if not any(line.startswith(prefix) for line in body.splitlines()):
                    missing.append(f"{finding_id}: 欄 '{name}' が無い")
        self.assertEqual(
            missing,
            [],
            "spec-ledger.md の確定項目に必須欄の欠落:\n" + "\n".join(missing),
        )

    def test_evidence_paths_exist(self) -> None:
        """根拠欄に書いたファイルパスがリポジトリに実在する (行番号は見ない)。"""
        broken: list[str] = []
        for finding_id, body in _entries():
            evidence = _field_body(body, "根拠 (file:line)")
            for token in BACKTICK_RE.findall(evidence):
                matched = PATH_LIKE.match(token.strip())
                if matched is None:
                    continue
                path = matched.group("path")
                if not (REPO_ROOT / path).exists():
                    broken.append(f"{finding_id}: 根拠パスが実在しない: {path}")
        self.assertEqual(
            broken,
            [],
            "spec-ledger.md の根拠パスが腐っている:\n" + "\n".join(broken),
        )

    def test_registry_cross_reference_resolves(self) -> None:
        """「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在する。"""
        known = _registered_adjudication_ids()
        dangling: list[str] = []
        for finding_id, body in _entries():
            registry = _field_body(body, "機械 registry")
            if "登録済" not in registry:
                continue
            for adjudication_id in ADJ_ID_RE.findall(registry):
                if adjudication_id not in known:
                    dangling.append(
                        f"{finding_id}: {adjudication_id} が adjudications.jsonl に無い"
                    )
        self.assertEqual(
            dangling,
            [],
            "spec-ledger.md と機械 registry の相互参照が切れている:\n"
            + "\n".join(dangling),
        )


if __name__ == "__main__":
    unittest.main()
