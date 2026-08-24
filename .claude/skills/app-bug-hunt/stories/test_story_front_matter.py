#!/usr/bin/env python3
"""シナリオカードの書式契約の自己テスト (標準ライブラリのみ)。

    cd .claude/skills/app-bug-hunt/stories && python3 -m unittest test_story_front_matter

`composer test` からは `tests/Architecture/BughuntStoryToolSelfTest.php` が起動する。

**走査対象**: `stories/*.md` から `story_front_matter.EXCLUDED_FILENAMES` を引いた全件と、
書式の正本 `README.md` のマーカー区間 2 つ (表 A = 許可する対象面の語彙 /
表 B = カード目録)。判定に使う純関数 (`card_violations` / `graph_violations` /
`marker_table` / `partition_violations`) は**合成入力にも実データにも同じものを使う**ので、
負例は実ファイル母集団が 0 件になっても走る。

**保証しないもの**:

- `covers_screens` / `covers_operations` / `covers_capabilities` の値の**実在**は見ない
  (形だけを見る)。実在・欄の意味・分母の被覆は `scripts/bug-hunt-inventory.py` の責務で、
  同じ規則を 2 か所に持たない (B16)。
- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
  (固定マップは派生キャッシュ。E5)。
- 兆候番号 (`H{n}`) の意味がカードに書かれていないことは見ない (G6)。
- 手順の書式 (ステップ表・step 識別子) は**採っていない**ので検査しない
  (`docs/template-divergence.md` D41)。
"""
from __future__ import annotations

import re
import unittest

import story_front_matter as sfm

STORIES_DIR = sfm.stories_dir()
README_PATH = STORIES_DIR / "README.md"

SURFACE_MARKER = "STORY-SURFACE-VOCABULARY"
INVENTORY_MARKER = "STORY-CARD-INVENTORY"
SURFACE_TABLE_HEADER = ("surface", "面", "由来")
INVENTORY_TABLE_HEADER = ("id", "surface")

# 家系必須の対象面。削除・改名は fail (追記は自由)。
FAMILY_REQUIRED_SURFACES = (
    "signup_funnel", "invitation", "core_journey", "org_project_admin", "billing",
    "account_security", "authz_boundary", "result_view", "admin_console",
    "cli_or_api", "public_share",
)

# 家系固定: 既存番号の面を付け替えない。
FAMILY_SURFACE_PIN = (
    ("S1", "signup_funnel"),
    ("S2", "invitation"),
    ("S3", "core_journey"),
    ("S4", "org_project_admin"),
    ("S5", "billing"),
    ("S6", "account_security"),
    ("S7", "authz_boundary"),
)
PINNED_IDS = frozenset(card_id for card_id, _ in FAMILY_SURFACE_PIN)

# 旧メタ節。前付けと散文の二重正本を残さない (H1)。
LEGACY_META_PATTERNS = (
    "- 前提状態:",
    "- 目的:",
    "## このストーリーで消化する",
)

PURPOSE_HEADING = "## 目的"
DEVIATION_HEADING = "## 逸脱アイデア (--deviate 時)"
STEPS_HEADING = "## 手順"


# --------------------------------------------------------------------------- #
# 判定の純関数 (合成入力にも実データにも同じものを使う)
# --------------------------------------------------------------------------- #
def marker_table(
    text: str, marker: str, header: tuple[str, ...]
) -> tuple[list[tuple[str, ...]], list[str]]:
    """マーカー区間から表を抜き、構造契約を検査して (データ行, 違反) を返す。

    契約 (**空行の位置も契約である**):

        <!-- {marker}:BEGIN -->
        (空行 1 行)
        | 正準ヘッダ |
        | 正準区切り行 |     ← 各セルはちょうど `---`
        | データ行 |         ← 1 行以上。**読み飛ばしを一切しない**
        (空行 1 行)
        <!-- {marker}:END -->

    BEGIN / END はそれぞれちょうど 1 個で、**BEGIN が END より前**にあること。
    表の中に空行を挟まないこと。
    """
    violations: list[str] = []
    begin, end = f"<!-- {marker}:BEGIN -->", f"<!-- {marker}:END -->"
    if text.count(begin) != 1 or text.count(end) != 1:
        violations.append(f"{marker}: マーカー区間がちょうど 1 対でない")
        return [], violations
    if text.index(begin) > text.index(end):
        violations.append(f"{marker}: END が BEGIN より前にある")
        return [], violations

    # BEGIN 行の残り / 空行 / 表 / 空行 / END 行の手前、で 4 つの空要素に挟まれる。
    raw = text.split(begin, 1)[1].split(end, 1)[0].split("\n")
    if len(raw) < 5 or raw[0] != "" or raw[1] != "" or raw[-1] != "" or raw[-2] != "":
        violations.append(f"{marker}: マーカー区間の空行の配置が契約外")
        return [], violations

    lines = raw[2:-2]
    if any(line.strip() == "" for line in lines):
        violations.append(f"{marker}: 表の中に空行がある")
        return [], violations

    expected_header = "| " + " | ".join(header) + " |"
    if lines[0] != expected_header:
        violations.append(f"{marker}: 正準ヘッダでない: {lines[0]!r} (期待 {expected_header!r})")
        return [], violations
    if len(lines) < 2:
        violations.append(f"{marker}: 区切り行が無い")
        return [], violations
    expected_separator = "|" + "|".join(["---"] * len(header)) + "|"
    if lines[1] != expected_separator:
        violations.append(
            f"{marker}: 正準区切り行でない: {lines[1]!r} (期待 {expected_separator!r})"
        )
        return [], violations

    rows: list[tuple[str, ...]] = []
    for line in lines[2:]:
        if not line.startswith("|") or not line.endswith("|"):
            violations.append(f"{marker}: 区間に表以外の行がある: {line!r}")
            continue
        cols = tuple(c.strip() for c in line.strip("|").split("|"))
        if len(cols) != len(header):
            violations.append(f"{marker}: データ行の列数が {len(header)} でない: {line!r}")
            continue
        rows.append(cols)

    if not rows:
        violations.append(f"{marker}: データ行が 1 行も無い")

    return rows, violations


def unwrap_code(value: str) -> tuple[str, bool]:
    """1 対のバッククォートを外す。装飾がそれ以外なら第 2 要素が False。"""
    if len(value) >= 2 and value.startswith("`") and value.endswith("`") and "`" not in value[1:-1]:
        return value[1:-1], True

    return value, False


def surface_vocabulary(text: str) -> tuple[list[str], list[str]]:
    """表 A を読み、許可する対象面の語彙と違反を返す (C1 / C2 / C3)。"""
    rows, violations = marker_table(text, SURFACE_MARKER, SURFACE_TABLE_HEADER)
    surfaces: list[str] = []
    for cols in rows:
        token, decorated = unwrap_code(cols[0])
        if not decorated:
            violations.append(f"表 A: surface の装飾が 1 対のバッククォートでない: {cols[0]!r}")
            continue
        if sfm.SURFACE_TOKEN_RE.fullmatch(token) is None:
            violations.append(f"表 A: surface が snake_case 1 語でない: {token!r}")
            continue
        if token in surfaces:
            violations.append(f"表 A: surface が重複している: {token}")
            continue
        surfaces.append(token)

    for required in FAMILY_REQUIRED_SURFACES:
        if required not in surfaces:
            violations.append(f"表 A: 家系必須の対象面が無い: {required}")

    return surfaces, violations


