#!/usr/bin/env python3
"""bug-hunt 目録 (画面一覧 / 操作一覧) の生成器兼検査器。

目録は**生成物**である。実装から取れる機械事実 (route 定義 / 画面題名) と、人が書く注釈
(`inventory/annotations.toml`) と散文 (`inventory/notes-*.md`) を合成して
`.claude/skills/app-bug-hunt/{screens,operations}.md` を作る。

    generate … 段 1 → 2 → 4 を通してから 2 ファイルを書き替える
    check    … 段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**

    段 1 (抽出)         抽出コマンドが成功し、宣言した抽出条件で走り、母集合が 0 件でない
    段 2 (注釈・割当)   注釈の集合 = 面の集合。語彙・必須・形式・複合 method を検査し、
                        シナリオカードの前付け (`stories/S*.md` の covers_*) と突き合わせる
    段 3 (生成物)       メモリ上で再生成した内容と現物を byte 比較する
    段 4 (機能カタログ) capability-catalog.md の代表機構が実在し、id が重複しない。
                        カードが挙げる capability が実在する

**割当 (どのカードが route を消化するか) の正本はシナリオカードの前付け**である
(規則の散文は `.claude/skills/app-bug-hunt/stories/README.md`)。注釈は route ごとの意味
(`kind` / `kubun` / `reason`) だけを持ち、`story` は未知の項目として落ちる。

終了コード: 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件・空名・重複名・
入力ファイル不在・壊れた TOML・**カードの置き場が無い / 候補 0 件 / 読み取り不能**・
想定外例外) / 3=ドリフト (段 2 / 3 / 4 の違反。**前付けの形式違反・語彙違反・
配列内重複・割当のドリフトはこちら**)。
**1 と 4 以上は使わない** (argparse が引数エラーで返す 2 は「致命」の側に落ちる)。

保証しないもの: 見るのは web group を宣言した面だけである。web group を宣言していない面
(機械向け API / Filament 管理画面 / MCP / 現在の webhook の大半) には**沈黙する**。
逆に web group を宣言した route は面の除外表の 2 つを除き必ず目録に入り、注釈を要求される
(実例: `webhooks.ses` は web 面なので操作表に載り、区分 `外` として理由付きで宣言されている)。
注釈の**内容**の妥当性・画面題名の欠落・機能カタログの網羅性も見ない。
**割当が痩せたこと**も検出できない (見るのは「1 枚以上のカードに載っていること」だけなので、
ある route が 2 枚から 1 枚へ減っても緑のままである)。カードの前付けの契約のうち
目録に関係しないもの (正準順序 / 表 A・表 B との突合 / lane / priority / depends_on /
H1 見出し / 旧メタ節) は `stories/test_story_front_matter.py` の責務で、ここでは見ない。

依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tomllib
import traceback
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

# --------------------------------------------------------------------------- #
# 語彙と定数 (規則の正本)
# --------------------------------------------------------------------------- #
STAGE1, STAGE2, STAGE3, STAGE4 = "抽出", "注釈", "生成物", "機能カタログ"
EXIT_OK, EXIT_FATAL, EXIT_DRIFT = 0, 2, 3

SCHEMA_VERSION = 1
# PHP 側 App\DataTransferObjects\Bughunt\InventoryScanData::EXTRACTION_CONDITION と一致させる。
EXTRACTION_CONDITION = "local-or-unit-tests"

# 面から除く先頭セグメント。**この 2 つだけ**にする (死んだ除外規則を並べない)。
SURFACE_EXCLUDED_SEGMENTS = ("oauth",)          # 先頭セグメント完全一致
SURFACE_EXCLUDED_PREFIXES = ("livewire",)       # 先頭セグメントの前方一致 (livewire-{hash})

KUBUN_VOCABULARY = ("通常", "逸", "終", "外")
# coverage/correlate.py の定数と一致させる (自己テストが import して照合する)。
KUBUN_OUT_OF_SCOPE, KUBUN_DEVIATE = "外", "逸"
# **reason の要否だけ**に使う。スコープ判定は `kubun == KUBUN_OUT_OF_SCOPE` に統一する
# (`終` は「実行すると後続が成立しない終端」であって**対象内**である)。
KUBUN_NEEDS_REASON = ("外", "終")

SCREEN_KINDS = ("画面", "JSON")
# 注釈が持つのは「route ごとの意味」だけである。割当 (どのカードが消化するか) は
# シナリオカードの前付けが正本なので、ここには持たない。
ANNOTATION_KEYS = ("kind", "kubun", "reason")
REASON_MIN_LENGTH = 30

# 割当セルの値域。**書き出し側の正本はここ**であり、規則の散文は
# `.claude/skills/app-bug-hunt/stories/README.md` にある。
# `-` は「載せるカードが 0 枚 (= 対象外)」を表す。
STORY_CELL_EMPTY = "-"
STORY_CELL_SEPARATOR = " "
# 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため)。
STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")

GET_LIKE_METHODS = ("GET", "HEAD", "OPTIONS")

# 表のセルへ出る値に許さない文字 (correlate.py が split("|") で読むためエスケープ規約は作らない)。
FORBIDDEN_CELL_CHARS = ("|", "\r", "\n")
# 箇条書きへ出る理由に許さない文字 (制御文字)。
CONTROL_CHAR_RE = re.compile(r"[\x00-\x1f\x7f]")

CAPABILITY_TABLE_HEADER = "| id | 機能 (actor→outcome) | 代表機構 (route name) |"
CAPABILITY_ID_RE = re.compile(r"^[A-Z]{2,5}-[0-9]{2}$")
BACKTICK_TOKEN_RE = re.compile(r"`([^`]+)`")
# ファイルパス (routes/api.php) は route 名候補にしない。**拡張子を持つものだけ**を
# パスと見なす (`/` を含むだけで捨てると `projects/store` のような打ち間違いが素通りする)。
PATH_TOKEN_RE = re.compile(r"^[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)+\.[A-Za-z0-9]+$")

SKILL_DIR = Path(".claude/skills/app-bug-hunt")
# 前付けの読み取り器の置き場 (stories/ に居る。文法の正本はその隣の README.md)。
STORIES_DIR = SKILL_DIR / "stories"
ANNOTATIONS_PATH = SKILL_DIR / "inventory" / "annotations.toml"
NOTES_SCREENS_PATH = SKILL_DIR / "inventory" / "notes-screens.md"
NOTES_OPERATIONS_PATH = SKILL_DIR / "inventory" / "notes-operations.md"
SCREENS_PATH = SKILL_DIR / "screens.md"
OPERATIONS_PATH = SKILL_DIR / "operations.md"
CATALOG_PATH = SKILL_DIR / "capability-catalog.md"

GENERATED_NOTICE = (
    "> **このファイルは生成物である。手で編集しない。**\n"
    "> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け\n"
    "> (`covers_screens` / `covers_operations`) を、区分・理由・種別は\n"
    "> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから\n"
    "> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。\n"
    "> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。\n"
    "> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。\n"
)

Scanner = Callable[[Path], object]

# 前付けの読み取り器を取り込む (ファイル名にハイフンを含む生成器からは通常の import ができない
# ため、読み取り器の置き場を sys.path へ一時的に足す)。読み取り器は stdlib だけに依存する。
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / STORIES_DIR))
try:
    import story_front_matter  # noqa: E402 — 置き場を sys.path へ足した直後にしか読めない
finally:
    sys.path.pop(0)


class FatalError(Exception):
    """検査を成立させられない状態 (終了コード 2)。"""


@dataclass(frozen=True)
class RouteFact:
    """抽出できた route 1 件の機械事実。"""

    name: str
    uri: str
    methods: tuple[str, ...]
    title: str | None

    @property
    def write_methods(self) -> tuple[str, ...]:
        """非 GET のメソッド (昇順)。"""
        return tuple(sorted(m for m in self.methods if m not in GET_LIKE_METHODS))


@dataclass(frozen=True)
class Facts:
    """段 1 が確定させた母集合。"""

    screens: tuple[RouteFact, ...]
    operations: tuple[RouteFact, ...]
    compound: tuple[str, ...]      # GET/HEAD と非 GET を併せ持つ route 名
    all_names: frozenset[str]      # 面に限らない全 route 名 (段 4 の照合母集合)

    @property
    def surface(self) -> tuple[RouteFact, ...]:
        return self.screens + self.operations


# --------------------------------------------------------------------------- #
# 段 1: 抽出
# --------------------------------------------------------------------------- #
def scan(repo_root: Path) -> object:
    """`php artisan bughunt:inventory-scan` を走らせて JSON を読む。"""
    try:
        proc = subprocess.run(
            ["php", "artisan", "bughunt:inventory-scan"],
            cwd=repo_root,
            capture_output=True,
            text=True,
        )
    except OSError as exc:
        raise FatalError(f"[{STAGE1}] 抽出コマンドを起動できない: {exc}") from exc

    if proc.returncode != 0:
        raise FatalError(
            f"[{STAGE1}] 抽出コマンドが非 0 終了 (code={proc.returncode}): "
            f"{proc.stderr.strip() or '(標準エラーなし)'}"
        )
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        raise FatalError(f"[{STAGE1}] 抽出結果が JSON として読めない: {exc}") from exc


def _is_excluded_surface(uri: str) -> bool:
    """面から除く先頭セグメントか。"""
    segment = uri.split("/", 1)[0]
    if segment in SURFACE_EXCLUDED_SEGMENTS:
        return True
    return any(segment.startswith(prefix) for prefix in SURFACE_EXCLUDED_PREFIXES)


def split_surface(data: object) -> Facts:
    """抽出結果から web 面を切り出し、画面表と操作表へ排他的に分ける。"""
    if not isinstance(data, dict):
        raise FatalError(f"[{STAGE1}] 抽出結果が表ではない")
    if data.get("schema_version") != SCHEMA_VERSION:
        raise FatalError(
            f"[{STAGE1}] schema_version が {SCHEMA_VERSION} ではない: {data.get('schema_version')!r}"
        )
    if data.get("extraction_condition") != EXTRACTION_CONDITION:
        raise FatalError(
            f"[{STAGE1}] 抽出条件が {EXTRACTION_CONDITION!r} ではない: "
            f"{data.get('extraction_condition')!r}"
        )
    routes = data.get("routes")
    if not isinstance(routes, list):
        raise FatalError(f"[{STAGE1}] routes が並びではない")

    all_names: list[str] = []
    screens: list[RouteFact] = []
    operations: list[RouteFact] = []
    compound: list[str] = []

    for raw in routes:
        if not isinstance(raw, dict):
            raise FatalError(f"[{STAGE1}] route の項目が表ではない: {raw!r}")
        name = raw.get("name")
        uri = raw.get("uri")
        methods = raw.get("methods")
        middleware = raw.get("middleware")
        title = raw.get("title")
        if not isinstance(uri, str) or not isinstance(methods, list) or not isinstance(middleware, list):
            raise FatalError(f"[{STAGE1}] route の項目の形が契約外: {raw!r}")
        if name is not None and not isinstance(name, str):
            raise FatalError(f"[{STAGE1}] route 名が文字列でも空でもない: {raw!r}")
        if title is not None and not isinstance(title, str):
            raise FatalError(f"[{STAGE1}] 画面題名が文字列でも空でもない: {raw!r}")

        if isinstance(name, str) and name != "":
            all_names.append(name)

        # 面の判定: middleware の**要素**に group 名 `web` があること (文字列化した部分一致にしない)。
        if "web" not in middleware or _is_excluded_surface(uri):
            continue
        if not isinstance(name, str) or name == "":
            raise FatalError(
                f"[{STAGE1}] web 面の route に名前が無い (目録の join キーを作れない): {uri}"
            )
        if not methods:
            raise FatalError(f"[{STAGE1}] web 面の route に HTTP メソッドが 1 つも無い: {name}")

        fact = RouteFact(name=name, uri=uri, methods=tuple(str(m) for m in methods), title=title)
        if fact.write_methods:
            operations.append(fact)
            # GET / HEAD と非 GET の併存は現在の注釈モデルで表せない (段 2 が drift にする)。
            # 黙って画面の分母から落とさないよう、操作表側へ入れたうえで印を付ける。
            if any(m in ("GET", "HEAD") for m in fact.methods):
                compound.append(name)
        else:
            screens.append(fact)

    duplicates = sorted({n for n in all_names if all_names.count(n) > 1})
    if duplicates:
        raise FatalError(f"[{STAGE1}] route 名が重複している: {', '.join(duplicates)}")
    if not screens and not operations:
        raise FatalError(f"[{STAGE1}] web 面の母集合が 0 件 (抽出が壊れた走行を緑にしない)")

    return Facts(
        screens=tuple(sorted(screens, key=lambda f: f.name)),
        operations=tuple(sorted(operations, key=lambda f: f.name)),
        compound=tuple(sorted(compound)),
        all_names=frozenset(all_names),
    )


# --------------------------------------------------------------------------- #
# 段 2: 注釈
# --------------------------------------------------------------------------- #
@dataclass(frozen=True)
class Annotations:
    """注釈ファイルの中身。未知のトップレベル項目も落とさずに持ち回る。"""

    routes: dict[str, dict[str, object]]
    unknown_top_level: tuple[str, ...]



def load_annotations(path: Path) -> Annotations:
    """注釈 TOML を読む (読み取り専用。生成器は注釈ファイルを書き換えない)。"""
    if not path.is_file():
        raise FatalError(f"[{STAGE2}] 注釈ファイルが無い: {path}")
    try:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as exc:
        raise FatalError(f"[{STAGE2}] 注釈ファイルが TOML として読めない: {exc}") from exc

    if data.get("schema_version") != SCHEMA_VERSION:
        raise FatalError(
            f"[{STAGE2}] 注釈の schema_version が {SCHEMA_VERSION} ではない: "
            f"{data.get('schema_version')!r}"
        )
    routes = data.get("routes", {})
    if not isinstance(routes, dict):
        raise FatalError(f"[{STAGE2}] 注釈の routes が表ではない")
    for name, entry in routes.items():
        if not isinstance(entry, dict):
            raise FatalError(f"[{STAGE2}] 注釈 {name} が表ではない")

    return Annotations(
        routes={str(name): dict(entry) for name, entry in routes.items()},
        # 書いたのに効かない項目を残さない (打ち間違いを黙って捨てない)。段 2 の drift。
        unknown_top_level=tuple(sorted(k for k in data if k not in ("schema_version", "routes"))),
    )


def _annotation_value(entry: dict[str, object], key: str) -> str | None:
    value = entry.get(key)
    return value if isinstance(value, str) else None


@dataclass(frozen=True)
class Assignment:
    """カードの前付けから逆引きした route → 割当カード集合 (欄ごと)。

    ★ 持つのは**判定と生成に使う 3 つだけ**である (集めるが誰も参照しない出力を作らない)。
      カードの一覧そのものは目録の生成にも突合にも要らないので持たない。
    """

    screens: dict[str, frozenset[str]]
    operations: dict[str, frozenset[str]]
    capabilities: frozenset[str]


def load_assignment(stories_dir: Path) -> tuple[Assignment | None, list[str]]:
    """カードの前付けを読み、欄ごとの割当と違反を返す。

    ★ **生成器単体で fail-closed にする**。書式の全契約は
      stories/test_story_front_matter.py の責務だが、それは**別プロセス**である。
      生成器を直接叩いた走行が緑になってはいけないので、ここでも次を見る:

        - parse_front_matter() が返した違反を**必ず伝播する**
        - `id` / `applicability` / `covers_*` が期待型でなければ**割当を構築しない**
        - 不正なカードを**飛ばして目録を生成しない** (段 2 の違反として exit 3 にする)

      逆に、語彙・正準順序・表 A / 表 B との突合といった「目録に関係しない契約」は
      ここでは見ない (二重に持つと必ず食い違う)。

    ★ **失敗を型で表す**。違反が 1 件でもあれば `None` を返す。空の Assignment を返すと、
      呼び出し側が違反の並びを見落としたときに**そのまま目録を生成できてしまう**。

    ★ 読むこと自体が成立しない状態 (置き場が無い / 候補 0 件 / 読み取り不能) は
      `FatalError` (終了コード 2) にする。**違反 0 件と母集団 0 件を混ぜない**。
    """
    try:
        cards, violations = story_front_matter.read_cards(stories_dir)
    except story_front_matter.StoryReadError as exc:
        raise FatalError(f"[{STAGE2}] シナリオカードを読めない: {exc}") from exc

    violations = [f"[{STAGE2}] {v}" for v in violations]
    screens: dict[str, set[str]] = {}
    operations: dict[str, set[str]] = {}
    capabilities: set[str] = set()
    card_ids: list[str] = []

    for card in cards:
        prefix = f"[{STAGE2}] {card.filename}:"
        card_id = card.front_matter.get("id")
        if not isinstance(card_id, str) or story_front_matter.CARD_ID_RE.fullmatch(card_id) is None:
            violations.append(f"{prefix} id の書式が契約外: {card_id!r}")
            continue
        if card_id in card_ids:
            violations.append(f"{prefix} id が重複している: {card_id}")
            continue
        card_ids.append(card_id)

        applicability = card.front_matter.get("applicability")
        if applicability not in story_front_matter.APPLICABILITY_VOCABULARY:
            violations.append(f"{prefix} 未知の applicability: {applicability!r}")
            continue

        for key, pattern in (
            ("covers_screens", story_front_matter.ROUTE_TOKEN_RE),
            ("covers_operations", story_front_matter.ROUTE_TOKEN_RE),
            ("covers_capabilities", story_front_matter.CAPABILITY_TOKEN_RE),
        ):
            elements = card.front_matter.get(key)
            if not isinstance(elements, list):
                violations.append(f"{prefix} {key} が配列でない")
                continue
            names: list[str] = []
            for element in elements:
                if not isinstance(element, str) or pattern.fullmatch(element) is None:
                    violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
                    continue
                if element in names:
                    # frozenset 化すると消えるので、集合にする**前**に見る。
                    violations.append(f"{prefix} {key} に重複した要素がある: {element}")
                    continue
                names.append(element)
            # not_applicable のカードは `## 手順` を持たない (F2) ため、消化カードとして
            # 数えるべきではない。よって割当の母集団から外す。
            if applicability != "applicable":
                continue
            if key == "covers_screens":
                for name in names:
                    screens.setdefault(name, set()).add(card_id)
            elif key == "covers_operations":
                for name in names:
                    operations.setdefault(name, set()).add(card_id)
            else:
                capabilities.update(names)

    if violations:
        return None, violations

    return Assignment(
        screens={k: frozenset(v) for k, v in screens.items()},
        operations={k: frozenset(v) for k, v in operations.items()},
        capabilities=frozenset(capabilities),
    ), []


def validate_assignment(
    facts: Facts, annotations: Annotations, assignment: Assignment
) -> list[str]:
    """前付けの割当と目録の母集合を突き合わせる (段 2 の一部)。

    見るのは 4 つ:
      I2 実在   … 載せた route 名が web 面の母集合に在る
      I3 欄     … covers_screens は safe method / covers_operations は 非 safe method
      I4 対象外 … 区分 **外** の route を載せていない (`終` は対象内である)
      I1 未割当 … 対象内の route が 1 枚以上のカードに載っている

    ★ **欄ごとに明示的にループする**。`fact in facts.screens` のような所属判定に頼ると、
      将来 GET と非 GET を併せ持つ route (compound) を両方の表へ入れる形にした瞬間に、
      操作側の未割当を静かに見逃す。
    ★ **判定の順序は expected → other → 不明**である。other を先に見ると、
      両方の母集合に在る route を「欄違い」と誤って報告する。
    """
    violations: list[str] = []
    screen_names = {f.name for f in facts.screens}
    operation_names = {f.name for f in facts.operations}

    for label, cell, expected, other in (
        ("covers_screens", assignment.screens, screen_names, operation_names),
        ("covers_operations", assignment.operations, operation_names, screen_names),
    ):
        for name in sorted(cell):
            if name in expected:
                entry = annotations.routes.get(name)
                # 未注釈 route は既存の「未注釈の route」違反の担当。ここでは黙って飛ばす
                # (KeyError で全体を落とすと、他の違反を集め終える前に走行が止まる)。
                if entry is not None and _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
                    violations.append(f"[{STAGE2}] {label} に対象外の route: {name}")
            elif name in other:
                violations.append(f"[{STAGE2}] {label} に欄違いの route: {name}")
            else:
                violations.append(f"[{STAGE2}] {label} に実在しない route: {name}")

    for label, route_facts, pool in (
        ("画面", facts.screens, assignment.screens),
        ("操作", facts.operations, assignment.operations),
    ):
        for fact in route_facts:
            entry = annotations.routes.get(fact.name)
            if entry is None or _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
                continue
            if not pool.get(fact.name):
                violations.append(
                    f"[{STAGE2}] 対象内なのにどのカードにも載っていない{label}: {fact.name} "
                    "(消化するカードの covers_* へ足すこと)"
                )

    return violations


def validate_annotations(facts: Facts, annotations: Annotations) -> list[str]:
    """注釈の定義域一致・語彙・形式・複合 method を検査し、違反行を全件返す。"""
    violations: list[str] = []

    for key in annotations.unknown_top_level:
        violations.append(f"[{STAGE2}] 未知のトップレベル項目: {key}")

    entries = annotations.routes
    screen_names = {f.name for f in facts.screens}
    surface_names = {f.name for f in facts.surface}

    for name in sorted(surface_names - set(entries)):
        violations.append(f"[{STAGE2}] 未注釈の route: {name}")
    for name in sorted(set(entries) - surface_names):
        violations.append(f"[{STAGE2}] 実装に無い route の注釈が残っている: {name}")

    for name in facts.compound:
        violations.append(
            f"[{STAGE2}] GET/HEAD と非 GET を併せ持つ route は現在の注釈モデルで表せない: {name}"
        )

    for name in sorted(surface_names & set(entries)):
        entry = entries[name]
        prefix = f"[{STAGE2}] {name}:"

        unknown = sorted(k for k in entry if k not in ANNOTATION_KEYS)
        if unknown:
            violations.append(f"{prefix} 未知の項目: {', '.join(unknown)}")

        kubun = _annotation_value(entry, "kubun")
        if kubun is None:
            violations.append(f"{prefix} kubun が無い")
        elif kubun not in KUBUN_VOCABULARY:
            violations.append(f"{prefix} 未知の区分: {kubun} (許すのは {'/'.join(KUBUN_VOCABULARY)})")

        kind = _annotation_value(entry, "kind")
        if name in screen_names:
            if kind is None:
                violations.append(f"{prefix} 画面表の route には kind が要る")
            elif kind not in SCREEN_KINDS:
                violations.append(f"{prefix} 未知の種別: {kind} (許すのは {'/'.join(SCREEN_KINDS)})")
        elif kind is not None:
            violations.append(f"{prefix} 操作表の route に kind は書けない")

        reason = _annotation_value(entry, "reason")
        if kubun in KUBUN_NEEDS_REASON:
            if reason is None:
                violations.append(f"{prefix} 区分 {kubun} には理由が要る")
            elif len(reason) < REASON_MIN_LENGTH:
                violations.append(
                    f"{prefix} 理由が {REASON_MIN_LENGTH} 文字未満 ({len(reason)} 文字)"
                )
            elif CONTROL_CHAR_RE.search(reason):
                violations.append(f"{prefix} 理由に制御文字が入っている")
        elif reason is not None:
            violations.append(f"{prefix} 区分 {kubun} に理由は書けない")

        for key in ("kind", "kubun"):
            value = _annotation_value(entry, key)
            if value is not None and any(c in value for c in FORBIDDEN_CELL_CHARS):
                violations.append(f"{prefix} {key} に表を壊す文字 (| / 改行) が入っている")

    for fact in facts.surface:
        for label, value in (("route 名", fact.name), ("URL", fact.uri), ("画面名", fact.title or "")):
            if any(c in value for c in FORBIDDEN_CELL_CHARS):
                violations.append(
                    f"[{STAGE2}] {fact.name}: {label} に表を壊す文字 (| / 改行) が入っている"
                )

    return violations


def check_notes(notes: dict[str, str]) -> list[str]:
    """散文ノートが下流ローダを騙す表を持たないことを検査する。

    `coverage/correlate.py` は operations.md を頭から走査し、直近に見たヘッダの列割当で
    以降の `|` 始まりの行を操作行として読む。**ヘッダとして読めない表が来ても列割当は
    更新されない**ので、連結される散文ノートに表があると、生成表の列割当のまま
    注釈に無い行が操作として読まれてしまう。よって**表そのものを置かせない**。

    画面側のノートにも同じ規則を課す (連結先で規則が変わる方が事故のもとになる)。
    """
    violations = []
    for name, text in notes.items():
        for lineno, raw in enumerate(text.splitlines(), start=1):
            if raw.strip().startswith("|"):
                violations.append(
                    f"[{STAGE2}] {name} {lineno} 行目: 散文ノートに表を置かない "
                    "(correlate.py が操作行として読んでしまう)"
                )

    return violations


# --------------------------------------------------------------------------- #
# 段 3 の素材: 生成物のレンダリング
# --------------------------------------------------------------------------- #
def _story_cell(assignment: frozenset[str]) -> str:
    """割当カードの集合をセルの表記へ落とす (番号の昇順・半角空白 1 つ区切り)。

    ★ 書き出す直前に**自分の出力を値域へ突き合わせる**。読み手 (`coverage/correlate.py`) は
      同じ値域を fullmatch で強制するので、生成側が契約外のセルを書いたら
      そこで走行を止める (黙って読めない目録を作らない)。
    """
    if not assignment:
        cell = STORY_CELL_EMPTY
    else:
        cell = STORY_CELL_SEPARATOR.join(sorted(assignment, key=lambda s: int(s[1:])))
    if STORY_CELL_RE.fullmatch(cell) is None:
        raise FatalError(f"[{STAGE3}] 割当セルが契約外の表記になった: {cell!r}")

    return cell


def _out_of_scope_section(
    routes: tuple[RouteFact, ...], annotations: Annotations
) -> str:
    lines = ["## 対象外の理由", ""]
    rows = [
        f"- `{fact.name}` — {_annotation_value(annotations.routes[fact.name], 'reason')}"
        for fact in routes
        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
    ]
    lines.extend(rows if rows else ["対象外に分類した route は無い。"])

    return "\n".join(lines) + "\n"


def render_screens(
    facts: Facts, annotations: Annotations, notes: str, assignment: Assignment
) -> str:
    out_of_scope = sum(
        1
        for fact in facts.screens
        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
    )
    lines = [
        "# 画面インベントリ (screens.md) — AI-CUE",
        "",
        GENERATED_NOTICE.rstrip("\n"),
        "",
        f"bug-hunt カバレッジの分母となる「画面」(GET × web セッション面) の一覧。"
        f"全 {len(facts.screens)} 件 (うち対象外 {out_of_scope} 件)。",
        "",
        "## GET × web 一覧 (画面 + 画面に付随する JSON GET)",
        "",
        "| route (URL) | name | 種別 | 画面名 | 割当ストーリー | 区分 |",
        "|---|---|---|---|---|---|",
    ]
    for fact in facts.screens:
        entry = annotations.routes[fact.name]
        lines.append(
            f"| {fact.uri} | {fact.name} | {_annotation_value(entry, 'kind')} | "
            f"{fact.title or '-'} | {_story_cell(assignment.screens.get(fact.name, frozenset()))} | "
            f"{_annotation_value(entry, 'kubun')} |"
        )
    body = "\n".join(lines) + "\n"

    return body + "\n" + _out_of_scope_section(facts.screens, annotations) + "\n" + notes


def render_operations(
    facts: Facts, annotations: Annotations, notes: str, assignment: Assignment
) -> str:
    out_of_scope = sum(
        1
        for fact in facts.operations
        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
    )
    lines = [
        "# 操作インベントリ (operations.md) — AI-CUE",
        "",
        GENERATED_NOTICE.rstrip("\n"),
        "",
        f"bug-hunt カバレッジの分母となる「書き込み操作」(非 GET × web セッション面) の一覧。"
        f"全 {len(facts.operations)} 件 (うち対象外 {out_of_scope} 件)。"
        "列は method / route / name / story / 区分 の 5 列固定 "
        "(coverage/correlate.py の入力契約。ヘッダ名を変えない)。",
        "",
        "## 操作一覧 (web セッション面)",
        "",
        "| method | route | name | story | 区分 |",
        "|---|---|---|---|---|",
    ]
    for fact in facts.operations:
        entry = annotations.routes[fact.name]
        lines.append(
            f"| {','.join(fact.write_methods)} | {fact.uri} | {fact.name} | "
            f"{_story_cell(assignment.operations.get(fact.name, frozenset()))} | "
            f"{_annotation_value(entry, 'kubun')} |"
        )
    body = "\n".join(lines) + "\n"

    return body + "\n" + _out_of_scope_section(facts.operations, annotations) + "\n" + notes


# --------------------------------------------------------------------------- #
# 段 4: 機能カタログの参照整合
# --------------------------------------------------------------------------- #
def check_catalog(catalog_text: str, facts: Facts, assignment: Assignment) -> list[str]:
    """capability-catalog.md の代表機構が実在し、id が重複しないことを検査する。

    対象はヘッダが CAPABILITY_TABLE_HEADER の表**だけ** (責務境界・割当規則の表は見ない)。
    網羅性 (すべての route が id を持つか) は見ない (overlay なので網羅を主張しない)。

    併せて、カードの `covers_capabilities` が**実在する id だけ**を挙げていることを見る。
    **被覆漏れは見ない** (機能カタログが継承宣言の欄を持たないため。既存乖離 D20。
    保証境界は stories/README.md に書いてある)。配列内の重複は `load_assignment()` が
    集合化の**前**に見る。
    """
    violations: list[str] = []
    seen: list[str] = []
    inside = False

    for raw in catalog_text.splitlines():
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

        capability_id, mechanisms = cols[0], cols[2]
        if not CAPABILITY_ID_RE.match(capability_id):
            violations.append(f"[{STAGE4}] id の書式が契約外: {capability_id}")
        elif capability_id in seen:
            violations.append(f"[{STAGE4}] id が重複している: {capability_id}")
        seen.append(capability_id)

        for token in BACKTICK_TOKEN_RE.findall(mechanisms):
            token = token.strip()
            # ファイルパスだけを候補から外す。丸括弧の説明はそもそもバッククォートで
            # 囲まれていないので候補に入らない。
            if PATH_TOKEN_RE.match(token):
                continue
            if token.endswith("*"):
                if not any(n.startswith(token[:-1]) for n in facts.all_names):
                    violations.append(
                        f"[{STAGE4}] {capability_id}: 前方一致する route が 1 件も無い: {token}"
                    )
            elif token not in facts.all_names:
                violations.append(f"[{STAGE4}] {capability_id}: 実在しない route 名: {token}")

    if not seen:
        raise FatalError(f"[{STAGE4}] 機能カタログの表が見つからない (ヘッダが変わっていないか)")

    for capability in sorted(assignment.capabilities - set(seen)):
        violations.append(f"[{STAGE4}] カードが実在しない capability を挙げている: {capability}")

    return violations


# --------------------------------------------------------------------------- #
# 差し替え (generate)
# --------------------------------------------------------------------------- #
def _read_text(path: Path, stage: str) -> str:
    if not path.is_file():
        raise FatalError(f"[{stage}] 入力ファイルが無い: {path}")

    return path.read_text(encoding="utf-8")


def _replace_atomically(pairs: list[tuple[Path, str]]) -> None:
    """2 ファイルを、部分的な成果物を残さずに差し替える。

    完全な多ファイル原子性は作らない (生成物 2 つに対して過剰)。保証するのは
    「通常の置換失敗では呼び出し前の 2 ファイルが byte 単位で保たれる」ことまでで、
    復元にも失敗したら**元 / 一時 / 控えのどれも消さず**に全パスを標準エラーへ出す。
    """
    temps: list[Path] = []
    # 元ファイルが未生成のときの控えは None (「無かった」状態へ戻せるようにする)。
    backups: list[Path | None] = []

    def cleanup(paths: list[Path] | list[Path | None]) -> None:
        for path in paths:
            if path is None:
                continue
            try:
                path.unlink()
            except OSError:
                pass

    try:
        for path, content in pairs:
            temp = path.with_suffix(path.suffix + ".tmp-generate")
            temp.write_text(content, encoding="utf-8", newline="\n")
            temps.append(temp)
        for path in (p for p, _ in pairs):
            if not path.is_file():
                backups.append(None)
                continue
            backup = path.with_suffix(path.suffix + ".bak-generate")
            backup.write_bytes(path.read_bytes())
            backups.append(backup)
    except OSError as exc:
        cleanup(temps)
        cleanup(backups)
        raise FatalError(f"[{STAGE3}] 生成物の準備に失敗した (元ファイルは無傷): {exc}") from exc

    replaced = 0
    try:
        for index, (path, _) in enumerate(pairs):
            os.replace(temps[index], path)
            replaced += 1
    except OSError as exc:
        if replaced == 0:
            cleanup(temps)
            cleanup(backups)
            raise FatalError(
                f"[{STAGE3}] 生成物の差し替えに失敗した (元ファイルは無傷。再実行せよ): {exc}"
            ) from exc
        try:
            for index in range(replaced):
                backup = backups[index]
                if backup is None:
                    # 元は存在しなかったので「無かった」状態へ戻す。
                    pairs[index][0].unlink()
                else:
                    os.replace(backup, pairs[index][0])
        except OSError as restore_exc:
            print(
                f"[{STAGE3}] 差し替えの復元に失敗した。手で戻すこと (何も消していない):\n"
                + "\n".join(
                    f"  元={pairs[i][0]} 一時={temps[i]} 控え={backups[i]}"
                    for i in range(len(pairs))
                ),
                file=sys.stderr,
            )
            raise FatalError(f"[{STAGE3}] 復元にも失敗した: {restore_exc}") from restore_exc
        cleanup(temps[replaced:])
        cleanup(backups[replaced:])
        raise FatalError(
            f"[{STAGE3}] 生成物の差し替えに失敗し、控えから元へ戻した (再実行せよ): {exc}"
        ) from exc

    try:
        for backup in backups:
            if backup is not None:
                backup.unlink()
    except OSError as exc:
        # 生成の成功は取り消さない。残ったパスを明示する。
        print(
            f"[{STAGE3}] 控えの削除に失敗した (生成は完了している。手で消すこと): "
            + ", ".join(str(b) for b in backups if b is not None),
            file=sys.stderr,
        )
        raise FatalError(f"[{STAGE3}] 控えの削除に失敗した: {exc}") from exc


# --------------------------------------------------------------------------- #
# 公開 entry
# --------------------------------------------------------------------------- #
def _prepare(
    repo_root: Path, scanner: Scanner | None
) -> tuple[Facts, Annotations, str, str, Assignment | None, list[str]]:
    """段 1 と入力の読み込みまでを行う。

    割当は読めなければ `None` になる (違反の並びを第 6 要素で返す)。**空の Assignment を
    返さない** — 呼び出し側が違反を見落としたときにそのまま目録を生成できてしまうため。
    """
    facts = split_surface((scanner or scan)(repo_root))
    annotations = load_annotations(repo_root / ANNOTATIONS_PATH)
    notes_screens = _read_text(repo_root / NOTES_SCREENS_PATH, STAGE2)
    notes_operations = _read_text(repo_root / NOTES_OPERATIONS_PATH, STAGE2)
    assignment, assignment_violations = load_assignment(repo_root / STORIES_DIR)

    return facts, annotations, notes_screens, notes_operations, assignment, assignment_violations


def _report(violations: list[str]) -> int:
    for line in violations:
        print(line, file=sys.stderr)
    print(f"ドリフト {len(violations)} 件 (再生成するか注釈を直すこと)", file=sys.stderr)

    return EXIT_DRIFT


def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
    """段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**。"""
    facts, annotations, notes_screens, notes_operations, assignment, assignment_violations = (
        _prepare(repo_root, scanner)
    )

    violations = validate_annotations(facts, annotations) + check_notes({
        NOTES_SCREENS_PATH.name: notes_screens,
        NOTES_OPERATIONS_PATH.name: notes_operations,
    }) + assignment_violations
    if assignment is None:
        # 割当が読めない状態で段 3 / 4 へ進まない (レンダリングの入力が無い)。
        return _report(violations)
    violations += validate_assignment(facts, annotations, assignment)
    if violations:
        return _report(violations)

    for path, rendered in (
        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens, assignment)),
        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations, assignment)),
    ):
        if _read_text(path, STAGE3) != rendered:
            violations.append(
                f"[{STAGE3}] 生成物が再生成の結果と一致しない: {path.name} "
                "(python3 scripts/bug-hunt-inventory.py generate を走らせること)"
            )

    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts, assignment)
    if violations:
        return _report(violations)

    print(
        f"一致: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
        f"(抽出条件 {EXTRACTION_CONDITION})"
    )

    return EXIT_OK


def run_generate(repo_root: Path, *, scanner: Scanner | None = None) -> int:
    """段 1 → 2 → 4 を通してから 2 ファイルを書き替える。"""
    facts, annotations, notes_screens, notes_operations, assignment, assignment_violations = (
        _prepare(repo_root, scanner)
    )

    violations = validate_annotations(facts, annotations) + check_notes({
        NOTES_SCREENS_PATH.name: notes_screens,
        NOTES_OPERATIONS_PATH.name: notes_operations,
    }) + assignment_violations
    if assignment is None:
        # 目録を 1 バイトも書かずに落とす。
        return _report(violations)
    violations += validate_assignment(facts, annotations, assignment)
    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts, assignment)
    if violations:
        return _report(violations)

    _replace_atomically([
        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens, assignment)),
        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations, assignment)),
    ])
    print(
        f"生成完了: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
        f"(抽出条件 {EXTRACTION_CONDITION})"
    )

    return EXIT_OK


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=("check", "generate"))
    args = parser.parse_args(argv)

    # リポジトリルートは自分の位置から決める (どの cwd から起動しても結果は同じ)。
    repo_root = Path(__file__).resolve().parent.parent
    try:
        return run_check(repo_root) if args.command == "check" else run_generate(repo_root)
    except FatalError as exc:
        print(str(exc), file=sys.stderr)

        return EXIT_FATAL
    except Exception:  # noqa: BLE001 — 想定外は 0 に畳まず traceback を出して致命にする
        traceback.print_exc()

        return EXIT_FATAL


if __name__ == "__main__":
    sys.exit(main())
