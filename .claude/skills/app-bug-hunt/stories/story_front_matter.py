#!/usr/bin/env python3
"""シナリオカードの前付け (制限文法) の読み取り器。

文法の**正本は `README.md`** であり、ここは**従う読み手**である。
読み取り器を書き換えて文法を広げてはならない (広げるなら README と自己テストを同じ変更で直す)。

**この読み取り器が見るもの** (制限文法 = README §1):

- 前付けの区切り (1 行目が厳密に `---` / 次に現れる「行頭から `---` だけ」の行で閉じる)
- 1 行 1 項目の `key: value` (半角コロン + 半角空白 1 つ)
- key の書式・重複・**この文法に無い key**
- 値の 3 形 (素のスカラー / 真偽値 / 配列) と、key ごとにどの形を取るか

**この読み取り器が見ないもの** (見るのは呼び出し側である):

- 必須 key の全数と正準順序 / 閉じた語彙 / 表 A・表 B との突合 / 本文の確定形
  … `test_story_front_matter.py` が見る
- `covers_*` の値の実在 / 欄の意味 / 分母の被覆 … `scripts/bug-hunt-inventory.py` が見る

**例外を投げない** (読み取り不能そのものを除く)。違反は並びで返す。1 件目で止めると
直すたびに再実行が要るためである。

依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path

CANONICAL_KEYS = (
    "id", "title", "surface", "lane", "priority", "applicability",
    "not_applicable_reason",
    "depends_on", "reseed_before", "accounts", "setup",
    "covers_screens", "covers_operations", "covers_capabilities",
)
CONDITIONAL_KEY = "not_applicable_reason"
REQUIRED_KEYS = tuple(k for k in CANONICAL_KEYS if k != CONDITIONAL_KEY)

SCALAR_KEYS = frozenset({
    "id", "title", "surface", "lane", "priority", "applicability", CONDITIONAL_KEY,
})
BOOL_KEYS = frozenset({"reseed_before"})
ARRAY_KEYS = frozenset({
    "depends_on", "accounts", "setup",
    "covers_screens", "covers_operations", "covers_capabilities",
})

LANE_VOCABULARY = ("parallel_browser", "serial_parent")
PRIORITY_VOCABULARY = ("P1", "P2", "P3")
APPLICABILITY_VOCABULARY = ("applicable", "not_applicable")
ACCOUNT_VOCABULARY = ("guest", "owner", "admin", "member", "platform_admin")

# 照合はすべて fullmatch() で行う (Python の `$` は**末尾改行の直前にも一致する**ため、
# match() + `$` は「厳密一致」と同義ではない)。
CARD_ID_RE = re.compile(r"S[1-9][0-9]*")
KEY_RE = re.compile(r"[a-z][a-z0-9_]*")
FILENAME_RE = re.compile(r"S[1-9][0-9]*-.+\.md")
ROUTE_TOKEN_RE = re.compile(r"[a-z0-9]+([._-][a-z0-9]+)*")
CAPABILITY_TOKEN_RE = re.compile(r"[A-Z]+-[0-9]{2}")
SURFACE_TOKEN_RE = re.compile(r"[a-z][a-z0-9_]*")

FRONT_MATTER_DELIMITER = "---"
BOOLEAN_LITERALS = {"true": True, "false": False}
ARRAY_SEPARATOR = ", "
# スカラーと配列要素に**どこに現れても**許さない文字 (README §1 の A4)。
# 引用符・注釈・区切り・入れ子の記号である。
FORBIDDEN_VALUE_CHARS = "#:[]'\""
# 値の**先頭**にだけ許さない記号 (README §1 の A5)。YAML ならここで構造が始まる —
# `&` アンカー / `*` 参照 / `|` `>` 複数行スカラー / `{` フローマップ。
#
# ★ **位置で判定する** (文字そのものを禁じない)。`R&D` や `横幅 * 高さ` のような
#   自然な値まで拒むと、読み取り器が README の文法より狭くなる
#   (「README が正本、ここは従う読み手」に反する)。
FORBIDDEN_VALUE_LEADING_CHARS = "&*|>{"

# 除外は**閉じたリテラル集合**にする (パターン除外を作らない)。
EXCLUDED_FILENAMES = frozenset({"README.md"})


class StoryReadError(Exception):
    """カードを読むこと自体が成立しない状態 (置き場が無い / 候補が 0 件 / 読み取り不能)。"""


@dataclass(frozen=True)
class Card:
    """1 枚のカード。値は制限文法で読めた形のまま持つ。"""

    filename: str
    text: str
    front_matter: dict[str, object]
    keys_in_order: tuple[str, ...]
    body: str


def _scalar_violation(key: str, value: str) -> str | None:
    if value == "":
        return f"{key}: スカラーが空である"
    if value != value.strip():
        return f"{key}: スカラーの前後に空白がある"
    for char in FORBIDDEN_VALUE_CHARS:
        if char in value:
            return f"{key}: スカラーに使えない文字がある: {char!r}"
    if value[0] in FORBIDDEN_VALUE_LEADING_CHARS:
        return f"{key}: 値の先頭に YAML の構造記号がある: {value[0]!r}"

    return None


def _parse_array(key: str, value: str) -> tuple[list[str], list[str]]:
    """配列を読む。`[]` か `[a, b, c]` だけを認める (ネスト不可・引用符禁止)。"""
    if not (value.startswith("[") and value.endswith("]")):
        return [], [f"{key}: 配列が角括弧で囲まれていない: {value!r}"]
    inner = value[1:-1]
    if inner == "":
        return [], []

    elements = inner.split(ARRAY_SEPARATOR)
    violations: list[str] = []
    for element in elements:
        violation = _scalar_violation(key, element)
        if violation is not None:
            violations.append(f"{violation} (要素 {element!r})")
        elif "," in element:
            violations.append(f"{key}: 配列の区切りが '{ARRAY_SEPARATOR}' でない: {element!r}")

    return elements, violations


def parse_front_matter(
    text: str,
) -> tuple[dict[str, object], tuple[str, ...], list[str], str]:
    """前付けを読み、(値, 出現順の key, 違反, 本文) を返す。**例外を投げない**。"""
    violations: list[str] = []
    lines = text.split("\n")

    if not lines or lines[0] != FRONT_MATTER_DELIMITER:
        violations.append(f"1 行目が {FRONT_MATTER_DELIMITER!r} でない")

        return {}, (), violations, text

    close = None
    for index in range(1, len(lines)):
        if lines[index] == FRONT_MATTER_DELIMITER:
            close = index
            break
    if close is None:
        violations.append(f"前付けが {FRONT_MATTER_DELIMITER!r} で閉じていない")

        return {}, (), violations, text

    values: dict[str, object] = {}
    order: list[str] = []
    for line in lines[1:close]:
        if line == "":
            violations.append("前付けに空行がある")
            continue
        key, separator, rest = line.partition(":")
        if separator == "":
            violations.append(f"key: value の形でない: {line!r}")
            continue
        if not rest.startswith(" "):
            violations.append(f"半角コロンの後に半角空白 1 つが要る: {line!r}")
            continue
        value = rest[1:]
        if KEY_RE.fullmatch(key) is None:
            violations.append(f"key の書式が契約外: {key!r}")
            continue
        if key in values:
            violations.append(f"key が重複している: {key}")
            continue
        if key not in CANONICAL_KEYS:
            violations.append(f"この文法に無い key: {key}")
            continue

        if key in BOOL_KEYS:
            if value not in BOOLEAN_LITERALS:
                violations.append(f"{key}: 真偽値が true / false でない: {value!r}")
                continue
            values[key] = BOOLEAN_LITERALS[value]
        elif key in ARRAY_KEYS:
            elements, element_violations = _parse_array(key, value)
            violations += element_violations
            if element_violations:
                continue
            values[key] = elements
        elif key in SCALAR_KEYS:
            violation = _scalar_violation(key, value)
            if violation is not None:
                violations.append(violation)
                continue
            values[key] = value
        else:
            # 正準 key に足したのに型集合 (SCALAR/BOOL/ARRAY) への登録を忘れた形。
            # 黙ってスカラー扱いにせず、内部契約の違反として落とす (fail-closed)。
            violations.append(f"{key}: どの型集合にも登録されていない key である")
            continue
        order.append(key)

    return values, tuple(order), violations, "\n".join(lines[close + 1:])


def parse_card(filename: str, text: str) -> tuple[Card, list[str]]:
    """1 枚分の本文からカードを作る。違反があってもカードは返す (呼び出し側が判断する)。"""
    values, order, violations, body = parse_front_matter(text)

    return (
        Card(filename=filename, text=text, front_matter=values, keys_in_order=order, body=body),
        [f"{filename}: {v}" for v in violations],
    )


def stories_dir() -> Path:
    return Path(__file__).resolve().parent


def read_cards(directory: Path | None = None) -> tuple[list[Card], list[str]]:
    """候補母集団 (`*.md` から `EXCLUDED_FILENAMES` を引いた全件) を読む。

    **パターンで発見しない**。`S8.md` のような命名違反を「存在しないもの」にしないため、
    全件走査してから命名契約を検査する (命名の判定は呼び出し側の責務)。

    読むこと自体が成立しない場合 (置き場が無い / 候補が 0 件 / 読み取り不能) は
    `StoryReadError` を投げる。**違反 0 件と母集団 0 件を混ぜない**ためである。
    """
    target = stories_dir() if directory is None else directory
    if not target.is_dir():
        raise StoryReadError(f"カードの置き場が無い: {target}")

    candidates = [p for p in sorted(target.glob("*.md")) if p.name not in EXCLUDED_FILENAMES]
    if not candidates:
        raise StoryReadError(f"カードの候補が 1 件も無い: {target}")

    cards: list[Card] = []
    violations: list[str] = []
    for path in candidates:
        try:
            text = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError) as exc:
            raise StoryReadError(f"カードを読めない: {path} ({exc})") from exc
        card, card_violations = parse_card(path.name, text)
        cards.append(card)
        violations += card_violations

    return cards, violations