def card_inventory(text: str) -> tuple[list[tuple[str, str]], list[str]]:
    """表 B を読み、(id, surface) の並びと違反を返す (C4 / C5)。"""
    rows, violations = marker_table(text, INVENTORY_MARKER, INVENTORY_TABLE_HEADER)
    entries: list[tuple[str, str]] = []
    seen: set[str] = set()
    for cols in rows:
        card_id = cols[0]
        token, decorated = unwrap_code(cols[1])
        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
            violations.append(f"表 B: id の書式が契約外: {card_id!r}")
            continue
        if not decorated:
            violations.append(f"表 B: surface の装飾が 1 対のバッククォートでない: {cols[1]!r}")
            continue
        if card_id in seen:
            violations.append(f"表 B: id が重複している: {card_id}")
            continue
        seen.add(card_id)
        entries.append((card_id, token))

    return entries, violations


def section_body(text: str, heading: str) -> str | None:
    """H2 見出しの直後から次の H2 見出しの直前までを返す。無ければ None。"""
    lines = text.splitlines()
    start = None
    for index, line in enumerate(lines):
        if line == heading:
            start = index + 1
            break
    if start is None:
        return None
    end = len(lines)
    for index in range(start, len(lines)):
        if lines[index].startswith("## "):
            end = index
            break

    return "\n".join(lines[start:end])


def card_violations(card: sfm.Card, surfaces: tuple[str, ...] | list[str]) -> list[str]:
    """カード 1 枚の契約を検査する (B / F2 / H1 / J 群)。

    ★ 前付けの**文法**違反は `story_front_matter.parse_card()` が既に返しているので、
      ここでは重ねて見ない。ここが見るのは「読めた前付けの中身」と本文である。
    """
    violations: list[str] = []
    prefix = f"{card.filename}:"
    values = card.front_matter

    # --- B1: 必須 key の全数と正準順序 (条件付き key は applicability で決まる) ---
    applicability = values.get("applicability")
    expected = list(sfm.REQUIRED_KEYS)
    if applicability == "not_applicable":
        expected.insert(sfm.CANONICAL_KEYS.index(sfm.CONDITIONAL_KEY), sfm.CONDITIONAL_KEY)
    if list(card.keys_in_order) != expected:
        violations.append(f"{prefix} key の全数か正準順序が契約外: {list(card.keys_in_order)}")
        return violations

    def scalar(key: str) -> str:
        value = values.get(key)

        return value if isinstance(value, str) else ""

    def array(key: str) -> list[str]:
        value = values.get(key)

        return [str(v) for v in value] if isinstance(value, list) else []

    # --- B2 / B4〜B7 / B10 / B11: 語彙と書式 ---
    if sfm.CARD_ID_RE.fullmatch(scalar("id")) is None:
        violations.append(f"{prefix} id の書式が契約外: {scalar('id')!r}")
    if scalar("title") == "":
        violations.append(f"{prefix} title が空である")
    if scalar("surface") not in surfaces:
        violations.append(f"{prefix} surface が表 A に無い: {scalar('surface')!r}")
    if scalar("lane") not in sfm.LANE_VOCABULARY:
        violations.append(f"{prefix} 未知の lane: {scalar('lane')!r}")
    if scalar("priority") not in sfm.PRIORITY_VOCABULARY:
        violations.append(f"{prefix} 未知の priority: {scalar('priority')!r}")
    if scalar("applicability") not in sfm.APPLICABILITY_VOCABULARY:
        violations.append(f"{prefix} 未知の applicability: {scalar('applicability')!r}")
    if not isinstance(values.get("reseed_before"), bool):
        violations.append(f"{prefix} reseed_before が真偽値でない")
    for account in array("accounts"):
        if account not in sfm.ACCOUNT_VOCABULARY:
            violations.append(f"{prefix} 未知の accounts トークン: {account!r}")

    # --- B8: 条件付き key の値 ---
    if applicability == "not_applicable" and scalar(sfm.CONDITIONAL_KEY) == "":
        violations.append(f"{prefix} not_applicable_reason が空である")

    # --- B9 / B12〜B15 + AC-13: 配列の形と重複 ---
    for key, pattern in (
        ("depends_on", sfm.CARD_ID_RE),
        ("covers_screens", sfm.ROUTE_TOKEN_RE),
        ("covers_operations", sfm.ROUTE_TOKEN_RE),
        ("covers_capabilities", sfm.CAPABILITY_TOKEN_RE),
    ):
        for element in array(key):
            if pattern.fullmatch(element) is None:
                violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
    for key in sfm.ARRAY_KEYS:
        elements = array(key)
        duplicates = sorted({e for e in elements if elements.count(e) > 1})
        if duplicates:
            violations.append(f"{prefix} {key} に重複した要素がある: {', '.join(duplicates)}")
    for element in array("setup"):
        if element.strip() == "":
            violations.append(f"{prefix} setup に空の要素がある")

    # --- J1: H1 見出しと前付けの機械一致 ---
    expected_heading = f"# {scalar('id')}: {scalar('title')}"
    headings = [line for line in card.body.splitlines() if line.startswith("# ")]
    if headings[:1] != [expected_heading]:
        violations.append(f"{prefix} H1 見出しが前付けと一致しない (期待 {expected_heading!r})")

    # --- F2: not_applicable のカードは手順を持たない ---
    has_steps = any(line == STEPS_HEADING for line in card.body.splitlines())
    if applicability == "not_applicable" and has_steps:
        violations.append(f"{prefix} not_applicable のカードに {STEPS_HEADING} 節がある")

    # --- H1: 旧メタ節が残っていない ---
    for line in card.body.splitlines():
        for pattern in LEGACY_META_PATTERNS:
            if line.startswith(pattern):
                violations.append(f"{prefix} 旧メタ節が残っている: {line!r}")

    # --- J2 / J3: 本文の確定形 (ちょうど 1 個 + 中身が空でない) ---
    for heading in (PURPOSE_HEADING, DEVIATION_HEADING):
        count = sum(1 for line in card.body.splitlines() if line == heading)
        if count != 1:
            violations.append(f"{prefix} {heading} 節がちょうど 1 個でない ({count} 個)")
            continue
        body = section_body(card.body, heading)
        if body is None or body.strip() == "":
            violations.append(f"{prefix} {heading} 節の中身が空である")

    return violations


def graph_violations(cards: list[sfm.Card]) -> list[str]:
    """カード横断の契約を検査する (D3 / D4 / D5 / E1 / E2 / E3)。"""
    violations: list[str] = []
    ids: list[str] = []
    by_id: dict[str, sfm.Card] = {}

    for card in cards:
        # --- D5: ファイル名の先頭セグメントだけを機械一致させる ---
        if sfm.FILENAME_RE.fullmatch(card.filename) is None:
            violations.append(f"{card.filename}: ファイル名が S{{n}}-{{kebab}}.md でない")
            continue
        card_id = str(card.front_matter.get("id", ""))
        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
            violations.append(f"{card.filename}: id の書式が契約外で番号規約を判定できない")
            continue
        if card.filename.split("-", 1)[0] != card_id:
            violations.append(f"{card.filename}: ファイル名の先頭セグメントが id ({card_id}) と違う")
            continue
        # --- D3: id は一意 ---
        if card_id in by_id:
            violations.append(f"{card.filename}: id が重複している: {card_id}")
            continue
        ids.append(card_id)
        by_id[card_id] = card

    # --- D4: 欠番を作らない (S1 から最大番号まで連番) ---
    if ids:
        numbers = sorted(int(i[1:]) for i in ids)
        if numbers != list(range(1, numbers[-1] + 1)):
            violations.append(f"カード番号に欠番がある: {numbers}")

    # --- E1: depends_on の実在・自己参照・循環 ---
    for card_id, card in by_id.items():
        for dependency in card.front_matter.get("depends_on", []) or []:
            if dependency == card_id:
                violations.append(f"{card.filename}: depends_on が自己参照している")
            elif dependency not in by_id:
                violations.append(f"{card.filename}: depends_on に実在しないカード: {dependency}")

    def reaches_self(start: str) -> bool:
        """start から depends_on を辿って start 自身へ戻れるか (自己参照を含む)。"""
        stack, seen = [start], set()
        while stack:
            node = stack.pop()
            for dependency in by_id[node].front_matter.get("depends_on") or []:
                key = str(dependency)
                if key == start:
                    return True
                if key in by_id and key not in seen:
                    seen.add(key)
                    stack.append(key)

        return False

    for card_id, card in by_id.items():
        if reaches_self(card_id):
            violations.append(f"{card.filename}: depends_on が循環している")

    # --- E2 / E3 ---
    for card_id, card in by_id.items():
        dependencies = [str(d) for d in (card.front_matter.get("depends_on") or [])]
        if dependencies and card.front_matter.get("reseed_before") is not False:
            violations.append(f"{card.filename}: depends_on を持つなら reseed_before は false")
        if card.front_matter.get("lane") == "parallel_browser":
            for dependency in dependencies:
                if dependency in by_id and by_id[dependency].front_matter.get("lane") == "serial_parent":
                    violations.append(
                        f"{card.filename}: parallel_browser のカードが serial_parent に依存している"
                    )

    return violations


