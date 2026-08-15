#!/usr/bin/env python3
"""annotations.toml の下書きを現行の手書き目録から起こす (T176 の一度きりの移行)。

現行 `.claude/skills/app-bug-hunt/{screens,operations}.md` の表から story を写し、
散文が「画面に付随する JSON GET」と呼ぶ 5 本を kind=JSON、他を kind=画面 として書き出す。
現行の正規表現に沈んでいて表に無かった route は kubun="外" + **理由を空**で出力する
(空では生成器の段 2 が落ちるので、人が理由を書くまで緑にならない)。

併せて「旧表の route 集合」と「新しく見える route 集合」の差分を標準出力へ出す。
この出力を devnotes に記録してから次へ進むこと。

    python3 devnotes/20260815-2100-bughunt-inventory-generator/bootstrap-annotations.py

**恒久スクリプトではない** (scripts/ へ昇格させない)。
"""
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent.parent
SKILL_DIR = REPO_ROOT / ".claude/skills/app-bug-hunt"
OUT_PATH = SKILL_DIR / "inventory/annotations.toml"

# 現行 screens.md の散文が「画面に付随する JSON GET」と呼んでいる 5 本。
JSON_GET_ROUTES = {
    "capture.csrf-cookie",
    "session.status",
    "passkey.registration-options",
    "passkey.login-options",
    "passkey.confirm-options",
}


def scan() -> dict:
    out = subprocess.run(
        ["php", "artisan", "bughunt:inventory-scan"],
        cwd=REPO_ROOT, capture_output=True, text=True, check=True,
    ).stdout

    return json.loads(out)


def table_rows(path: Path, name_index: int) -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped.startswith("|") or "---" in stripped:
            continue
        cols = [c.strip() for c in stripped.strip("|").split("|")]
        if len(cols) <= name_index or cols[name_index] == "name":
            continue
        rows[cols[name_index]] = cols

    return rows


def main() -> int:
    data = scan()
    routes = data["routes"]
    surface = [
        r for r in routes
        if "web" in r["middleware"]
        and r["uri"].split("/")[0] != "oauth"
        and not r["uri"].split("/")[0].startswith("livewire")
    ]
    screens = {r["name"] for r in surface if all(m in ("GET", "HEAD", "OPTIONS") for m in r["methods"])}

    old_screens = table_rows(SKILL_DIR / "screens.md", 1)     # | route | name | 割当ストーリー |
    old_operations = table_rows(SKILL_DIR / "operations.md", 2)  # | method | route | name | story | 区分 |
    old_names = set(old_screens) | set(old_operations)

    lines = ["schema_version = 1", ""]
    newly_visible: list[str] = []
    for route in sorted(surface, key=lambda r: r["name"]):
        name = route["name"]
        lines.append(f'[routes."{name}"]')
        if name in screens:
            lines.append(f'kind = "{"JSON" if name in JSON_GET_ROUTES else "画面"}"')
        if name in old_screens:
            lines.append(f'story = "{old_screens[name][2]}"')
            lines.append('kubun = "通常"')
        elif name in old_operations:
            lines.append(f'story = "{old_operations[name][3]}"')
            lines.append(f'kubun = "{old_operations[name][4]}"')
        else:
            newly_visible.append(name)
            lines.append('kubun = "外"')
            lines.append('reason = ""  # TODO: 30 文字以上の理由を人が書く')
        lines.append("")

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text("\n".join(lines), encoding="utf-8", newline="\n")

    print(f"書き出し: {OUT_PATH.relative_to(REPO_ROOT)} ({len(surface)} route)")
    print(f"旧表にしか無い route (消えた行): {sorted(old_names - {r['name'] for r in surface})}")
    print(f"新しく見える route ({len(newly_visible)} 件): {newly_visible}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
