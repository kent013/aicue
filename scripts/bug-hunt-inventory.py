#!/usr/bin/env python3
"""bug-hunt 目録 (画面一覧 / 操作一覧) の生成器兼検査器。

目録は**生成物**である。実装から取れる機械事実 (route 定義 / 画面題名) と、人が書く注釈
(`inventory/annotations.toml`) と散文 (`inventory/notes-*.md`) を合成して
`.claude/skills/app-bug-hunt/{screens,operations}.md` を作る。

    generate … 段 1 → 2 → 4 を通してから 2 ファイルを書き替える
    check    … 段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**

    段 1 (抽出)         抽出コマンドが成功し、宣言した抽出条件で走り、母集合が 0 件でない
    段 2 (注釈)         注釈の集合 = 面の集合。語彙・必須・形式・複合 method を検査する
    段 3 (生成物)       メモリ上で再生成した内容と現物を byte 比較する
    段 4 (機能カタログ) capability-catalog.md の代表機構が実在し、id が重複しない

終了コード: 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件・空名・重複名・
入力ファイル不在・壊れた TOML・想定外例外) / 3=ドリフト (段 2 / 3 / 4 の違反)。
**1 と 4 以上は使わない** (argparse が引数エラーで返す 2 は「致命」の側に落ちる)。

保証しないもの: 見るのは web group を宣言した面だけである。web group を宣言していない面
(機械向け API / Filament 管理画面 / MCP / 現在の webhook の大半) には**沈黙する**。
逆に web group を宣言した route は面の除外表の 2 つを除き必ず目録に入り、注釈を要求される
(実例: `webhooks.ses` は web 面なので操作表に載り、区分 `外` として理由付きで宣言されている)。
注釈の**内容**の妥当性・画面題名の欠落・機能カタログの網羅性も見ない。

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
KUBUN_NEEDS_STORY = ("通常", "逸")
KUBUN_NEEDS_REASON = ("外", "終")

STORY_IDS = tuple(f"S{i}" for i in range(1, 8))
SCREEN_KINDS = ("画面", "JSON")
ANNOTATION_KEYS = ("kind", "story", "kubun", "reason")
REASON_MIN_LENGTH = 30

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
ANNOTATIONS_PATH = SKILL_DIR / "inventory" / "annotations.toml"
NOTES_SCREENS_PATH = SKILL_DIR / "inventory" / "notes-screens.md"
NOTES_OPERATIONS_PATH = SKILL_DIR / "inventory" / "notes-operations.md"
SCREENS_PATH = SKILL_DIR / "screens.md"
OPERATIONS_PATH = SKILL_DIR / "operations.md"
CATALOG_PATH = SKILL_DIR / "capability-catalog.md"

GENERATED_NOTICE = (
    "> **このファイルは生成物である。手で編集しない。**\n"
    "> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か\n"
    "> `inventory/notes-*.md` (散文) を直してから "
    "`python3 scripts/bug-hunt-inventory.py generate` を走らせる。\n"
    "> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。\n"
    "> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。\n"
)

Scanner = Callable[[Path], object]


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

        story = _annotation_value(entry, "story")
        if kubun in KUBUN_NEEDS_STORY:
            if story is None:
                violations.append(f"{prefix} 区分 {kubun} には story が要る")
            elif story not in STORY_IDS:
                violations.append(f"{prefix} 未知のストーリー: {story}")
        elif kubun in KUBUN_NEEDS_REASON and story is not None:
            # 表では `-` に潰れて見えなくなる古い割当を残さない。
            violations.append(f"{prefix} 区分 {kubun} に story は書けない")

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

        for key in ("kind", "story", "kubun"):
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
def _story_cell(entry: dict[str, object]) -> str:
    story = _annotation_value(entry, "story")
    return story if story is not None else "-"


def _out_of_scope_section(
    routes: tuple[RouteFact, ...], annotations: Annotations
) -> str:
    lines = ["## 対象外の理由", ""]
    rows = [
        f"- `{fact.name}` — {_annotation_value(annotations.routes[fact.name], 'reason')}"
        for fact in routes
        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
    ]
    lines.extend(rows if rows else ["対象外に分類した route は無い。"])

    return "\n".join(lines) + "\n"


def render_screens(
    facts: Facts, annotations: Annotations, notes: str
) -> str:
    out_of_scope = sum(
        1
        for fact in facts.screens
        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
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
            f"{fact.title or '-'} | {_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
        )
    body = "\n".join(lines) + "\n"

    return body + "\n" + _out_of_scope_section(facts.screens, annotations) + "\n" + notes


def render_operations(
    facts: Facts, annotations: Annotations, notes: str
) -> str:
    out_of_scope = sum(
        1
        for fact in facts.operations
        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
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
            f"{_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
        )
    body = "\n".join(lines) + "\n"

    return body + "\n" + _out_of_scope_section(facts.operations, annotations) + "\n" + notes


# --------------------------------------------------------------------------- #
# 段 4: 機能カタログの参照整合
# --------------------------------------------------------------------------- #
def check_catalog(catalog_text: str, facts: Facts) -> list[str]:
    """capability-catalog.md の代表機構が実在し、id が重複しないことを検査する。

    対象はヘッダが CAPABILITY_TABLE_HEADER の表**だけ** (責務境界・割当規則の表は見ない)。
    網羅性 (すべての route が id を持つか) は見ない (overlay なので網羅を主張しない)。
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
def _prepare(repo_root: Path, scanner: Scanner | None) -> tuple[Facts, Annotations, str, str]:
    """段 1 と入力の読み込みまでを行う。"""
    facts = split_surface((scanner or scan)(repo_root))
    annotations = load_annotations(repo_root / ANNOTATIONS_PATH)
    notes_screens = _read_text(repo_root / NOTES_SCREENS_PATH, STAGE2)
    notes_operations = _read_text(repo_root / NOTES_OPERATIONS_PATH, STAGE2)

    return facts, annotations, notes_screens, notes_operations


def _report(violations: list[str]) -> int:
    for line in violations:
        print(line, file=sys.stderr)
    print(f"ドリフト {len(violations)} 件 (再生成するか注釈を直すこと)", file=sys.stderr)

    return EXIT_DRIFT


def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
    """段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**。"""
    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)

    violations = validate_annotations(facts, annotations) + check_notes({
        NOTES_SCREENS_PATH.name: notes_screens,
        NOTES_OPERATIONS_PATH.name: notes_operations,
    })
    if violations:
        return _report(violations)

    for path, rendered in (
        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
    ):
        if _read_text(path, STAGE3) != rendered:
            violations.append(
                f"[{STAGE3}] 生成物が再生成の結果と一致しない: {path.name} "
                "(python3 scripts/bug-hunt-inventory.py generate を走らせること)"
            )

    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
    if violations:
        return _report(violations)

    print(
        f"一致: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
        f"(抽出条件 {EXTRACTION_CONDITION})"
    )

    return EXIT_OK


def run_generate(repo_root: Path, *, scanner: Scanner | None = None) -> int:
    """段 1 → 2 → 4 を通してから 2 ファイルを書き替える。"""
    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)

    violations = validate_annotations(facts, annotations) + check_notes({
        NOTES_SCREENS_PATH.name: notes_screens,
        NOTES_OPERATIONS_PATH.name: notes_operations,
    })
    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
    if violations:
        return _report(violations)

    _replace_atomically([
        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
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