# --------------------------------------------------------------------------- #
# AC-14: 全数点呼
# --------------------------------------------------------------------------- #
# 詳細設計の全数対応表の全 58 項目。**ここが点呼の基準**である。
ALL_INVARIANTS = (
    "A1", "A2", "A3", "A4", "A5", "A6",
    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
    "C1", "C2", "C3", "C4", "C5",
    "D1", "D2", "D3", "D4", "D5", "D6", "D7",
    "E1", "E2", "E3", "E4", "E5",
    "F1", "F2",
    "G1", "G2", "G3", "G4", "G5", "G6",
    "H1",
    "I1", "I2", "I3", "I4", "I5", "I6", "I7",
    "J1", "J2", "J3",
)
EXPECTED_TOTAL = 58

# --- 分類 (互いに排他。和が ALL_INVARIANTS と一致する) ---
ADOPTED = (
    "A1", "A2", "A3", "A4", "A5", "A6",
    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
    "C1", "C2", "C3", "C4", "C5",
    "D1", "D2", "D3", "D4", "D5", "D7",
    "E1", "E2", "E3", "E4", "E5",
    "F2",
    "G6",
    "H1",
    "I1", "I2", "I3", "I4", "I6",
    "J1", "J2", "J3",
)
DIFFERENCES = ("I5", "I7")                                  # aicue 固有差 (既存 D20 が説明)
NOT_ADOPTED = ("D6", "F1", "G1", "G2", "G3", "G4", "G5")    # 新規 D41 が説明

# --- 担い手 (集合同士の重複を許す。B16 のように両側に現れる項目がある) ---
STORY_SIDE = (
    "A1", "A2", "A3", "A4", "A5", "A6",
    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
    "C1", "C2", "C3", "C4", "C5",
    "D1", "D2", "D3", "D4", "D5", "D7",
    "E1", "E2", "E3", "E4",
    "F2", "H1", "J1", "J2", "J3",
)
INVENTORY_SIDE = ("B16", "I1", "I2", "I3", "I4", "I6")
NON_MECHANICAL = ("E5", "G6")

SUBJECT_TO_TESTS = {
    "AC-01": (
        "test_ac_01_accepts_canonical_front_matter",
        "test_ac_01_accepts_horizontal_rule_in_body",
        "test_ac_01_accepts_structural_chars_inside_values",
        "test_ac_01_rejects_quoted_scalar",
        "test_ac_01_rejects_duplicate_key",
        "test_ac_01_rejects_key_out_of_canonical_order",
        "test_ac_01_rejects_missing_required_key",
        "test_ac_01_rejects_unknown_key",
        "test_ac_01_rejects_blank_and_comment_line",
        "test_ac_01_rejects_missing_delimiter",
        "test_ac_01_rejects_malformed_key_value_separator",
        "test_ac_01_rejects_malformed_key_syntax",
        "test_ac_01_rejects_malformed_array_syntax",
        "test_ac_01_rejects_yaml_structures",
        "test_ac_01_rejects_key_outside_type_sets",
    ),
    "AC-02": (
        "test_ac_02_accepts_real_cards_vocabulary",
        "test_ac_02_rejects_unknown_lane",
        "test_ac_02_rejects_unknown_priority",
        "test_ac_02_rejects_unknown_account",
        "test_ac_02_rejects_zero_padded_id",
        "test_ac_02_rejects_non_boolean_reseed",
    ),
    "AC-03": (
        "test_ac_03_accepts_real_card_naming",
        "test_ac_03_rejects_gap_in_card_numbers",
        "test_ac_03_rejects_duplicate_id",
        "test_ac_03_rejects_filename_without_id_segment",
    ),
    "AC-04": (
        "test_ac_04_accepts_surface_vocabulary_table",
        "test_ac_04_rejects_removed_family_surface",
        "test_ac_04_rejects_wrong_table_header",
        "test_ac_04_rejects_duplicate_surface_row",
        "test_ac_04_rejects_prose_line_inside_marker",
        "test_ac_04_rejects_reversed_markers",
        "test_ac_04_rejects_blank_line_layout_change",
        "test_ac_04_rejects_non_canonical_separator_row",
    ),
    "AC-05": (
        "test_ac_05_accepts_inventory_matching_cards",
        "test_ac_05_rejects_card_missing_from_inventory",
        "test_ac_05_rejects_inventory_row_without_card",
        "test_ac_05_rejects_surface_outside_vocabulary",
        "test_ac_05_rejects_inventory_table_with_extra_column",
    ),
    "AC-06": (
        "test_ac_06_accepts_family_surface_pin",
        "test_ac_06_rejects_reassigned_family_surface",
    ),
    "AC-07": (
        "test_ac_07_accepts_real_dependencies",
        "test_ac_07_rejects_dependency_cycle",
        "test_ac_07_rejects_self_dependency",
        "test_ac_07_rejects_unknown_dependency",
    ),
    "AC-08": (
        "test_ac_08_accepts_dependency_without_reseed",
        "test_ac_08_rejects_reseed_with_dependency",
    ),
    "AC-09": (
        "test_ac_09_accepts_serial_depending_on_parallel",
        "test_ac_09_rejects_parallel_depending_on_serial",
    ),
    "AC-10": (
        "test_ac_10_accepts_not_applicable_card",
        "test_ac_10_rejects_steps_in_not_applicable_card",
        "test_ac_10_rejects_reason_on_applicable_card",
        "test_ac_10_rejects_missing_reason_on_not_applicable_card",
    ),
    "AC-11": (
        "test_ac_11_accepts_matching_heading",
        "test_ac_11_rejects_heading_mismatch",
        "test_ac_11_rejects_missing_heading",
    ),
    "AC-12": (
        "test_ac_12_accepts_real_cards_without_legacy_meta",
        "test_ac_12_rejects_legacy_meta_section",
        "test_ac_12_rejects_legacy_purpose_bullet",
    ),
    "AC-13": (
        "test_ac_13_accepts_covers_shape",
        "test_ac_13_rejects_duplicate_array_element",
        "test_ac_13_rejects_malformed_route_token",
        "test_ac_13_rejects_malformed_capability_token",
    ),
    "AC-14": (
        "test_ac_14_accepts_complete_partition",
        "test_ac_14_accepts_explicit_subject_to_test_mapping",
        "test_ac_14_rejects_missing_invariant",
        "test_ac_14_rejects_duplicate_classification",
        "test_ac_14_rejects_adopted_without_bearer",
        "test_ac_14_rejects_unknown_bearer_id",
        "test_ac_14_rejects_wrong_total",
    ),
    "AC-15": (
        "test_ac_15_accepts_canonical_body",
        "test_ac_15_rejects_missing_purpose_section",
        "test_ac_15_rejects_duplicate_purpose_section",
        "test_ac_15_rejects_empty_purpose_section",
        "test_ac_15_rejects_missing_deviation_section",
        "test_ac_15_rejects_duplicate_deviation_section",
        "test_ac_15_rejects_empty_deviation_section",
    ),
}

