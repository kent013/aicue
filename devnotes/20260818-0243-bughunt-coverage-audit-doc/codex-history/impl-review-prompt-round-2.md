# Round 2: Round 1 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] 自己言及検査に祖先パスによる迂回がある (`out_of_scope.py`)

- 判断: 対応する
- 根拠: 指摘のとおり。`verification_refs: ["app"]` や
  `[".claude/skills/app-bug-hunt/coverage"]` は、対象外の面や宣言自身を**内包する祖先**であり、
  子方向の判定だけでは通ってしまう。D27 に「代替検証が自己言及でない」と書いた以上、
  実装がその保証に届いていないのは記述の誇張になる。
- 対応内容: `_overlaps()` を新設し、重なりを**両方向**で判定するようにした
  (対象パスとの重なり・宣言自身・監査文書の 3 つすべてに適用)。
  負の対照を実測で確認済み — 子方向だけの判定へ戻すと `["app"]` が通ってしまう。

## [Warning] JSON の重複キーを検出できない (`out_of_scope.py`)

- 判断: 対応する
- 根拠: `json.loads()` は同一 object 内の重複キーを黙って後勝ちで畳むため、
  必須キー・未知キーの検査を通っても、レビューで見えている値と実際に採用される値が
  食い違いうる。deny-by-default の宣言形式としては穴である。
- 対応内容: `object_pairs_hook=_reject_duplicate_keys` を渡し、トップレベルと entry の
  両方で重複キーを拒否するようにした。負の対照も実測で確認済み。

## [Warning] パス解決の失敗が DeclarationError へ収束しない (`out_of_scope.py`)

- 判断: 対応する
- 根拠: 「終了コード 2 / traceback を出さない」と文書に書いた契約が、
  symlink の輪や入出力エラーで破れうる。fail-closed の実体に関わる。
- 対応内容: `_resolve_or_fail()` を新設し、`resolve()` と `is_symlink()` / `exists()` の
  失敗をすべて `DeclarationError` へ収束させた。基点 (`repo_root`) の解決も同じ関数を通す。

## [Warning] 実装上の迂回に対応する負のテストが無い (`test_out_of_scope.py`)

- 判断: 対応する
- 根拠: 検査と同じ穴を共有しているテストは空振りする。
- 対応内容: 次の 4 本を足した。
  - 祖先による自己言及 (`app` / `.claude/skills/app-bug-hunt/coverage` / `.claude`) の拒否
  - トップレベルと entry の JSON 重複キーの拒否
  - symlink の輪でも 終了コード 2 / stdout 空 / traceback なし になること
  - 値に混ぜられた Unicode の行区切りでも stderr が 1 行に保たれること

## [Warning] `queued-job` の代替検証が狭い (`out-of-scope.json`)

- 判断: 対応する
- 根拠: `app/Jobs` 全体を対象外にしながら、代替検証が「待ち時間の扱いと重複実行の目録」しか
  指していないと、各ジョブの業務挙動を誰も見ていないように読める。
  実際にはドメイン側の Feature テストが各ジョブを実走させている。
- 対応内容: 実際にジョブを実走させている Feature テスト 3 本
  (合成の一連の流れ / テイクの実体削除 / 自動チャージ) を `verification_refs` に足し、
  説明文も「各ジョブの業務挙動はドメイン側の Feature テストが検査する」と書き直した。

## [Suggestion] `_single_line()` が CR / LF しか畳まない

- 判断: 対応する
- 根拠: 指摘のとおり、行区切り 1 文字で「1 行」という契約を壊せる。
  `str.splitlines()` を使えば標準ライブラリの作法のまま塞げる (安い)。
- 対応内容: `" ".join(message.splitlines())` へ変更し、負の対照テストを足した。

## [Suggestion] `ID_PATTERN` が末尾ハイフン・連続ハイフンを許す

- 判断: 対応する
- 根拠: id は参照される語彙なので、表記ゆれを許さないほうがよい。安い。
- 対応内容: `^[a-z0-9]+(?:-[a-z0-9]+)*$` へ変更した。

## [Suggestion] middleware の docblock が env と config を混ぜている

- 判断: 対応する
- 根拠: 実装の判定窓口は `config('bughunt.pcov.enabled')` であり、env は値の出所にすぎない。
  説明が実装とずれていると、guard を外すときの判断材料を誤らせる。
