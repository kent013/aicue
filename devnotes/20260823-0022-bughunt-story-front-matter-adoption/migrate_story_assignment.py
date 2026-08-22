#!/usr/bin/env python3
"""シナリオカードへの割当移行の変換器兼検算器 (使い捨て。恒久スクリプトにしない)。

移行の中身は「割当の正本を `inventory/annotations.toml` の `story` から
`stories/S*.md` の前付け (`covers_screens` / `covers_operations`) へ移す」ことである。

    generate … 撤去前の注釈と抽出結果から、6 枚分の covers_* の初期値を作る (手写しをしない)
    verify   … 撤去後の前付けから逆引きした関係を、撤去前の関係と突き合わせる

「変換前」の観測点は **git の HEAD** (= worktree を切った時点の main) に置く。
作業ツリーの注釈は移行で書き換わるため、そこを根拠にすると自分で自分を検算することになる。

判定 (verify) は次の 4 つがすべて成り立つときだけ成功とする。

  1. 「変換前のみ」が 0 件 (既存 6 カードの割当が 1 件も落ちていない)
  2. 「変換後のみ」が EXPECTED_S7_PRIOR_* のキー集合と欄別に完全一致
  3. その各 route について before == EXPECTED_S7_PRIOR_*[route] かつ after == before | {"S7"}
  4. 対象外 (kubun = 外) の route が両側とも空集合

依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
import tomllib
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SKILL_DIR = REPO_ROOT / ".claude/skills/app-bug-hunt"
STORIES_DIR = SKILL_DIR / "stories"
ANNOTATIONS_REL = ".claude/skills/app-bug-hunt/inventory/annotations.toml"
CATALOG_PATH = SKILL_DIR / "capability-catalog.md"

GET_LIKE_METHODS = ("GET", "HEAD", "OPTIONS")
KUBUN_OUT_OF_SCOPE = "外"

CAPABILITY_TABLE_HEADER = "| id | 機能 (actor→outcome) | 代表機構 (route name) |"
BACKTICK_TOKEN_RE = re.compile(r"`([^`]+)`")
PATH_TOKEN_RE = re.compile(r"^[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)+\.[A-Za-z0-9]+$")

# S7 が組織 B 視点で踏み直す画面と操作の「変換前の割当」。
# 全件が元から S3 または S4 の消化対象であることを機械で閉じる
# (before が空の route へ S7 だけを足す形を合格にしないため)。
EXPECTED_S7_PRIOR_SCREENS = {
    "capture.manuals.show": frozenset({"S3"}),
    "capture.takes.playback": frozenset({"S3"}),
    "projects.categories.index": frozenset({"S4"}),
    "projects.edit": frozenset({"S4"}),
    "projects.manuals.download": frozenset({"S3"}),
    "projects.manuals.edit": frozenset({"S3"}),
    "projects.manuals.jobs.show": frozenset({"S3"}),
    "projects.manuals.render-jobs.playback": frozenset({"S3"}),
    "projects.manuals.render-jobs.show": frozenset({"S3"}),
    "projects.manuals.show": frozenset({"S3"}),
    "projects.show": frozenset({"S3"}),
}
# S7 が踏み直す 11 画面の選定根拠 (境界の種別で分類する)。
# `projects.edit` と `projects.show` は「nested child」ではなく project 自身の画面なので、
# 現行カードの散文 (「全 nested screen」) とのずれが読み手に見えるように残す。
S7_SCREEN_RATIONALE = (
    ("project 自身の current-org 境界", ("projects.show", "projects.edit")),
    (
        "project 配下 manual の親子境界",
        ("projects.manuals.show", "projects.manuals.edit", "projects.manuals.download"),
    ),
    (
        "manual 配下の take / render / job の親子境界",
        (
            "projects.manuals.jobs.show",
            "projects.manuals.render-jobs.show",
            "projects.manuals.render-jobs.playback",
        ),
    ),
    ("project 配下 category の親子境界", ("projects.categories.index",)),
    ("capture 経由で manual / take へ到達する境界", ("capture.manuals.show", "capture.takes.playback")),
)

EXPECTED_S7_PRIOR_OPERATIONS = {
    "capture.takes.adopt": frozenset({"S3"}),
    "capture.takes.destroy": frozenset({"S3"}),
    "projects.categories.destroy": frozenset({"S4"}),
    "projects.categories.reorder": frozenset({"S4"}),
    "projects.categories.update": frozenset({"S4"}),
    "projects.manuals.destroy": frozenset({"S3"}),
    "projects.manuals.duplicate": frozenset({"S3"}),
    "projects.manuals.scenario.update": frozenset({"S3"}),
    "projects.manuals.update": frozenset({"S3"}),
}


# --------------------------------------------------------------------------- #
# 入力
# --------------------------------------------------------------------------- #
def git_show(rev_path: str) -> str:
    """git の版から内容を取る (変換前の観測点)。"""
    proc = subprocess.run(
        ["git", "show", rev_path],
        cwd=REPO_ROOT, capture_output=True, text=True,
    )
    if proc.returncode != 0:
        raise SystemExit(f"git show {rev_path} が失敗した: {proc.stderr.strip()}")

    return proc.stdout


def head_commit() -> str:
    proc = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=REPO_ROOT, capture_output=True, text=True,
    )

    return proc.stdout.strip()


def load_scan(scan_json: Path | None) -> dict:
    """抽出結果 (route 名 / method / middleware) を読む。"""
    if scan_json is not None:
        return json.loads(scan_json.read_text(encoding="utf-8"))

    proc = subprocess.run(
        ["php", "artisan", "bughunt:inventory-scan"],
        cwd=REPO_ROOT, capture_output=True, text=True,
    )
    if proc.returncode != 0:
        raise SystemExit(f"抽出コマンドが非 0 終了: {proc.stderr.strip()}")

    return json.loads(proc.stdout)


def surface_methods(scan: dict) -> dict[str, tuple[str, ...]]:
    """web 面の route 名 -> HTTP method の並び。"""
    out: dict[str, tuple[str, ...]] = {}
    for raw in scan["routes"]:
        name = raw.get("name")
        uri = raw.get("uri", "")
        segment = uri.split("/", 1)[0]
        if not isinstance(name, str) or not name:
            continue
        if "web" not in raw.get("middleware", []):
            continue
        if segment == "oauth" or segment.startswith("livewire"):
            continue
        out[name] = tuple(str(m) for m in raw.get("methods", []))

    return out


def is_safe(methods: tuple[str, ...]) -> bool:
    """safe method だけの route か (画面側の母集合)。"""
    return not [m for m in methods if m not in GET_LIKE_METHODS]


def load_annotations(text: str) -> dict[str, dict[str, object]]:
    data = tomllib.loads(text)

    return {str(k): dict(v) for k, v in data.get("routes", {}).items()}


def load_catalog_capabilities(text: str) -> dict[str, list[str]]:
    """capability_id -> 代表機構の route 名トークン (末尾 `*` は前方一致)。"""
    out: dict[str, list[str]] = {}
    inside = False
    for raw in text.splitlines():
        line = raw.strip()
        if line == CAPABILITY_TABLE_HEADER:
            inside = True
            continue
        if not inside:
            continue
        if not line.startswith("|"):
            inside = False
            continue
        cols = [c.strip() for c in line.strip("|").split("|")]
        if len(cols) < 3 or set("".join(cols)) <= set("- "):
            continue
        tokens = [
            t.strip() for t in BACKTICK_TOKEN_RE.findall(cols[2])
            if not PATH_TOKEN_RE.match(t.strip())
        ]
        if tokens:
            out.setdefault(cols[0], []).extend(tokens)

    return out


# --------------------------------------------------------------------------- #
# 変換前の関係 (注釈由来)
# --------------------------------------------------------------------------- #
def prior_relation(
    annotations: dict[str, dict[str, object]], methods: dict[str, tuple[str, ...]]
) -> tuple[dict[str, frozenset[str]], dict[str, frozenset[str]]]:
    """注釈の story から欄別の route -> カード集合を作る。

    欄は**変換前の HTTP method** から導く (前付け側の値を根拠にしない)。
    対象内の route は全件をキーに置く (割当が無ければ空集合)。
    """
    screens: dict[str, frozenset[str]] = {}
    operations: dict[str, frozenset[str]] = {}
    for name, entry in annotations.items():
        if name not in methods:
            continue
        story = entry.get("story")
        assigned = frozenset({str(story)}) if isinstance(story, str) else frozenset()
        target = screens if is_safe(methods[name]) else operations
        target[name] = assigned

    return screens, operations


# --------------------------------------------------------------------------- #
# 変換後の関係 (前付け由来)
# --------------------------------------------------------------------------- #
def load_front_matter_relation(
    methods: dict[str, tuple[str, ...]],
) -> tuple[dict[str, frozenset[str]], dict[str, frozenset[str]]]:
    """カードの前付けから欄別の route -> カード集合を逆引きする。"""
    sys.path.insert(0, str(STORIES_DIR))
    try:
        import story_front_matter as sfm
    finally:
        sys.path.pop(0)

    cards, violations = sfm.read_cards(STORIES_DIR)
    if violations:
        raise SystemExit("前付けが読めない:\n" + "\n".join(violations))

    screens: dict[str, set[str]] = {name: set() for name in methods if is_safe(methods[name])}
    operations: dict[str, set[str]] = {name: set() for name in methods if not is_safe(methods[name])}
    for card in cards:
        card_id = str(card.front_matter["id"])
        if card.front_matter["applicability"] != "applicable":
            continue
        for key, pool in (("covers_screens", screens), ("covers_operations", operations)):
            for name in card.front_matter[key]:  # type: ignore[union-attr]
                pool.setdefault(str(name), set()).add(card_id)

    return (
        {k: frozenset(v) for k, v in screens.items()},
        {k: frozenset(v) for k, v in operations.items()},
    )


# --------------------------------------------------------------------------- #
# generate
# --------------------------------------------------------------------------- #
def run_generate(scan_json: Path | None) -> int:
    """撤去前の注釈から 6 枚分の covers_* を起こし、S7 の追加分を併せて出す。"""
    methods = surface_methods(load_scan(scan_json))
    annotations = load_annotations(git_show(f"HEAD:{ANNOTATIONS_REL}"))
    catalog = load_catalog_capabilities(CATALOG_PATH.read_text(encoding="utf-8"))

    screens, operations = prior_relation(annotations, methods)
    per_card: dict[str, dict[str, list[str]]] = {}
    for label, relation in (("covers_screens", screens), ("covers_operations", operations)):
        for name, assigned in relation.items():
            for card_id in assigned:
                per_card.setdefault(card_id, {}).setdefault(label, []).append(name)

    # S7 の追加分を重ねる (カード本文の散文から起こした route 名の固定リスト)。
    for label, expected in (
        ("covers_screens", EXPECTED_S7_PRIOR_SCREENS),
        ("covers_operations", EXPECTED_S7_PRIOR_OPERATIONS),
    ):
        bucket = per_card.setdefault("S7", {}).setdefault(label, [])
        for name in expected:
            if name not in bucket:
                bucket.append(name)

    for card_id in sorted(per_card, key=lambda s: int(s[1:])):
        block = per_card[card_id]
        routes = sorted(set(block.get("covers_screens", [])) | set(block.get("covers_operations", [])))
        capabilities = sorted({
            cap for cap, tokens in catalog.items()
            for token in tokens
            if (
                any(r.startswith(token[:-1]) for r in routes)
                if token.endswith("*")
                else token in routes
            )
        })
        print(f"--- {card_id} ---")
        for label in ("covers_screens", "covers_operations"):
            print(f"{label}: [{', '.join(sorted(block.get(label, [])))}]")
        print(f"covers_capabilities: [{', '.join(capabilities)}]")
        print()

    return 0


# --------------------------------------------------------------------------- #
# verify
# --------------------------------------------------------------------------- #
def _cell(value: frozenset[str]) -> str:
    return " ".join(sorted(value, key=lambda s: int(s[1:]))) if value else "(空)"


def steps_section(text: str) -> str:
    """`## 手順` 節の本文を取り出す。

    見出し行の**次の行**から、次に現れる H2 見出し (`## ` で始まる行) の**直前の行**まで。
    末尾の空行は落とさない (空行の増減も差分として検出する)。次の H2 が無ければ末尾まで。
    """
    lines = text.splitlines(keepends=True)
    start = None
    for index, line in enumerate(lines):
        if line.startswith("## 手順"):
            start = index + 1
            break
    if start is None:
        return ""
    end = len(lines)
    for index in range(start, len(lines)):
        if lines[index].startswith("## "):
            end = index
            break

    return "".join(lines[start:end])


def run_verify(scan_json: Path | None, out_path: Path) -> int:
    methods = surface_methods(load_scan(scan_json))
    annotations = load_annotations(git_show(f"HEAD:{ANNOTATIONS_REL}"))
    before_screens, before_operations = prior_relation(annotations, methods)
    after_screens, after_operations = load_front_matter_relation(methods)

    failures: list[str] = []
    rows: list[str] = []
    summary: list[str] = []
    expected_rows: list[str] = []

    for label, before, after, expected in (
        ("screens", before_screens, after_screens, EXPECTED_S7_PRIOR_SCREENS),
        ("operations", before_operations, after_operations, EXPECTED_S7_PRIOR_OPERATIONS),
    ):
        if set(before) != set(after):
            failures.append(
                f"{label}: 母集合が一致しない "
                f"(変換前のみ {sorted(set(before) - set(after))} / "
                f"変換後のみ {sorted(set(after) - set(before))})"
            )
        same = lost = added = 0
        added_routes: set[str] = set()
        for name in sorted(set(before) | set(after)):
            b, a = before.get(name, frozenset()), after.get(name, frozenset())
            if b == a:
                same += 1
                verdict = "一致"
            elif b - a:
                lost += 1
                verdict = "**変換前のみ (落ちた)**"
                failures.append(f"{label} {name}: 割当が落ちた ({_cell(b)} -> {_cell(a)})")
            else:
                added += 1
                added_routes.add(name)
                verdict = "変換後のみ (S7 の追加分)"
            if b != a or name in expected:
                rows.append(f"| {label} | {name} | {_cell(b)} | {_cell(a)} | {verdict} |")

        if added_routes != set(expected):
            failures.append(
                f"{label}: 変換後のみの集合が期待と一致しない "
                f"(不足 {sorted(set(expected) - added_routes)} / "
                f"余分 {sorted(added_routes - set(expected))})"
            )
        for name, prior in expected.items():
            b, a = before.get(name, frozenset()), after.get(name, frozenset())
            if b != prior:
                failures.append(f"{label} {name}: 変換前の割当が期待と違う ({_cell(b)} != {_cell(prior)})")
            if a != b | {"S7"}:
                failures.append(f"{label} {name}: 変換後が before | {{S7}} でない ({_cell(a)})")
        summary.append(f"| {label} | {same} | {lost} | {added} |")
        expected_rows.append(
            f"| {label} | {len(expected)} 件 ({', '.join(sorted(expected))}) | "
            f"{len(added_routes)} 件 | {'一致' if added_routes == set(expected) else '**不一致**'} |"
        )

    # 対象外 (kubun = 外) の route は両側とも空集合であること。
    out_rows: list[str] = []
    for name, entry in sorted(annotations.items()):
        if entry.get("kubun") != KUBUN_OUT_OF_SCOPE or name not in methods:
            continue
        b = (before_screens | before_operations).get(name, frozenset())
        a = (after_screens | after_operations).get(name, frozenset())
        out_rows.append(f"| {name} | {_cell(b)} | {_cell(a)} |")
        if b or a:
            failures.append(f"対象外 route に割当がある: {name} ({_cell(b)} / {_cell(a)})")

    # `## 手順` 節の不変 (移行前後の sha256)。
    step_rows: list[str] = []
    for card in sorted(STORIES_DIR.glob("S*.md")):
        rel = card.relative_to(REPO_ROOT)
        before_text = steps_section(git_show(f"HEAD:{rel.as_posix()}"))
        after_text = steps_section(card.read_text(encoding="utf-8"))
        b = hashlib.sha256(before_text.encode("utf-8")).hexdigest()
        a = hashlib.sha256(after_text.encode("utf-8")).hexdigest()
        step_rows.append(f"| {card.name.split('-')[0]} | `{b[:16]}` | `{a[:16]}` | {'一致' if b == a else '**不一致**'} |")
        if b != a:
            failures.append(f"{card.name}: `## 手順` 節が変わっている")

    verdict = "成功" if not failures else "失敗"
    doc = [
        "# 移行の検算",
        "",
        "`devnotes/20260823-0022-bughunt-story-front-matter-adoption/migrate_story_assignment.py verify`",
        "の出力である (手で書かない)。",
        "",
        f"- 変換前の観測点: `{head_commit()}` の `{ANNOTATIONS_REL}`",
        f"- 判定: **{verdict}**",
        "",
        "## 全差分 (欄 / route / 変換前 / 変換後)",
        "",
        "| 欄 | route | 変換前 | 変換後 | 判定 |",
        "|---|---|---|---|---|",
        *rows,
        "",
        "## 集計",
        "",
        "| 欄 | 一致 | 変換前のみ (落ちた) | 変換後のみ (S7 の追加分) |",
        "|---|---|---|---|",
        *summary,
        "",
        "## 期待する S7 追加分との完全一致",
        "",
        "| 欄 | 期待 | 実測 | 判定 |",
        "|---|---|---|---|",
        *expected_rows,
        "",
        "## 対象外 route (両側とも空集合であること)",
        "",
        "| route | 変換前 | 変換後 |",
        "|---|---|---|",
        *out_rows,
        "",
        "## S7 が踏み直す 11 画面の選定根拠",
        "",
        "| 境界の種別 | route |",
        "|---|---|",
        *[
            f"| {label} | {' / '.join(f'`{r}`' for r in routes)} |"
            for label, routes in S7_SCREEN_RATIONALE
        ],
        "",
        "## `## 手順` 節の不変 (移行前後の sha256。先頭 16 文字)",
        "",
        "| カード | 移行前 | 移行後 | 判定 |",
        "|---|---|---|---|",
        *step_rows,
        "",
    ]
    if failures:
        doc += ["## 失敗の内訳", "", *[f"- {f}" for f in failures], ""]
    out_path.write_text("\n".join(doc), encoding="utf-8", newline="\n")
    print(f"検算を書き出した: {out_path} (判定 {verdict})")

    return 0 if not failures else 1


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("generate", "verify"))
    parser.add_argument("--scan-json", type=Path, default=None)
    parser.add_argument(
        "--out", type=Path, default=Path(__file__).resolve().parent / "migration-verification.md"
    )
    args = parser.parse_args(argv)

    if args.command == "generate":
        return run_generate(args.scan_json)

    return run_verify(args.scan_json, args.out)


if __name__ == "__main__":
    sys.exit(main())