INVARIANT_TO_SUBJECT = {
    "A1": "AC-01", "A2": "AC-01", "A3": "AC-01", "A4": "AC-01", "A5": "AC-01", "A6": "AC-01",
    "B1": "AC-01",
    "B2": "AC-02", "B5": "AC-02", "B6": "AC-02", "B7": "AC-02", "B10": "AC-02",
    "B11": "AC-02", "B12": "AC-02",
    "B3": "AC-11",
    "B4": "AC-05",
    "B8": "AC-10",
    "B9": "AC-07",
    "B13": "AC-13", "B14": "AC-13", "B15": "AC-13", "B16": "AC-13",
    "C1": "AC-04", "C2": "AC-04", "C3": "AC-04",
    "C4": "AC-05", "C5": "AC-05",
    "D1": "AC-06", "D2": "AC-06",
    "D3": "AC-03", "D4": "AC-03", "D5": "AC-03",
    "D7": "AC-05",
    "E1": "AC-07", "E2": "AC-08", "E3": "AC-09", "E4": "AC-05",
    "F2": "AC-10",
    "H1": "AC-12",
    "J1": "AC-11", "J2": "AC-15", "J3": "AC-15",
}


def partition_violations(
    all_invariants: tuple[str, ...],
    adopted: tuple[str, ...],
    differences: tuple[str, ...],
    not_adopted: tuple[str, ...],
    bearers: tuple[str, ...],
    expected_total: int,
) -> list[str]:
    """分類と担い手の整合を見て違反の並びを返す (実データにも合成入力にも使う純関数)。"""
    violations: list[str] = []
    if len(all_invariants) != expected_total:
        violations.append(f"全数が {expected_total} 件でない: {len(all_invariants)}")
    if len(all_invariants) != len(set(all_invariants)):
        violations.append("全数の一覧に重複がある")

    classified = [*adopted, *differences, *not_adopted]
    if len(classified) != len(set(classified)):
        violations.append("分類が重複している")
    if set(classified) != set(all_invariants):
        missing = sorted(set(all_invariants) - set(classified))
        extra = sorted(set(classified) - set(all_invariants))
        violations.append(f"分類の和が全数と一致しない (不足 {missing} / 余分 {extra})")

    for key in adopted:
        if key not in bearers:
            violations.append(f"担い手の無い採用項目: {key}")
    for key in sorted(set(bearers) - set(all_invariants)):
        violations.append(f"担い手集合に未知の ID: {key}")

    return violations


# --------------------------------------------------------------------------- #
# 合成入力 (実ファイル母集団が 0 件になりうる違反分岐を必ず走らせる)
# --------------------------------------------------------------------------- #
BASE_VALUES: dict[str, object] = {
    "id": "S1",
    "title": "見本カード",
    "surface": "signup_funnel",
    "lane": "parallel_browser",
    "priority": "P1",
    "applicability": "applicable",
    "depends_on": [],
    "reseed_before": True,
    "accounts": ["guest"],
    "setup": [],
    "covers_screens": ["home"],
    "covers_operations": ["login.store"],
    "covers_capabilities": ["AUTH-01"],
}
BASE_BODY = (
    "# S1: 見本カード\n"
    "\n"
    "## 目的\n"
    "見本のカードである。\n"
    "\n"
    "## 手順\n"
    "1. 開く → 見える\n"
    "\n"
    "## 逸脱アイデア (--deviate 時)\n"
    "- 二重送信してみる\n"
)
BASE_SURFACES = list(FAMILY_REQUIRED_SURFACES)


def render_value(value: object) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, list):
        return "[" + ", ".join(str(v) for v in value) + "]"

    return str(value)


def render_front_matter(values: dict[str, object], order: list[str] | None = None) -> str:
    keys = order if order is not None else [k for k in sfm.CANONICAL_KEYS if k in values]

    return "---\n" + "".join(f"{k}: {render_value(values[k])}\n" for k in keys) + "---\n"


def build_card(
    *,
    values: dict[str, object] | None = None,
    order: list[str] | None = None,
    body: str | None = None,
    filename: str = "S1-sample.md",
    raw: str | None = None,
) -> tuple[sfm.Card, list[str]]:
    text = raw if raw is not None else render_front_matter(
        dict(BASE_VALUES) if values is None else values, order
    ) + "\n" + (BASE_BODY if body is None else body)

    return sfm.parse_card(filename, text)


def synthetic_violations(**kwargs: object) -> list[str]:
    """合成カード 1 枚の文法違反と中身の違反を合わせて返す。"""
    card, parse = build_card(**kwargs)  # type: ignore[arg-type]

    return parse + card_violations(card, BASE_SURFACES)


def parse_violations(raw: str) -> list[str]:
    """**読み取り器の違反だけ**を返す (中身の違反を混ぜない)。

    ★ 負例で `synthetic_violations()` の非空だけを見ると、狙った分岐が壊れても
      **別の違反**で緑になる (例: 読み取り器が `title: |` を受理するよう後退しても、
      H1 見出しの不一致で落ちるので気付けない)。負例は必ず狙った分岐を名指しする。
    """
    return sfm.parse_front_matter(raw)[2]