- 対応内容: docblock を「設定 config('bughunt.pcov.enabled') (値の出所は env の BUGHUNT_PCOV)」
  と書き直した。実装 (コード) には触れていない。

## 修正後の `coverage/out_of_scope.py` (全文)

```python
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
    """表を壊さないよう区切りを退避し、改行を空白へ畳む。"""
    escaped = value.replace("\\", "\\\\").replace("|", "\\|")

    return escaped.replace("\r\n", " ").replace("\n", " ").replace("\r", " ")


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
```

## 修正後の `coverage/test_out_of_scope.py` (全文)

```python
"""コード到達カバレッジの「対象外の面」の宣言 (out-of-scope.json) の契約テスト。

1 契約 1 テスト。実データ (実宣言) の妥当性と、読み取り器の拒否契約と、
CLI の終了コード契約 (実プロセス起動) を固定する。

依存は標準ライブラリのみ。実行:
    cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_out_of_scope
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import out_of_scope
from out_of_scope import (
    AUDIT_DOC_REL_PATH,
    DECLARATION_REL_PATH,
    DEFAULT_DECLARATION,
    DEFAULT_REPO_ROOT,
    DeclarationError,
    covers,
    load,
    normalize,
)

MODULE_PATH = Path(out_of_scope.__file__).resolve()

# 承認済み範囲のスナップショット (施策 3 の 17 番)。
# **宣言から生成しない** — テスト側に独立に書くことで、対象外の増減が必ずこの定数の
# diff としてレビューに出るようにする。運用上の正本は JSON の側である。
APPROVED_SCOPE: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("filament-admin", ("app/Filament", "app/Providers/Filament", "app/Http/Controllers/Admin")),
    ("seo-static-delivery", ("app/Http/Controllers/Seo", "app/Providers/SeoServiceProvider.php")),
    ("inbound-webhook", ("app/Http/Controllers/Webhooks",)),
    ("mcp-oauth-interface", ("app/Mcp", "app/Passport")),
    ("rest-api", ("app/Http/Controllers/Api",)),
    ("artisan-command", ("app/Console",)),
    ("queued-job", ("app/Jobs",)),
    (
        "bughunt-external-fake",
        ("app/Http/Controllers/Testing", "app/Providers/BughuntFakesServiceProvider.php"),
    ),
)


def _long(text: str) -> str:
    """30 文字以上の説明文を作る (閾値そのものの検査は専用テストで行う)。"""
    filler = "この面をブラウザ走行で検査できない事情をここに十分な長さで説明する。"
    return text + filler


def _split_md_row(row: str) -> list[str]:
    """markdown の 1 行を、退避された区切り (\\|) を区別して分解する。

    素の split('|') では退避された区切りまで数えてしまい、表の崩壊を検出できない。
    """
    cells: list[str] = []
    buffer = ""
    escaped = False
    for char in row:
        if escaped:
            buffer += char
            escaped = False
            continue
        if char == "\\":
            escaped = True
            continue
        if char == "|":
            cells.append(buffer.strip())
            buffer = ""
            continue
        buffer += char
    cells.append(buffer.strip())
    # 先頭と末尾の縦棒による空セルを落とす。
    return cells[1:-1]


def _is_tracked(rel_path: str, tracked: frozenset[str]) -> bool:
    """追跡集合に対し、ファイルは完全一致・ディレクトリはパス要素の境界で判定する。"""
    if rel_path in tracked:
        return True
    prefix = tuple(rel_path.split("/"))
    for candidate in tracked:
        parts = tuple(candidate.split("/"))
        if len(parts) > len(prefix) and parts[: len(prefix)] == prefix:
            return True
    return False


class SyntheticRepo:
    """層 2 (実在・symlink・追跡) の検査に使う合成リポジトリ。"""

    def __init__(self, root: Path) -> None:
        self.root = root
        for rel in (
            "app/Alpha",
            "app/Beta",
            "app/Http/Controllers/Gamma",
            "tests/Feature/Alpha",
            "tests/Feature/Beta",
            ".claude/skills/app-bug-hunt/coverage",
        ):
            (root / rel).mkdir(parents=True, exist_ok=True)
        (root / "app/Alpha/Alpha.php").write_text("<?php\n", encoding="utf-8")
        (root / "app/Beta/Beta.php").write_text("<?php\n", encoding="utf-8")
        (root / "app/Http/Controllers/Gamma/Gamma.php").write_text("<?php\n", encoding="utf-8")
        (root / "tests/Feature/Alpha/AlphaTest.php").write_text("<?php\n", encoding="utf-8")
        (root / "tests/Feature/Beta/BetaTest.php").write_text("<?php\n", encoding="utf-8")
        (root / DECLARATION_REL_PATH).write_text("{}\n", encoding="utf-8")
        (root / AUDIT_DOC_REL_PATH).write_text("# audit\n", encoding="utf-8")

    def payload(self) -> dict:
        return {
            "version": 1,
            "note": "合成リポジトリ向けの宣言 (テスト用)。",
            "entries": [
                {
                    "id": "alpha",
                    "title": "アルファ面",
                    "reason": _long("アルファ面は利用者が到達しない。"),
                    "alternative_verification": _long("アルファ面の挙動は Feature テストが見る。"),
                    "verification_refs": ["tests/Feature/Alpha"],
                    "path_prefixes": ["app/Alpha"],
                },
                {
                    "id": "beta",
                    "title": "ベータ面",
                    "reason": _long("ベータ面はブラウザ操作では発火しない。"),
                    "alternative_verification": _long("ベータ面の挙動は Feature テストが見る。"),
                    "verification_refs": ["tests/Feature/Beta"],
                    "path_prefixes": ["app/Beta"],
                },
            ],
        }

    def write(self, payload: dict) -> Path:
        target = self.root / "declaration.json"
        target.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        return target

    def load(self, payload: dict):
        return load(self.write(payload), self.root)


class SyntheticCase(unittest.TestCase):
    """合成リポジトリを持つテストの土台。"""

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.repo = SyntheticRepo(Path(self.tmp.name))

    def assertRejects(self, payload: dict, hint: str) -> None:
        with self.assertRaises(DeclarationError, msg=hint):
            self.repo.load(payload)

    def valid(self) -> dict:
        return self.repo.payload()


class RealDeclarationTest(unittest.TestCase):
    """1 / 17 / 23: 実データそのものの契約。"""

    def setUp(self) -> None:
        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)

    def test_1_real_declaration_loads(self) -> None:
        self.assertEqual(self.declaration.version, 1)
        self.assertTrue(self.declaration.entries)

    def test_17_matches_approved_scope_snapshot(self) -> None:
        actual = tuple((e.id, e.path_prefixes) for e in self.declaration.entries)
        self.assertEqual(
            actual,
            APPROVED_SCOPE,
            "対象外の面が承認済み範囲と食い違う (増減はどちらでも赤にする)",
        )

    def test_23_audit_document_does_not_copy_the_list(self) -> None:
        audit = (DEFAULT_REPO_ROOT / AUDIT_DOC_REL_PATH).read_text(encoding="utf-8")
        leaked: list[str] = []
        for entry in self.declaration.entries:
            for literal in (entry.id, entry.title, *entry.path_prefixes):
                if literal in audit:
                    leaked.append(literal)
        self.assertEqual(leaked, [], "監査文書に対象外の面の一覧が複製されている: " + str(leaked))


class TrackedRefsTest(unittest.TestCase):
    """14 / 14b: 代替検証と対象パスが git の追跡下にあること。"""

    def setUp(self) -> None:
        self.declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)

    def _tracked(self) -> frozenset[str]:
        proc = subprocess.run(
            ["git", "-C", str(DEFAULT_REPO_ROOT), "ls-files", "-z"],
            capture_output=True,
        )
        # git が使えない環境は skip ではなく fail にする (環境不備を隠さない)。
        self.assertEqual(proc.returncode, 0, "git ls-files を実行できない: " + proc.stderr.decode())
        return frozenset(p for p in proc.stdout.decode("utf-8").split("\0") if p)

    def test_14_refs_and_prefixes_are_tracked(self) -> None:
        tracked = self._tracked()
        untracked: list[str] = []
        for entry in self.declaration.entries:
            for rel in (*entry.verification_refs, *entry.path_prefixes):
                if not _is_tracked(rel, tracked):
                    untracked.append(rel)
        self.assertEqual(untracked, [], "追跡下にないパスが宣言されている: " + str(untracked))

    def test_14b_directory_tracking_uses_segment_boundary(self) -> None:
        tracked = frozenset({"tests/Foobar/Test.php"})
        self.assertFalse(_is_tracked("tests/Foo", tracked))
        self.assertTrue(_is_tracked("tests/Foobar", tracked))


class RequiredKeysTest(SyntheticCase):
    """2 / 3: 必須キーの欠落と未知キー。"""

    def test_2_missing_top_level_key_is_rejected(self) -> None:
        for key in ("version", "note", "entries"):
            payload = self.valid()
            del payload[key]
            self.assertRejects(payload, f"トップレベル {key} の欠落を通した")

    def test_2_missing_entry_key_is_rejected(self) -> None:
        for key in (
            "id",
            "title",
            "reason",
            "alternative_verification",
            "verification_refs",
            "path_prefixes",
        ):
            payload = self.valid()
            del payload["entries"][0][key]
            self.assertRejects(payload, f"entry の {key} 欠落を通した")

    def test_3_unknown_key_is_rejected(self) -> None:
        payload = self.valid()
        payload["extra"] = 1
        self.assertRejects(payload, "トップレベルの未知キーを通した")

        payload = self.valid()
        payload["entries"][0]["extra"] = 1
        self.assertRejects(payload, "entry の未知キーを通した")


class TypeContractTest(SyntheticCase):
    """4 / 5: 型の厳密判定。"""

    def test_4_wrong_types_are_rejected(self) -> None:
        self.assertRejects([], "トップレベルが配列でも通した")

        payload = self.valid()
        payload["entries"] = {"alpha": {}}
        self.assertRejects(payload, "entries が object でも通した")

        payload = self.valid()
        payload["entries"] = []
        self.assertRejects(payload, "entries が空でも通した")

        payload = self.valid()
        payload["entries"][0]["title"] = 12345
        self.assertRejects(payload, "文字列欄が数値でも通した")

        payload = self.valid()
        payload["entries"][0]["verification_refs"] = [12345]
        self.assertRejects(payload, "配列要素が非文字列でも通した")

        payload = self.valid()
        payload["entries"][0]["title"] = "   "
        self.assertRejects(payload, "空白だけの文字列を通した")

        payload = self.valid()
        payload["entries"][0] = "alpha"
        self.assertRejects(payload, "entry が文字列でも通した")

    def test_5_version_must_be_the_integer_one(self) -> None:
        for bad in (2, 0, "1", 1.0, True):
            payload = self.valid()
            payload["version"] = bad
            self.assertRejects(payload, f"version={bad!r} を通した")


class IdentifierTest(SyntheticCase):
    """6: id の書式と一意性。"""

    def test_6_bad_id_format_is_rejected(self) -> None:
        for bad in ("Alpha", "-alpha", "alpha_beta", "alpha/beta", "", "アルファ"):
            payload = self.valid()
            payload["entries"][0]["id"] = bad
            self.assertRejects(payload, f"id={bad!r} を通した")

    def test_6_duplicate_id_is_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][1]["id"] = payload["entries"][0]["id"]
        self.assertRejects(payload, "id の重複を通した")


class StatementTest(SyntheticCase):
    """7: 理由と代替検証の中身。"""

    def test_7_short_statement_is_rejected(self) -> None:
        for key in ("reason", "alternative_verification"):
            payload = self.valid()
            payload["entries"][0][key] = "短い理由"
            self.assertRejects(payload, f"{key} が 30 文字未満でも通した")

    def test_7_hollow_statement_is_rejected(self) -> None:
        for hollow in ("対象外", "なし", "-", "N/A", "TBD"):
            payload = self.valid()
            payload["entries"][0]["reason"] = hollow
            self.assertRejects(payload, f"無内容な理由 {hollow!r} を通した")


class PathPrefixTest(SyntheticCase):
    """8 / 9 / 10 / 11: 対象パスの制約。"""

    def test_8_empty_missing_or_outside_app_is_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = []
        self.assertRejects(payload, "path_prefixes が空でも通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/NoSuchDirectory"]
        self.assertRejects(payload, "不在の対象パスを通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["tests/Feature/Alpha"]
        self.assertRejects(payload, "app/ の外の対象パスを通した")

    def test_9_symlinks_and_missing_paths_are_rejected(self) -> None:
        outside = Path(self.tmp.name).parent / "outside-target"
        outside.mkdir(exist_ok=True)
        self.addCleanup(outside.rmdir)

        (self.repo.root / "app/OutsideLink").symlink_to(outside, target_is_directory=True)
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/OutsideLink"]
        self.assertRejects(payload, "repo の外を指す symlink を通した")

        (self.repo.root / "app/InsideLink").symlink_to(
            self.repo.root / "app/Beta", target_is_directory=True
        )
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/InsideLink"]
        self.assertRejects(payload, "repo の内を指す symlink を通した")

        (self.repo.root / "app/LinkedParent").symlink_to(
            self.repo.root / "app/Http", target_is_directory=True
        )
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/LinkedParent/Controllers/Gamma"]
        self.assertRejects(payload, "親ディレクトリが symlink の対象パスを通した")

    def test_10_containment_and_duplicates_across_entries_are_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][1]["path_prefixes"] = ["app/Alpha/Deeper"]
        (self.repo.root / "app/Alpha/Deeper").mkdir()
        self.assertRejects(payload, "entry を跨いだ包含関係を通した")

        payload = self.valid()
        payload["entries"][1]["path_prefixes"] = ["app/Alpha"]
        self.assertRejects(payload, "entry を跨いだ完全重複を通した")

        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/Alpha", "app/Alpha"]
        self.assertRejects(payload, "entry 内の完全重複を通した")

    def test_11_trunk_prefixes_are_rejected(self) -> None:
        for trunk in ("app", "app/Http", "app/Http/Controllers"):
            payload = self.valid()
            payload["entries"][0]["path_prefixes"] = [trunk]
            self.assertRejects(payload, f"幹 {trunk} を通した")


class VerificationRefsTest(SyntheticCase):
    """12 / 13: 代替検証の参照。"""

    def test_12_empty_missing_or_duplicated_refs_are_rejected(self) -> None:
        payload = self.valid()
        payload["entries"][0]["verification_refs"] = []
        self.assertRejects(payload, "verification_refs が空でも通した")

        payload = self.valid()
        payload["entries"][0]["verification_refs"] = ["tests/Feature/NoSuchTest.php"]
        self.assertRejects(payload, "不在の代替検証を通した")

        payload = self.valid()
        payload["entries"][1]["verification_refs"] = ["tests/Feature/Alpha"]
        self.assertRejects(payload, "宣言内での重複を通した")

    def test_13_self_referencing_refs_are_rejected(self) -> None:
        for circular in (DECLARATION_REL_PATH, AUDIT_DOC_REL_PATH, "app/Beta"):
            payload = self.valid()
            payload["entries"][0]["verification_refs"] = [circular]
            self.assertRejects(payload, f"循環参照 {circular} を通した")

    def test_13_ancestor_of_self_reference_is_rejected(self) -> None:
        # 子方向だけを見ると、対象外の面や宣言自身を内包する**祖先**を書いてすり抜けられる。
        for ancestor in ("app", ".claude/skills/app-bug-hunt/coverage", ".claude"):
            payload = self.valid()
            payload["entries"][0]["verification_refs"] = [ancestor]
            self.assertRejects(payload, f"祖先による自己言及 {ancestor} を通した")


class NormalizeTest(unittest.TestCase):
    """15: 層 1 (字句の正規形) と covers のセグメント境界。"""

    def test_15_non_canonical_paths_are_rejected(self) -> None:
        for bad in (
            "/app/Filament",
            "app/../../etc",
            "app/./Filament",
            "app//Filament",
            "app/Filament/",
            "app\\Filament",
            "..",
            ".",
            "",
            "   ",
        ):
            with self.assertRaises(DeclarationError, msg=f"{bad!r} を通した"):
                normalize(bad)

    def test_15_normalize_returns_segments(self) -> None:
        self.assertEqual(normalize("app/Http/Controllers/Api"), ("app", "Http", "Controllers", "Api"))

    def test_15_covers_uses_segment_boundary(self) -> None:
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        matched = covers(declaration, "app/Filament/Resources/Foo.php")
        self.assertIsNotNone(matched)
        self.assertIsNone(covers(declaration, "app/Filamentary/Foo.php"))
        self.assertIsNone(covers(declaration, "app/Services/Manual/ScenarioService.php"))

    def test_15_covers_rejects_non_canonical_argument(self) -> None:
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        with self.assertRaises(DeclarationError):
            covers(declaration, "app/../app/Filament")


class InputFailureTest(SyntheticCase):
    """16: 入力障害が DeclarationError へ収束すること。"""

    def test_16_missing_file(self) -> None:
        with self.assertRaises(DeclarationError):
            load(self.repo.root / "no-such-file.json", self.repo.root)

    def test_16_invalid_utf8(self) -> None:
        target = self.repo.root / "broken.json"
        target.write_bytes(b"\xff\xfe{ invalid }")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_broken_json(self) -> None:
        target = self.repo.root / "broken2.json"
        target.write_text("{ this is not json", encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_deeply_nested_json(self) -> None:
        target = self.repo.root / "deep.json"
        target.write_text("[" * 200000, encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

    def test_16_duplicate_json_keys_are_rejected(self) -> None:
        # json.loads は重複キーを黙って後勝ちで畳む。レビューで見えている値と
        # 実際に採用される値がずれるので拒否する。
        valid = json.dumps(self.valid(), ensure_ascii=False)
        top_level = valid[:-1] + ', "version": 1}'
        target = self.repo.root / "dup-top.json"
        target.write_text(top_level, encoding="utf-8")
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)

        entry = json.dumps(self.valid()["entries"][0], ensure_ascii=False)
        duplicated = entry[:-1] + ', "id": "gamma"}'
        payload = json.dumps(self.valid(), ensure_ascii=False)
        target = self.repo.root / "dup-entry.json"
        target.write_text(
            payload.replace(entry, duplicated, 1),
            encoding="utf-8",
        )
        with self.assertRaises(DeclarationError):
            load(target, self.repo.root)


class CliTest(SyntheticCase):
    """1b / 18 / 19 / 20 / 21 / 22: CLI の契約 (実プロセス起動)。"""

    def _run(self, args: list[str]) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(MODULE_PATH), *args],
            capture_output=True,
            text=True,
            cwd=str(MODULE_PATH.parent),
        )

    def test_1b_runs_with_default_paths(self) -> None:
        proc = self._run([])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        self.assertTrue(proc.stdout.strip())

    def test_18_emit_json_matches_normalized_data(self) -> None:
        proc = self._run(["--emit", "json"])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        payload = json.loads(proc.stdout)
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        self.assertEqual(payload["version"], declaration.version)
        self.assertEqual(payload["note"], declaration.note)
        self.assertEqual(
            [e["id"] for e in payload["entries"]],
            [e.id for e in declaration.entries],
        )
        self.assertEqual(
            [tuple(e["path_prefixes"]) for e in payload["entries"]],
            [e.path_prefixes for e in declaration.entries],
        )

    def test_19_emit_markdown_contains_every_entry(self) -> None:
        proc = self._run(["--emit", "markdown"])
        self.assertEqual(proc.returncode, 0, proc.stderr)
        declaration = load(DEFAULT_DECLARATION, DEFAULT_REPO_ROOT)
        for entry in declaration.entries:
            for literal in (
                entry.title,
                entry.reason,
                entry.alternative_verification,
                *entry.path_prefixes,
            ):
                self.assertIn(literal, proc.stdout, f"markdown に {literal} が現れない")

    def test_19_emit_markdown_keeps_column_count(self) -> None:
        payload = self.valid()
        payload["entries"][0]["reason"] = _long("縦棒 | と\n改行を含む理由。")
        declaration_path = self.repo.write(payload)
        proc = self._run(
            [
                "--declaration",
                str(declaration_path),
                "--repo-root",
                str(self.repo.root),
                "--emit",
                "markdown",
            ]
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        rows = [line for line in proc.stdout.splitlines() if line.startswith("|")]
        self.assertGreaterEqual(len(rows), 4)
        widths = {len(_split_md_row(row)) for row in rows}
        self.assertEqual(len(widths), 1, f"列数が揃っていない: {widths}")

    def test_20_invalid_declaration_is_fail_closed(self) -> None:
        payload = self.valid()
        payload["entries"][0]["reason"] = "短い"
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertTrue(proc.stderr.strip())
        self.assertEqual(len(proc.stderr.strip().splitlines()), 1, proc.stderr)
        self.assertNotIn("Traceback", proc.stderr)

    def test_20_symlink_loop_is_fail_closed(self) -> None:
        # symlink の輪はパス解決を失敗させうる。どちらの経路を通っても
        # 終了コード 2 / stdout 空 / traceback なし へ収束する。
        (self.repo.root / "app/LoopA").symlink_to(self.repo.root / "app/LoopB")
        (self.repo.root / "app/LoopB").symlink_to(self.repo.root / "app/LoopA")
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/LoopA"]
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertNotIn("Traceback", proc.stderr)

    def test_20_unicode_line_separator_keeps_single_line_stderr(self) -> None:
        # 値に混ぜられた行区切り 1 文字で「stderr は 1 行」の契約を壊されないこと。
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/Alpha\u2028Missing"]
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertEqual(len(proc.stderr.strip().splitlines()), 1, proc.stderr)

    def test_21_unknown_emit_value_is_fail_closed(self) -> None:
        proc = self._run(["--emit", "no-such-format"])
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertNotIn("Traceback", proc.stderr)

    def test_22_wrong_repo_root_fails(self) -> None:
        proc = self._run(["--repo-root", str(self.repo.root)])
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")


if __name__ == "__main__":
    unittest.main()
```

