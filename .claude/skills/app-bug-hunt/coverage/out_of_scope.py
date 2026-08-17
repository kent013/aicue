#!/usr/bin/env python3
"""bug-hunt コード到達カバレッジの「対象外の面」の宣言を読み、検証し、出力する道具。

宣言 (out-of-scope.json) が答える問いは 1 つだけである:
    「この範囲のコードがコード到達カバレッジで未到達でも、なぜ穴ではないのか。
      代わりに何が検査しているのか」

本モジュールは宣言を **検証済みの型** (OutOfScopeDeclaration) へ変換して返す。
生の dict を持ち回らない。検証に通らない宣言は DeclarationError で落ち、CLI としては
**終了コード 2 かつ標準出力へ 1 バイトも書かない** (fail-closed)。

パスの検査は 2 層に分かれている。層を混ぜると covers() が repo_root を要求する形になり、
公開インターフェースと契約が食い違うためである。

  層 1 (normalize) … リポジトリに依存しない字句の正規形。実在検査・包含検査・
                     循環検査・covers() のすべてがこの 1 本を共用する。
  層 2 (load)     … repo_root を基点にした実在・境界・symlink の検査。

依存は標準ライブラリのみ。使い方:
    python3 out_of_scope.py --emit markdown
    python3 out_of_scope.py --emit json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path, PurePosixPath

# 宣言の版。増やすときは読み取り側の分岐ではなく移行を書く。
DECLARATION_VERSION = 1

# 理由と代替検証の最小長。inventory/annotations.toml の区分「外」の理由と同じ閾値を使う
# (同じ判断に別の閾値を置くと説明できなくなる)。
MIN_STATEMENT_LENGTH = 30

# 「書けば通る」空洞化を防ぐための無内容な値 (trim 後の完全一致で拒否する)。
HOLLOW_STATEMENTS = frozenset({"対象外", "なし", "-", "N/A", "TBD"})

ID_PATTERN = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")

TOP_LEVEL_KEYS = frozenset({"version", "note", "entries"})
ENTRY_KEYS = frozenset(
    {
        "id",
        "title",
        "reason",
        "alternative_verification",
        "verification_refs",
        "path_prefixes",
    }
)

# 対象パスの根。app/ の外は本宣言の管轄ではない。
PATH_PREFIX_ROOT = ("app",)

# 一撃で全体を対象外にする幹。規則を推測させないため明示的な禁止集合との完全一致で判定する。
TRUNK_PREFIXES = frozenset(
    {
        ("app",),
        ("app", "Http"),
        ("app", "Http", "Controllers"),
    }
)

# 自己言及の禁止先 (宣言自身と監査文書)。
DECLARATION_REL_PATH = ".claude/skills/app-bug-hunt/coverage/out-of-scope.json"
AUDIT_DOC_REL_PATH = ".claude/skills/app-bug-hunt/coverage-audit.md"

DEFAULT_DECLARATION = Path(__file__).resolve().parent / "out-of-scope.json"
# coverage -> app-bug-hunt -> skills -> .claude -> リポジトリルート
DEFAULT_REPO_ROOT = Path(__file__).resolve().parents[4]


class DeclarationError(Exception):
    """宣言が契約を満たさない (読み込み失敗・書式違反・境界違反をすべて含む)。"""


@dataclass(frozen=True)
class OutOfScopeEntry:
    """対象外の面 1 件。理由か代替検証が違うなら別の面である。"""

    id: str
    title: str
    reason: str
    alternative_verification: str
    verification_refs: tuple[str, ...]
    path_prefixes: tuple[str, ...]


@dataclass(frozen=True)
class OutOfScopeDeclaration:
    """検証済みの宣言全体。entries の並び順は宣言の並び順を保つ。"""

    version: int
    note: str
    entries: tuple[OutOfScopeEntry, ...]


def normalize(raw: object) -> tuple[str, ...]:
    """層 1: 正規形の相対パスをパス要素の列にする。非正規形は DeclarationError。

    PurePosixPath へ入れた後では `a//b` や `.` が畳まれて元の非正規形を検出できないため、
    変換より前に生の文字列のまま拒否する。
    """
    if not isinstance(raw, str):
        raise DeclarationError("パスは文字列である必要がある")
    if raw.strip() == "":
        raise DeclarationError("パスが空である")
    if "\\" in raw:
        raise DeclarationError(f"パスにバックスラッシュがある: {raw}")
    if raw.startswith("/"):
        raise DeclarationError(f"絶対パスは書けない: {raw}")
    if raw.endswith("/"):
        raise DeclarationError(f"末尾スラッシュは書けない: {raw}")

    segments = raw.split("/")
    for segment in segments:
        if segment.strip() == "":
            raise DeclarationError(f"空のパス要素がある: {raw}")
        if segment in (".", ".."):
            raise DeclarationError(f"相対指定は書けない: {raw}")

    parts = PurePosixPath(raw).parts
    if parts != tuple(segments):
        raise DeclarationError(f"正規形の相対パスではない: {raw}")

    return parts


def covers(declaration: OutOfScopeDeclaration, rel_path: str) -> OutOfScopeEntry | None:
    """そのパスを覆う面を返す (無ければ None)。層 1 だけを使い repo_root を要求しない。

    宣言は antichain (どの対象パスも他を包含しない) なので結果は並び順に依存しない。
    """
    target = normalize(rel_path)
    for entry in declaration.entries:
        for prefix in entry.path_prefixes:
            parts = normalize(prefix)
            if target[: len(parts)] == parts:
                return entry

    return None


def load(path: Path | str, repo_root: Path | str) -> OutOfScopeDeclaration:
    """宣言を読み、層 1 と層 2 の両方を検証して返す。"""
    declaration_path = Path(path)
    root = _resolve_or_fail(Path(repo_root), "基点")

    raw = _read_json(declaration_path)
    entries = _build_entries(raw)
    _reject_overlapping_prefixes(entries)
    _verify_against_repository(entries, root)

    return OutOfScopeDeclaration(
        version=DECLARATION_VERSION,
        note=_require_text(raw["note"], "note"),
        entries=entries,
    )


def render_markdown(declaration: OutOfScopeDeclaration) -> str:
    """人が読む表を作る (値に含まれる区切りと改行は退避する)。"""
    header = "| id | 面 | ブラウザ走行では検査できない理由 | 代替検証 | 対象パス |"
    lines = [header, "|---|---|---|---|---|"]
    for entry in declaration.entries:
        alternative = (
            entry.alternative_verification + " 参照: " + " / ".join(entry.verification_refs)
        )
        cells = [
            _markdown_cell(entry.id),
            _markdown_cell(entry.title),
            _markdown_cell(entry.reason),
            _markdown_cell(alternative),
            _markdown_cell(" / ".join(entry.path_prefixes)),
        ]
        lines.append("| " + " | ".join(cells) + " |")

    return "\n".join(lines) + "\n"


def render_json(declaration: OutOfScopeDeclaration) -> str:
    """正規化済みトップレベル object を返す (宣言の並び順を保つ)。"""
    payload = {
        "version": declaration.version,
        "note": declaration.note,
        "entries": [
            {
                "id": entry.id,
                "title": entry.title,
                "reason": entry.reason,
                "alternative_verification": entry.alternative_verification,
                "verification_refs": list(entry.verification_refs),
                "path_prefixes": list(entry.path_prefixes),
            }
            for entry in declaration.entries
        ],
    }

    return json.dumps(payload, ensure_ascii=False, indent=2) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="bug-hunt コード到達カバレッジの対象外宣言を検証して出力する",
    )
    parser.add_argument("--declaration", default=str(DEFAULT_DECLARATION), help="宣言ファイル")
    parser.add_argument("--repo-root", default=str(DEFAULT_REPO_ROOT), help="実在検査の基点")
    parser.add_argument("--emit", choices=("markdown", "json"), default="markdown")
    args = parser.parse_args(argv)

    try:
        declaration = load(args.declaration, args.repo_root)
        # 出力は組み立て切ってから書く (途中で落ちて標準出力を汚さないため)。
        rendered = render_markdown(declaration) if args.emit == "markdown" else render_json(declaration)
    except DeclarationError as error:
        sys.stderr.write(_single_line(f"対象外宣言が不正です: {error}") + "\n")

        return 2

    sys.stdout.write(rendered)

    return 0


def _read_json(declaration_path: Path) -> dict:
    """読み込みと parse の失敗を理由を問わず DeclarationError へ落とす。"""
    try:
        text = declaration_path.read_text(encoding="utf-8")
    except (OSError, UnicodeError) as error:
        raise DeclarationError(f"宣言を読めない: {error}") from error

    try:
        # 重複キーは json.loads が黙って後勝ちで畳む。レビューで見えている値と
        # 実際に採用される値がずれるので object_pairs_hook で拒否する。
        raw = json.loads(text, object_pairs_hook=_reject_duplicate_keys)
    except (ValueError, RecursionError) as error:
        raise DeclarationError(f"宣言を JSON として読めない: {error}") from error

    if not isinstance(raw, dict):
        raise DeclarationError("宣言のトップレベルは object である必要がある")

    _require_exact_keys(set(raw), TOP_LEVEL_KEYS, "トップレベル")

    if type(raw["version"]) is not int or raw["version"] != DECLARATION_VERSION:
        # 真偽値は int の派生なので isinstance では通ってしまう。type で見る。
        raise DeclarationError(f"version は整数の {DECLARATION_VERSION} である必要がある")

    _require_text(raw["note"], "note")

    return raw


def _build_entries(raw: dict) -> tuple[OutOfScopeEntry, ...]:
    rows = raw["entries"]
    if not isinstance(rows, list):
        raise DeclarationError("entries は配列である必要がある")
    if not rows:
        raise DeclarationError("entries は 1 件以上である必要がある")

    entries: list[OutOfScopeEntry] = []
    seen_ids: set[str] = set()
    seen_refs: set[str] = set()
    for index, row in enumerate(rows):
        if not isinstance(row, dict):
            raise DeclarationError(f"entries[{index}] は object である必要がある")
        _require_exact_keys(set(row), ENTRY_KEYS, f"entries[{index}]")

        identifier = _require_text(row["id"], f"entries[{index}].id")
        if not ID_PATTERN.match(identifier):
            raise DeclarationError(f"id の書式が不正: {identifier}")
        if identifier in seen_ids:
            raise DeclarationError(f"id が重複している: {identifier}")
        seen_ids.add(identifier)

        refs = _require_text_list(row["verification_refs"], f"{identifier}.verification_refs")
        for ref in refs:
            normalize(ref)
            if ref in seen_refs:
                raise DeclarationError(f"verification_refs が重複している: {ref}")
            seen_refs.add(ref)

        prefixes = _require_text_list(row["path_prefixes"], f"{identifier}.path_prefixes")
        for prefix in prefixes:
            parts = normalize(prefix)
            if parts[: len(PATH_PREFIX_ROOT)] != PATH_PREFIX_ROOT:
                raise DeclarationError(f"対象パスは app/ 配下である必要がある: {prefix}")
            if parts in TRUNK_PREFIXES:
                raise DeclarationError(f"幹は対象パスにできない: {prefix}")

        entries.append(
            OutOfScopeEntry(
                id=identifier,
                title=_require_text(row["title"], f"{identifier}.title"),
                reason=_require_statement(row["reason"], f"{identifier}.reason"),
                alternative_verification=_require_statement(
                    row["alternative_verification"], f"{identifier}.alternative_verification"
                ),
                verification_refs=refs,
                path_prefixes=prefixes,
            )
        )

    return tuple(entries)


def _reject_overlapping_prefixes(entries: tuple[OutOfScopeEntry, ...]) -> None:
    """対象パスを全 entry の直積で antichain にする (完全重複も包含も禁止)。"""
    collected: list[tuple[str, tuple[str, ...]]] = []
    for entry in entries:
        for prefix in entry.path_prefixes:
            collected.append((prefix, normalize(prefix)))

    for i, (left_raw, left) in enumerate(collected):
        for right_raw, right in collected[i + 1 :]:
            if left == right:
                raise DeclarationError(f"対象パスが重複している: {left_raw}")
            shorter, longer = (left, right) if len(left) < len(right) else (right, left)
            if longer[: len(shorter)] == shorter:
                raise DeclarationError(f"対象パスが包含関係にある: {left_raw} と {right_raw}")


def _verify_against_repository(entries: tuple[OutOfScopeEntry, ...], root: Path) -> None:
    """層 2: 実在・境界・symlink・循環参照を repo_root を基点に検査する。"""
    prefixes = [normalize(p) for entry in entries for p in entry.path_prefixes]
    self_references = (normalize(DECLARATION_REL_PATH), normalize(AUDIT_DOC_REL_PATH))

    for entry in entries:
        for prefix in entry.path_prefixes:
            _resolve_within(root, normalize(prefix), f"{entry.id} の対象パス {prefix}")

        for ref in entry.verification_refs:
            parts = normalize(ref)
            # 重なりは**両方向**で見る。子方向だけを見ると、宣言自身や対象外の面を
            # 内包する祖先 (`app` / `.claude/skills/app-bug-hunt/coverage`) を
            # 代替検証に書いて自己言及の禁止をすり抜けられる。
            for other in self_references:
                if _overlaps(parts, other):
                    raise DeclarationError(f"代替検証に宣言自身や監査文書は書けない: {ref}")
            for prefix in prefixes:
                if _overlaps(parts, prefix):
                    raise DeclarationError(f"代替検証が対象外の面そのものを指している: {ref}")
            _resolve_within(root, parts, f"{entry.id} の代替検証 {ref}")


def _overlaps(left: tuple[str, ...], right: tuple[str, ...]) -> bool:
    """どちらかがもう一方の接頭辞になっているか (完全一致を含む)。"""
    shorter, longer = (left, right) if len(left) <= len(right) else (right, left)

    return longer[: len(shorter)] == shorter


def _resolve_within(root: Path, parts: tuple[str, ...], label: str) -> Path:
    """repo_root を基点に解決し、symlink・不在・repo の外を拒否する。

    先に完全解決すると「どの要素が symlink だったか」が失われるため、
    先頭から 1 要素ずつたどって各要素を見る (末尾だけ見ると親が symlink の場合を通す)。
    """
    current = root
    for part in parts:
        current = current / part
        try:
            is_link = current.is_symlink()
        except OSError as error:
            raise DeclarationError(f"{label} の経路を辿れない: {error}") from error
        if is_link:
            raise DeclarationError(f"{label} の経路に symlink がある")

    try:
        exists = current.exists()
    except OSError as error:
        raise DeclarationError(f"{label} の実在を確かめられない: {error}") from error
    if not exists:
        raise DeclarationError(f"{label} が実在しない")

    resolved = _resolve_or_fail(current, label)
    if resolved != root and root not in resolved.parents:
        raise DeclarationError(f"{label} がリポジトリの外を指している")

    return current


def _resolve_or_fail(path: Path, label: str) -> Path:
    """パス解決の失敗 (symlink の輪・入出力エラー) も DeclarationError へ収束させる。

    ここを素通しにすると、文書化した「終了コード 2・traceback を出さない」契約から
    外れて終了コード 1 の traceback になる。
    """
    try:
        return path.resolve()
    except (OSError, RuntimeError, ValueError) as error:
        raise DeclarationError(f"{label} のパスを解決できない: {error}") from error


def _require_exact_keys(actual: set[str], expected: frozenset[str], label: str) -> None:
    missing = sorted(expected - actual)
    if missing:
        raise DeclarationError(f"{label} に必須キーが無い: {', '.join(missing)}")
    unknown = sorted(actual - expected)
    if unknown:
        raise DeclarationError(f"{label} に未知のキーがある: {', '.join(unknown)}")


def _require_text(value: object, label: str) -> str:
    if not isinstance(value, str):
        raise DeclarationError(f"{label} は文字列である必要がある")
    if value.strip() == "":
        raise DeclarationError(f"{label} が空である")

    return value


def _require_statement(value: object, label: str) -> str:
    text = _require_text(value, label)
    trimmed = text.strip()
    if trimmed in HOLLOW_STATEMENTS:
        raise DeclarationError(f"{label} が無内容である")
    if len(trimmed) < MIN_STATEMENT_LENGTH:
        raise DeclarationError(f"{label} は {MIN_STATEMENT_LENGTH} 文字以上である必要がある")

    return text


def _require_text_list(value: object, label: str) -> tuple[str, ...]:
    if not isinstance(value, list):
        raise DeclarationError(f"{label} は配列である必要がある")
    if not value:
        raise DeclarationError(f"{label} は 1 件以上である必要がある")

    return tuple(_require_text(item, f"{label}[{i}]") for i, item in enumerate(value))


def _markdown_cell(value: str) -> str:
    """表を壊さないよう区切りを退避し、改行を空白へ畳む。

    畳む対象は診断の 1 行化 (`_single_line`) と同じで、Python が行区切りとして数える
    文字すべてである。CR / LF だけを見ると、値に混ぜられた 1 文字で表が分断される。
    """
    escaped = value.replace("\\", "\\\\").replace("|", "\\|")

    return " ".join(escaped.splitlines())


def _reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict:
    """JSON object の重複キーを拒否する (後勝ちで畳ませない)。"""
    seen: set[str] = set()
    for key, _ in pairs:
        if key in seen:
            raise DeclarationError(f"JSON にキーの重複がある: {key}")
        seen.add(key)

    return dict(pairs)


def _single_line(message: str) -> str:
    """診断を 1 行に保つ (外部入力の改行で契約を壊されないため)。

    CR / LF だけでなく、Python が行区切りとして数える文字 (NEL・行区切り・段落区切り) も
    畳む。ここを取りこぼすと、値に混ぜられた 1 文字で 1 行という契約が破れる。
    """
    return " ".join(message.splitlines())


if __name__ == "__main__":
    sys.exit(main())