# --------------------------------------------------------------------------- #
# 実データ (母集団)
# --------------------------------------------------------------------------- #
class StoryFrontMatterContractTest(unittest.TestCase):
    """カードの書式契約。実データと合成入力の両方を同じ純関数で判定する。"""

    @classmethod
    def setUpClass(cls) -> None:
        cls.readme = README_PATH.read_text(encoding="utf-8")
        cls.cards, cls.parse_violations = sfm.read_cards(STORIES_DIR)
        cls.surfaces, cls.surface_violations = surface_vocabulary(cls.readme)
        cls.inventory, cls.inventory_violations = card_inventory(cls.readme)

    # ----------------------------------------------------------------- #
    # 負例の共通ヘルパ (狙った分岐を名指しする)
    # ----------------------------------------------------------------- #
    def assert_parse_rejects(self, raw: str, needle: str) -> None:
        """読み取り器が**その理由で**落とすこと (別の違反での緑を許さない)。"""
        violations = parse_violations(raw)
        self.assertTrue(
            any(needle in v for v in violations),
            f"{needle!r} を含む違反が無い: {violations}",
        )

    def assert_card_rejects(self, needle: str, **kwargs: object) -> None:
        """カードの中身の検査が**その理由で**落とすこと。"""
        violations = synthetic_violations(**kwargs)
        self.assertTrue(
            any(needle in v for v in violations),
            f"{needle!r} を含む違反が無い: {violations}",
        )

    # ----------------------------------------------------------------- #
    # 母集団の非空 (走査が空振りしていないこと)
    # ----------------------------------------------------------------- #
    def test_population_is_not_empty(self) -> None:
        """カード母集団と表 A / 表 B のデータ行がいずれも空でないこと。"""
        self.assertNotEqual([], self.cards, "カード母集団が 0 件 (走査根が壊れている)")
        self.assertNotEqual([], self.surfaces)
        self.assertNotEqual([], self.inventory)

    def test_real_cards_parse_without_violations(self) -> None:
        """実カードの前付けが制限文法で読めること。"""
        self.assertEqual([], self.parse_violations)

    def test_real_cards_have_no_content_violations(self) -> None:
        """実カードの中身が契約に反していないこと。"""
        violations: list[str] = []
        for card in self.cards:
            violations += card_violations(card, self.surfaces)
        self.assertEqual([], violations)

    def test_real_cards_have_no_graph_violations(self) -> None:
        """番号規約と依存の契約に反していないこと。"""
        self.assertEqual([], graph_violations(self.cards))

    # ----------------------------------------------------------------- #
    # AC-01: 制限文法 + 必須 key 全数 + 正準順序 + 重複なし
    # ----------------------------------------------------------------- #
    def test_ac_01_accepts_canonical_front_matter(self) -> None:
        self.assertEqual([], synthetic_violations())

    def test_ac_01_accepts_horizontal_rule_in_body(self) -> None:
        """本文中の水平線で前付けが閉じたことにならないこと (A1)。"""
        body = BASE_BODY.replace("## 手順\n", "## 手順\n---\n")
        card, parse = build_card(body=body)
        self.assertEqual([], parse)
        self.assertEqual("S1", card.front_matter["id"])

    def test_ac_01_rejects_quoted_scalar(self) -> None:
        raw = render_front_matter(dict(BASE_VALUES, title='"見本カード"')) + "\n" + BASE_BODY
        self.assert_parse_rejects(raw, "スカラーに使えない文字がある")

    def test_ac_01_rejects_duplicate_key(self) -> None:
        raw = render_front_matter(dict(BASE_VALUES)).replace(
            "id: S1\n", "id: S1\nid: S2\n"
        ) + "\n" + BASE_BODY
        self.assert_parse_rejects(raw, "key が重複している")

    def test_ac_01_rejects_key_out_of_canonical_order(self) -> None:
        order = [k for k in sfm.CANONICAL_KEYS if k in BASE_VALUES]
        order[0], order[1] = order[1], order[0]
        self.assert_card_rejects("key の全数か正準順序が契約外", order=order)

    def test_ac_01_rejects_missing_required_key(self) -> None:
        values = {k: v for k, v in BASE_VALUES.items() if k != "priority"}
        self.assert_card_rejects("key の全数か正準順序が契約外", values=values)

    def test_ac_01_rejects_unknown_key(self) -> None:
        raw = render_front_matter(dict(BASE_VALUES)).replace(
            "---\nid: S1\n", "---\nid: S1\nowner: kento\n"
        ) + "\n" + BASE_BODY
        self.assert_parse_rejects(raw, "この文法に無い key: owner")

    def test_ac_01_rejects_blank_and_comment_line(self) -> None:
        for injected, needle in (
            ("\n", "前付けに空行がある"),
            ("# コメント\n", "key: value の形でない"),
        ):
            with self.subTest(injected=injected):
                raw = render_front_matter(dict(BASE_VALUES)).replace(
                    "id: S1\n", "id: S1\n" + injected
                ) + "\n" + BASE_BODY
                self.assert_parse_rejects(raw, needle)

    def test_ac_01_rejects_missing_delimiter(self) -> None:
        for raw, needle in (
            # 1 行目が `---` でない
            (render_front_matter(dict(BASE_VALUES))[4:] + "\n" + BASE_BODY, "1 行目が"),
            # 閉じる `---` が無い
            (render_front_matter(dict(BASE_VALUES))[:-4] + "\n" + BASE_BODY, "で閉じていない"),
        ):
            with self.subTest(raw=raw[:20]):
                self.assert_parse_rejects(raw, needle)

    def test_ac_01_rejects_malformed_key_value_separator(self) -> None:
        """`key: value` (半角コロン + 半角空白 1 つ) 以外を認めないこと (A2)。"""
        for broken, needle in (
            ("id:S1", "半角コロンの後に半角空白 1 つが要る"),
            ("id:  S1", "スカラーの前後に空白がある"),
            ("id : S1", "key の書式が契約外"),
            ("id S1", "key: value の形でない"),
        ):
            with self.subTest(broken=broken):
                raw = render_front_matter(dict(BASE_VALUES)).replace(
                    "id: S1", broken, 1
                ) + "\n" + BASE_BODY
                self.assert_parse_rejects(raw, needle)

    def test_ac_01_rejects_malformed_key_syntax(self) -> None:
        """key が `^[a-z][a-z0-9_]*$` でないこと (A3)。"""
        for broken in ("Id: S1", "1id: S1", "id-x: S1", "-: S1"):
            with self.subTest(broken=broken):
                raw = render_front_matter(dict(BASE_VALUES)).replace(
                    "id: S1", broken, 1
                ) + "\n" + BASE_BODY
                self.assert_parse_rejects(raw, "key の書式が契約外")

    def test_ac_01_rejects_malformed_array_syntax(self) -> None:
        """配列は `[]` か `[a, b]` だけで、区切りの揺れとネストを認めないこと (A4)。"""
        for broken, needle in (
            ("accounts: [guest,owner]", "配列の区切りが"),      # 区切りに空白が無い
            ("accounts: [guest ,owner]", "配列の区切りが"),     # 要素の後ろに空白
            ("accounts: [ guest]", "スカラーの前後に空白がある"),  # 要素の前に空白
            ("accounts: [[guest]]", "スカラーに使えない文字がある"),  # ネスト
            ("accounts: guest", "配列が角括弧で囲まれていない"),   # 角括弧が無い
            ("accounts: [guest", "配列が角括弧で囲まれていない"),  # 閉じていない
        ):
            with self.subTest(broken=broken):
                raw = render_front_matter(dict(BASE_VALUES)).replace(
                    "accounts: [guest]", broken, 1
                ) + "\n" + BASE_BODY
                self.assert_parse_rejects(raw, needle)

    def test_ac_01_rejects_yaml_structures(self) -> None:
        """複数行スカラー・アンカー・参照・フローマップ・ネストマップを認めないこと (A5)。

        ★ これらを「素のスカラーとして黙って受ける」と、A5 を守っているとは言えなくなる
          (値としては読めてしまうため)。読み取り器が構造記号を値から締め出すことで閉じる。
        """
        for broken, needle in (
            ("title: |", "値の先頭に YAML の構造記号がある"),          # 複数行スカラー (リテラル)
            ("title: >", "値の先頭に YAML の構造記号がある"),          # 複数行スカラー (畳み込み)
            ("title: &anchor 見本カード", "値の先頭に YAML の構造記号がある"),  # アンカー
            ("title: *anchor", "値の先頭に YAML の構造記号がある"),     # 参照
            ("title: {a: b}", "スカラーに使えない文字がある"),          # フローマップ (`:` で落ちる)
            ("setup: [&anchor 準備する]", "値の先頭に YAML の構造記号がある"),  # 配列要素の先頭
        ):
            with self.subTest(broken=broken):
                target = "setup: []" if broken.startswith("setup") else "title: 見本カード"
                raw = render_front_matter(dict(BASE_VALUES)).replace(
                    target, broken, 1
                ) + "\n" + BASE_BODY
                self.assert_parse_rejects(raw, needle)

        # ネストマップ (字下げした続き行) は key の書式で落ちる。
        raw = render_front_matter(dict(BASE_VALUES)).replace(
            "title: 見本カード", "title: 見本カード\n  nested: value", 1
        ) + "\n" + BASE_BODY
        self.assert_parse_rejects(raw, "key の書式が契約外")

    def test_ac_01_accepts_structural_chars_inside_values(self) -> None:
        """構造記号は**先頭以外**なら使えること (読み取り器が README より狭くならない)。"""
        for value in ("R&D の手順", "横幅 * 高さを確認する", "入力 > 出力"):
            with self.subTest(value=value):
                self.assertEqual([], parse_violations(
                    render_front_matter(dict(BASE_VALUES, title=value))
                    + "\n" + BASE_BODY.replace("# S1: 見本カード", f"# S1: {value}")
                ))

    def test_ac_01_rejects_key_outside_type_sets(self) -> None:
        """正準 key なのに型集合へ登録し忘れた形を黙ってスカラーにしないこと (fail-closed)。"""
        self.assert_parse_rejects("---\nghost: x\n---\n", "この文法に無い key: ghost")
        original = sfm.CANONICAL_KEYS
        sfm.CANONICAL_KEYS = (*original, "ghost")
        try:
            self.assert_parse_rejects(
                "---\nghost: x\n---\n", "どの型集合にも登録されていない key である"
            )
        finally:
            sfm.CANONICAL_KEYS = original

    # ----------------------------------------------------------------- #
    # AC-02: 閉じた語彙と値の書式
    # ----------------------------------------------------------------- #
    def test_ac_02_accepts_real_cards_vocabulary(self) -> None:
        for card in self.cards:
            with self.subTest(card=card.filename):
                self.assertIn(card.front_matter["lane"], sfm.LANE_VOCABULARY)
                self.assertIn(card.front_matter["priority"], sfm.PRIORITY_VOCABULARY)
                self.assertIn(card.front_matter["applicability"], sfm.APPLICABILITY_VOCABULARY)

    def test_ac_02_rejects_unknown_lane(self) -> None:
        self.assert_card_rejects("未知の lane", values=dict(BASE_VALUES, lane="serial"))

    def test_ac_02_rejects_unknown_priority(self) -> None:
        self.assert_card_rejects("未知の priority", values=dict(BASE_VALUES, priority="P0"))

    def test_ac_02_rejects_unknown_account(self) -> None:
        self.assert_card_rejects(
            "未知の accounts トークン", values=dict(BASE_VALUES, accounts=["photographer"])
        )

    def test_ac_02_rejects_zero_padded_id(self) -> None:
        self.assert_card_rejects(
            "id の書式が契約外",
            values=dict(BASE_VALUES, id="S01"), body=BASE_BODY.replace("# S1: ", "# S01: "),
        )

    def test_ac_02_rejects_non_boolean_reseed(self) -> None:
        raw = render_front_matter(dict(BASE_VALUES)).replace(
            "reseed_before: true", "reseed_before: yes"
        ) + "\n" + BASE_BODY
        self.assert_parse_rejects(raw, "真偽値が true / false でない")

    # ----------------------------------------------------------------- #
    # AC-03: 命名・id の一意性・欠番
    # ----------------------------------------------------------------- #
    def test_ac_03_accepts_real_card_naming(self) -> None:
        self.assertEqual([], graph_violations(self.cards))

    def test_ac_03_rejects_gap_in_card_numbers(self) -> None:
        first, _ = build_card(filename="S1-a.md")
        third, _ = build_card(
            values=dict(BASE_VALUES, id="S3"),
            body=BASE_BODY.replace("# S1: ", "# S3: "),
            filename="S3-c.md",
        )
        self.assertNotEqual([], graph_violations([first, third]))

    def test_ac_03_rejects_duplicate_id(self) -> None:
        first, _ = build_card(filename="S1-a.md")
        clone, _ = build_card(filename="S1-b.md")
        self.assertNotEqual([], graph_violations([first, clone]))

    def test_ac_03_rejects_filename_without_id_segment(self) -> None:
        card, _ = build_card(filename="story-one.md")
        self.assertNotEqual([], graph_violations([card]))

    # ----------------------------------------------------------------- #
    # AC-04: 表 A の構造契約と家系必須 11 語
    # ----------------------------------------------------------------- #
    def test_ac_04_accepts_surface_vocabulary_table(self) -> None:
        self.assertEqual([], self.surface_violations)
        for required in FAMILY_REQUIRED_SURFACES:
            self.assertIn(required, self.surfaces)

    def test_ac_04_rejects_removed_family_surface(self) -> None:
        broken = self.readme.replace("| `public_share` |", "| `shared_link` |")
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    def test_ac_04_rejects_wrong_table_header(self) -> None:
        broken = self.readme.replace("| surface | 面 | 由来 |", "| surface | 面 |")
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    def test_ac_04_rejects_duplicate_surface_row(self) -> None:
        broken = self.readme.replace(
            "| `billing` | 課金 | テンプレート同梱 |",
            "| `billing` | 課金 | テンプレート同梱 |\n| `billing` | 課金 (写し) | テンプレート同梱 |",
        )
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    def test_ac_04_rejects_reversed_markers(self) -> None:
        """END が BEGIN より前にある区間を通さないこと。"""
        broken = self.readme.replace(
            f"<!-- {SURFACE_MARKER}:BEGIN -->", "@@BEGIN@@", 1
        ).replace(
            f"<!-- {SURFACE_MARKER}:END -->", f"<!-- {SURFACE_MARKER}:BEGIN -->", 1
        ).replace("@@BEGIN@@", f"<!-- {SURFACE_MARKER}:END -->", 1)
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    def test_ac_04_rejects_blank_line_layout_change(self) -> None:
        """空行の配置も契約であること (区間直後の空行を削る / 表の中に空行を挟む)。"""
        for broken in (
            self.readme.replace(
                f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface",
                f"<!-- {SURFACE_MARKER}:BEGIN -->\n| surface",
                1,
            ),
            self.readme.replace(
                "| `billing` | 課金 | テンプレート同梱 |",
                "| `billing` | 課金 | テンプレート同梱 |\n",
                1,
            ),
        ):
            with self.subTest(broken=broken[:0]):
                _, violations = surface_vocabulary(broken)
                self.assertNotEqual([], violations)

    def test_ac_04_rejects_non_canonical_separator_row(self) -> None:
        """区切り行は各セルがちょうど `---` であること。"""
        broken = self.readme.replace(
            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|---|---|---|",
            f"<!-- {SURFACE_MARKER}:BEGIN -->\n\n| surface | 面 | 由来 |\n|-|-|-|",
            1,
        )
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    def test_ac_04_rejects_prose_line_inside_marker(self) -> None:
        """区間の中の非表行を読み飛ばさないこと (読み飛ばしを一切しない)。"""
        broken = self.readme.replace(
            "| `billing` | 課金 | テンプレート同梱 |",
            "| `billing` | 課金 | テンプレート同梱 |\nこの語彙はあとで整理する。",
        )
        _, violations = surface_vocabulary(broken)
        self.assertNotEqual([], violations)

    # ----------------------------------------------------------------- #
    # AC-05: surface の所属と表 B とカードの 1 対 1
    # ----------------------------------------------------------------- #
    def inventory_mismatch(self, inventory: list[tuple[str, str]], cards: list[sfm.Card]) -> list[str]:
        """表 B と実在カードの 1 対 1 を判定する (C5 / D7)。"""
        violations: list[str] = []
        declared = dict(inventory)
        actual = {
            str(c.front_matter.get("id")): str(c.front_matter.get("surface")) for c in cards
        }
        for card_id in sorted(set(actual) - set(declared)):
            violations.append(f"表 B に載っていないカード: {card_id}")
        for card_id in sorted(set(declared) - set(actual)):
            violations.append(f"表 B の行に対応するカードが無い: {card_id}")
        for card_id in sorted(set(declared) & set(actual)):
            if declared[card_id] != actual[card_id]:
                violations.append(f"表 B とカードの surface が違う: {card_id}")

        return violations

    def test_ac_05_accepts_inventory_matching_cards(self) -> None:
        self.assertEqual([], self.inventory_violations)
        self.assertEqual([], self.inventory_mismatch(self.inventory, self.cards))
        for card in self.cards:
            self.assertIn(card.front_matter["surface"], self.surfaces)

    def test_ac_05_rejects_card_missing_from_inventory(self) -> None:
        extra, _ = build_card(
            values=dict(BASE_VALUES, id="S8", surface="result_view"),
            body=BASE_BODY.replace("# S1: ", "# S8: "),
            filename="S8-result.md",
        )
        self.assertNotEqual([], self.inventory_mismatch(self.inventory, [*self.cards, extra]))

    def test_ac_05_rejects_inventory_row_without_card(self) -> None:
        broken = self.readme.replace(
            "| S7 | `authz_boundary` |",
            "| S7 | `authz_boundary` |\n| S8 | `result_view` |",
        )
        inventory, violations = card_inventory(broken)
        self.assertEqual([], violations)
        self.assertNotEqual([], self.inventory_mismatch(inventory, self.cards))

    def test_ac_05_rejects_surface_outside_vocabulary(self) -> None:
        self.assert_card_rejects(
            "surface が表 A に無い", values=dict(BASE_VALUES, surface="not_registered")
        )

    def test_ac_05_rejects_inventory_table_with_extra_column(self) -> None:
        """表 B に lane / priority / depends_on の写しを置けないこと (C4 / E4)。"""
        broken = self.readme.replace("| id | surface |\n|---|---|", "| id | surface | lane |\n|---|---|---|")
        _, violations = card_inventory(broken)
        self.assertNotEqual([], violations)

    # ----------------------------------------------------------------- #
    # AC-06: 家系固定 (id, surface)
    # ----------------------------------------------------------------- #
    def family_pin_actual(self, cards: list[sfm.Card]) -> tuple[tuple[str, str], ...]:
        return tuple(sorted(
            (str(card.front_matter["id"]), str(card.front_matter["surface"]))
            for card in cards
            if str(card.front_matter.get("id")) in PINNED_IDS
        ))

    def test_ac_06_accepts_family_surface_pin(self) -> None:
        """S1 から S7 の (id, surface) を家系で固定する。

        番号は識別子であって意味を持たないが、**既存番号の面を付け替えない**ことが
        家系固定の本体である (D1 / D2)。検査側のリテラルと完全一致で突き合わせる。

        ★ pin の対象は PINNED_IDS に属するカードだけである。S8 以降を正規の手続き
          (表 A に面を足し、表 B に 1 行、カードを 1 枚) で足しても落ちない。
        """
        self.assertEqual(tuple(sorted(FAMILY_SURFACE_PIN)), self.family_pin_actual(self.cards))

    def test_ac_06_rejects_reassigned_family_surface(self) -> None:
        # ★ **実カード 7 枚のうち S1 の面だけを差し替える**。カードを減らした集合で比べると
        #   「6 枚足りない」で落ちてしまい、面の付け替えを検出したことにならない
        #   (共通規約 (c): 正しい理由で落ちること)。
        others = [c for c in self.cards if str(c.front_matter.get("id")) != "S1"]
        self.assertEqual(6, len(others))
        pin = tuple(sorted(FAMILY_SURFACE_PIN))

        reassigned, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="billing"))
        self.assertNotEqual(pin, self.family_pin_actual([*others, reassigned]))

        # 正の対照: 面を正しい値へ戻すと一致する (落ちた理由が面の付け替えであることの裏取り)。
        restored, _ = build_card(values=dict(BASE_VALUES, id="S1", surface="signup_funnel"))
        self.assertEqual(pin, self.family_pin_actual([*others, restored]))

    # ----------------------------------------------------------------- #
    # AC-07 / AC-08 / AC-09: 依存と実行方式
    # ----------------------------------------------------------------- #
    def two_cards(self, first: dict[str, object], second: dict[str, object]) -> list[sfm.Card]:
        a, _ = build_card(
            values=first, body=BASE_BODY.replace("# S1: ", f"# {first['id']}: "),
            filename=f"{first['id']}-a.md",
        )
        b, _ = build_card(
            values=second, body=BASE_BODY.replace("# S1: ", f"# {second['id']}: "),
            filename=f"{second['id']}-b.md",
        )

        return [a, b]

    def test_ac_07_accepts_real_dependencies(self) -> None:
        self.assertEqual([], graph_violations(self.cards))

    def test_ac_07_rejects_dependency_cycle(self) -> None:
        cards = self.two_cards(
            dict(BASE_VALUES, id="S1", depends_on=["S2"], reseed_before=False),
            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
        )
        self.assertNotEqual([], graph_violations(cards))

    def test_ac_07_rejects_self_dependency(self) -> None:
        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S1"], reseed_before=False))
        self.assertNotEqual([], graph_violations([card]))

    def test_ac_07_rejects_unknown_dependency(self) -> None:
        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S9"], reseed_before=False))
        self.assertNotEqual([], graph_violations([card]))

    def test_ac_08_accepts_dependency_without_reseed(self) -> None:
        cards = self.two_cards(
            dict(BASE_VALUES, id="S1"),
            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
        )
        self.assertEqual([], graph_violations(cards))

    def test_ac_08_rejects_reseed_with_dependency(self) -> None:
        cards = self.two_cards(
            dict(BASE_VALUES, id="S1"),
            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=True),
        )
        self.assertNotEqual([], graph_violations(cards))

    def test_ac_09_accepts_serial_depending_on_parallel(self) -> None:
        cards = self.two_cards(
            dict(BASE_VALUES, id="S1", lane="parallel_browser"),
            dict(BASE_VALUES, id="S2", lane="serial_parent", depends_on=["S1"], reseed_before=False),
        )
        self.assertEqual([], graph_violations(cards))

    def test_ac_09_rejects_parallel_depending_on_serial(self) -> None:
        cards = self.two_cards(
            dict(BASE_VALUES, id="S1", lane="serial_parent"),
            dict(BASE_VALUES, id="S2", lane="parallel_browser", depends_on=["S1"], reseed_before=False),
        )
        self.assertNotEqual([], graph_violations(cards))

    # ----------------------------------------------------------------- #
    # AC-10: not_applicable カードの中身
    # ----------------------------------------------------------------- #
    NOT_APPLICABLE_VALUES = {
        "id": "S1",
        "title": "見本カード",
        "surface": "signup_funnel",
        "lane": "parallel_browser",
        "priority": "P3",
        "applicability": "not_applicable",
        "not_applicable_reason": "本アプリに該当する面が無いため実走しない",
        "depends_on": [],
        "reseed_before": False,
        "accounts": [],
        "setup": [],
        "covers_screens": [],
        "covers_operations": [],
        "covers_capabilities": [],
    }
    NOT_APPLICABLE_BODY = (
        "# S1: 見本カード\n"
        "\n"
        "## 目的\n"
        "該当面が無いことを記録として残す。\n"
        "\n"
        "## 逸脱アイデア (--deviate 時)\n"
        "- 該当面が生えていないか確認する\n"
    )

    def test_ac_10_accepts_not_applicable_card(self) -> None:
        self.assertEqual([], synthetic_violations(
            values=dict(self.NOT_APPLICABLE_VALUES), body=self.NOT_APPLICABLE_BODY,
        ))

    def test_ac_10_rejects_steps_in_not_applicable_card(self) -> None:
        body = self.NOT_APPLICABLE_BODY.replace(
            "## 逸脱アイデア", "## 手順\n1. 開く\n\n## 逸脱アイデア"
        )
        self.assert_card_rejects(
            "not_applicable のカードに ## 手順 節がある",
            values=dict(self.NOT_APPLICABLE_VALUES), body=body,
        )

    def test_ac_10_rejects_reason_on_applicable_card(self) -> None:
        values = dict(self.NOT_APPLICABLE_VALUES, applicability="applicable")
        self.assertNotEqual([], synthetic_violations(
            values=values, body=self.NOT_APPLICABLE_BODY,
        ))

    def test_ac_10_rejects_missing_reason_on_not_applicable_card(self) -> None:
        values = {
            k: v for k, v in self.NOT_APPLICABLE_VALUES.items() if k != sfm.CONDITIONAL_KEY
        }
        self.assertNotEqual([], synthetic_violations(
            values=values, body=self.NOT_APPLICABLE_BODY,
        ))

    # ----------------------------------------------------------------- #
    # AC-11: H1 見出しと前付けの機械一致
    # ----------------------------------------------------------------- #
    def test_ac_11_accepts_matching_heading(self) -> None:
        self.assertEqual([], synthetic_violations())

    def test_ac_11_rejects_heading_mismatch(self) -> None:
        self.assert_card_rejects(
            "H1 見出しが前付けと一致しない",
            body=BASE_BODY.replace("# S1: 見本カード", "# S1: 別のタイトル"),
        )

    def test_ac_11_rejects_missing_heading(self) -> None:
        self.assert_card_rejects(
            "H1 見出しが前付けと一致しない", body=BASE_BODY.replace("# S1: 見本カード\n\n", "")
        )

    # ----------------------------------------------------------------- #
    # AC-12: 旧メタ節が残っていない
    # ----------------------------------------------------------------- #
    def test_ac_12_accepts_real_cards_without_legacy_meta(self) -> None:
        for card in self.cards:
            with self.subTest(card=card.filename):
                for pattern in LEGACY_META_PATTERNS:
                    for line in card.body.splitlines():
                        self.assertFalse(line.startswith(pattern), line)

    def test_ac_12_rejects_legacy_meta_section(self) -> None:
        self.assert_card_rejects(
            "旧メタ節が残っている",
            body=BASE_BODY + "\n## このストーリーで消化する screens / operations\n- screens: home\n",
        )

    def test_ac_12_rejects_legacy_purpose_bullet(self) -> None:
        for legacy in ("- 前提状態: ゲスト\n", "- 目的: 何かする\n"):
            with self.subTest(legacy=legacy):
                self.assert_card_rejects(
                    "旧メタ節が残っている", body=BASE_BODY.replace("## 目的\n", "## 目的\n" + legacy)
                )

    # ----------------------------------------------------------------- #
    # AC-13: covers_* は形だけを見る (実在は目録側)
    # ----------------------------------------------------------------- #
    def test_ac_13_accepts_covers_shape(self) -> None:
        """実在しない route 名でも**形が正しければ**ここでは通ること (B16)。"""
        values = dict(BASE_VALUES, covers_screens=["not.a.real.route"])
        self.assertEqual([], synthetic_violations(values=values))

    def test_ac_13_rejects_duplicate_array_element(self) -> None:
        self.assert_card_rejects(
            "covers_operations に重複した要素がある",
            values=dict(BASE_VALUES, covers_operations=["login.store", "login.store"]),
        )

    def test_ac_13_rejects_malformed_route_token(self) -> None:
        self.assert_card_rejects(
            "covers_screens の要素の書式が契約外",
            values=dict(BASE_VALUES, covers_screens=["Home Page"]),
        )

    def test_ac_13_rejects_malformed_capability_token(self) -> None:
        self.assert_card_rejects(
            "covers_capabilities の要素の書式が契約外",
            values=dict(BASE_VALUES, covers_capabilities=["auth-1"]),
        )

    # ----------------------------------------------------------------- #
    # AC-14: 全数点呼
    # ----------------------------------------------------------------- #
    def test_ac_14_accepts_complete_partition(self) -> None:
        """実データの 58 項目が 3 分類へ過不足なく割れ、採用項目に担い手が居ること。"""
        self.assertEqual([], partition_violations(
            ALL_INVARIANTS, ADOPTED, DIFFERENCES, NOT_ADOPTED,
            (*STORY_SIDE, *INVENTORY_SIDE, *NON_MECHANICAL), EXPECTED_TOTAL,
        ))
        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
        self.assertEqual(("E5", "G6"), NON_MECHANICAL)

    def test_ac_14_accepts_explicit_subject_to_test_mapping(self) -> None:
        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。

        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
        """
        for key in STORY_SIDE:
            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
            self.assertIn(INVARIANT_TO_SUBJECT[key], SUBJECT_TO_TESTS)

        for subject, names in SUBJECT_TO_TESTS.items():
            for name in names:
                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")

    def test_ac_14_rejects_missing_invariant(self) -> None:
        self.assertNotEqual([], partition_violations(
            ("A1", "A2"), ("A1",), (), (), ("A1",), 2,
        ))

    def test_ac_14_rejects_duplicate_classification(self) -> None:
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), ("A1",), (), ("A1",), 1,
        ))

    def test_ac_14_rejects_adopted_without_bearer(self) -> None:
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), (), 1,
        ))

    def test_ac_14_rejects_unknown_bearer_id(self) -> None:
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), ("A1", "Z9"), 1,
        ))

    def test_ac_14_rejects_wrong_total(self) -> None:
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), ("A1",), 58,
        ))

    # ----------------------------------------------------------------- #
    # AC-15: カード本文の確定形
    # ----------------------------------------------------------------- #
    def test_ac_15_accepts_canonical_body(self) -> None:
        self.assertEqual([], synthetic_violations())

    def test_ac_15_rejects_missing_purpose_section(self) -> None:
        for body in (
            BASE_BODY.replace("## 目的\n見本のカードである。\n\n", ""),
            BASE_BODY.replace("## 目的", "## 目的:"),
        ):
            with self.subTest(body=body[:40]):
                self.assertNotEqual([], synthetic_violations(body=body))

    def test_ac_15_rejects_duplicate_purpose_section(self) -> None:
        body = BASE_BODY + "\n## 目的\n2 つ目の目的。\n"
        self.assertNotEqual([], synthetic_violations(body=body))

    def test_ac_15_rejects_empty_purpose_section(self) -> None:
        body = BASE_BODY.replace("## 目的\n見本のカードである。\n", "## 目的\n\n")
        self.assertNotEqual([], synthetic_violations(body=body))

    def test_ac_15_rejects_duplicate_deviation_section(self) -> None:
        body = BASE_BODY + "\n## 逸脱アイデア (--deviate 時)\n- もう 1 つ\n"
        self.assertNotEqual([], synthetic_violations(body=body))

    def test_ac_15_rejects_empty_deviation_section(self) -> None:
        body = BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n",
                                 "## 逸脱アイデア (--deviate 時)\n\n")
        self.assertNotEqual([], synthetic_violations(body=body))

    def test_ac_15_rejects_missing_deviation_section(self) -> None:
        for body in (
            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n", ""),
            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)", "## 逸脱アイデア"),
        ):
            with self.subTest(body=body[-40:]):
                self.assertNotEqual([], synthetic_violations(body=body))


class ReadCardsTest(unittest.TestCase):
    """候補母集団の作り方 (パターンで発見しない)。"""

    def test_readme_is_excluded_and_others_are_not(self) -> None:
        """除外は閉じたリテラル集合 1 件だけで、他の `*.md` は全件が候補になること。

        ★ **件数を pin しない**。S8 以降を正規の手続き (表 A に面を足し、表 B に 1 行、
          カードを 1 枚) で足せることが D7 の契約であり、ここで 7 枚に固定すると
          AC-06 が S8 を阻害しないよう作ってある意味が消える。
          母集団の非空は `test_population_is_not_empty`、表 B との 1 対 1 は AC-05 が持つ。
        """
        self.assertEqual(frozenset({"README.md"}), sfm.EXCLUDED_FILENAMES)
        names = {card.filename for card in sfm.read_cards(STORIES_DIR)[0]}
        self.assertNotIn("README.md", names)
        self.assertNotEqual(set(), names)
        self.assertEqual(
            {p.name for p in STORIES_DIR.glob("*.md")} - sfm.EXCLUDED_FILENAMES, names
        )

    def test_missing_directory_is_a_read_error(self) -> None:
        with self.assertRaises(sfm.StoryReadError):
            sfm.read_cards(STORIES_DIR / "no-such-dir")


if __name__ == "__main__":
    unittest.main()