## 修正後の `coverage/out-of-scope.json` の queued-job 項

```json
      "id": "queued-job",
      "title": "キューのワーカーが処理する実行単位",
      "reason": "ワーカーは serve とは別のプロセスで走るため、走行中に実際へ処理されても行の到達として記録されない (計測の到達点の外)。動いていないのではなく見えていない。",
      "alternative_verification": "各ジョブの業務挙動はドメイン側の Feature テスト (合成の一連の流れ / テイクの実体削除 / 自動チャージ) が検査し、待ち時間の扱いと重複実行の全数目録は tests/Feature/Queue と tests/Architecture/JobExecutionDedupInventoryTest.php が検査する。",
      "verification_refs": [
        "tests/Feature/Manual/RenderPipelineTest.php",
        "tests/Feature/Capture/DeleteTakeObjectsJobTest.php",
        "tests/Feature/Billing/AutoRechargeTriggerTest.php",
        "tests/Feature/Queue",
        "tests/Architecture/JobExecutionDedupInventoryTest.php"
      ],
      "path_prefixes": ["app/Jobs"]
    },
```

## 修正後の middleware docblock (コメントのみ)

```php
/**
 * コード到達カバレッジ (bug-hunt): app/ の実行された行/未到達行を bug-hunt 走行中のみ収集する観測器。
 *
 * 設計の honest 前提: 開発コンテナ (docker/Dockerfile) では pcov を使えるが、収集を有効にするのは
 * bug-hunt が serve を起動するときだけである。CI と本番でコード到達の収集を有効にする構成は
 * 本リポジトリに存在せず (CI の workflow に pcov の導入記述は無く、デプロイ定義そのものが無い)、
 * リポジトリの外にある本番構成がどうなっているかは分からない。よって拡張の有無に関わらず、
 * 設定 config('bughunt.pcov.enabled') (値の出所は env の BUGHUNT_PCOV) と
 * function_exists('\pcov\start') の **二重 guard** は必要であり、
 * どちらかが偽なら本 middleware は完全 no-op で安全である (handle は $next をそのまま返すだけ)。
 *
 * 役割分担:
 *  - handle:    per-request で pcov を初期化 (clear → start)。gate 内のみ。
 *  - terminate: pcov\collect → app/ 配下に限定 → covered/all 行集合を JSONL で追記。
 *               観測器が機能を壊さないよう全体を try/catch し、失敗は Log::warning のみ。
 *
 * 出力 (C4 merge_pcov.py が consume する契約・JSONL 追記、shard ごとに 1 ファイル):
 *   storage/bughunt-coverage/{run}-{shard}.json に 1 行 1 file:
 *     {"file":"app/Http/Controllers/...","covered":[12,13],"all":[12,13,14]}
 *   追記なので C4 が同一ファイルを union merge する。
 *
 * 主出力は uncovered (未到達) であり、covered%/line% は副 (gaming 防止)。本 middleware は
 * 生の covered/all を吐くだけで % は計算しない (集計は C4 に委ねる)。
 */
final class BughuntCoverageMiddleware
{
    /**
     * env + function_exists の二重 guard。どちらか偽なら handle/terminate は完全 no-op。
     * 拡張が読み込まれていない実行環境では function_exists 側が常に false を返す。
     */
    public static function enabled(): bool
    {
        return (bool) config('bughunt.pcov.enabled', false)
```

## 再検証の結果

- `vendor/bin/pint --test` = passed
- `composer phpstan` = No errors
- coverage の `python3 -m unittest` (5 モジュール) = 144 tests OK
- 負の対照を実測: 子方向だけの重なり判定へ戻すと `["app"]` が通り、object_pairs_hook を外すと重複キーが通る (どちらも新テストが検出する)

上記対応で全体判定を見直してほしい。残る指摘があれば [Critical] / [Warning] / [Suggestion] で分類し、最後に APPROVED / CHANGES_REQUESTED を明示すること。
