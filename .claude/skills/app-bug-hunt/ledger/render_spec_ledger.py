#!/usr/bin/env python3
"""申し送り `spec-ledger.md` の生成器 (stdlib のみ)。

入力は 2 つである:

  - `ledger/adjudications.jsonl` — 裁定登録の一覧。各行の任意項目 `context` に
    「なぜそう確定したか」の経緯を書く (`title` / `spec_basis` / `narrative` /
    任意の `reopen_condition`。未知キーは拒否する)。
  - `ledger/spec-ledger-migration.json` — 手書き時代の申し送りが痩せずに移ったことの検査。

出力は `.claude/skills/app-bug-hunt/spec-ledger.md` である。**手で編集しない。**

**照合器 (`validate_findings.py`) との関係**: 照合器は `context` を読まない。
JSON として妥当なまま `context` の形だけが壊れている場合、抑制機構は止まらず、
止まるのは生成だけである。**JSONL の構文そのものを壊した場合は従来どおり
registry 全体が fail-closed で無効になる** (経緯も同じ 1 行に載っているため)。

**保証しないもの**: 本生成器も自己テストも CI では走らない。生成物のドリフトは
人が `--check` か `python3 -m unittest` を走らせたときにだけ見つかる。
経緯の**内容が正しいこと**は機械が見ていない (形・全数性・痩せ・drift だけを見る)。

使い方:
    python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py            # 生成
    python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check    # drift 検出
"""

from __future__ import annotations

import argparse
import difflib
import hashlib
import json
import os
import pathlib
import re
import sys
import tempfile

HERE = pathlib.Path(__file__).resolve().parent
SKILL_DIR = HERE.parent
# .claude/skills/app-bug-hunt -> parents[0]=skills / parents[1]=.claude / parents[2]=リポジトリルート。
REPO_ROOT = SKILL_DIR.parents[2]
ADJUDICATIONS_PATH = os.path.join(HERE, "adjudications.jsonl")
MIGRATION_PATH = os.path.join(HERE, "spec-ledger-migration.json")
OUTPUT_PATH = os.path.join(SKILL_DIR, "spec-ledger.md")

REGENERATE_COMMAND = "python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py"

# 移行元 spec-ledger.md の実ブロック数 (`^#### ` のうちコードフェンス外のもの)。
# 2026-08-17 の実測で 1 件 (F-1-02)。もう 1 つの `####` は初回登録テンプレートの
# フェンス内なので移行対象ではない。件数を pin しないと「1 件に痩せても通る」検査になる。
EXPECTED_BLOCK_COUNT = 1

# 経緯の欄。閉じた集合で、未知キーは拒否する (deny-by-default)。
CONTEXT_KEYS = ("title", "spec_basis", "narrative", "reopen_condition")
CONTEXT_REQUIRED = ("title", "spec_basis", "narrative")

# 移行台帳の語彙。どちらも現時点で 1 語である。
# 参照実装 (aigenba) は run 修飾つき finding id を鍵にするが、aicue の source_finding_ids は
# run 修飾を持たず、F-3-01 が A-002 と A-003 の両方に現れるため一意に解決できない。
# 一意性を照合器が強制している識別子は adjudication_id だけなので、それを鍵にする。
MIGRATION_KEY_KINDS = ("adjudication_id",)
MIGRATION_TARGETS = ("adjudications",)
PROVENANCE_KEYS = (
    "source_file",
    "source_commit",
    "source_lines",
    "source_block_headings",
    "migrated_at",
    "machine_projection_sha256",
    "note",
)

# 生成に使う機械項目 (欠けたら RenderError。KeyError で落とさない)。
RENDERED_MACHINE_FIELDS = (
    "verdict",
    "scope",
    "source_finding_ids",
    "adjudicated_at_run",
    "adjudicated_at_commit",
    "review_after_days",
)

# 照合には**必ず fullmatch を使う**。`re.match` + `$` は Python では末尾の改行 1 個の
# 直前にも一致するため、`"A-001\n"` のような値を通してしまう
# (この id は機械マーカーと見出しへそのまま出るので、掲載の完全性を壊す経路になる)。
_ADJ_ID_RE = re.compile(r"A-[0-9]{3,}")
_SHA256_RE = re.compile(r"[0-9a-f]{64}")
ENTRY_MARKER_PREFIX = "<!-- entry:"
NO_CONTEXT_MARK = "**経緯は未記入**"

# 識別子を構成する文字。台帳が実際に使う識別子の文字集合に揃える
# (finding id `F-1-02` / TODO id `T095` / `feedback-probe.js`)。
# `-` と `.` を外すと `F-1-02` が `F-1-02-extra` の一部にも当たる。
# 日本語は含めない — 「T095 の実装フェーズ」のように直後へ日本語が続くのは正当な出現である。
_IDENT_CHARS = frozenset(
    "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_.-"
)


class RenderError(Exception):
    """生成できない入力に対する例外。生成物には 1 バイトも書かずに落ちる。"""


# ---------------------------------------------------------------------
# 小道具
# ---------------------------------------------------------------------
def sha256_of_bytes(data: bytes) -> str:
    """バイト列の sha256 (hex)。

    比較は**必ずバイト列で行う**。テキストとして読み直すと universal newline 変換で
    CRLF が LF に畳まれ、改行だけ変えた手編集を「一致」と誤判定するため。
    """
    return hashlib.sha256(data).hexdigest()


def canonical_machine_projection(adjudication: dict) -> str:
    """登録から context を除いた「機械項目だけの射影」の sha256 (hex)。

    正規化方式をここ 1 か所に固定する。生成器・テスト・移行台帳の pin は
    すべてこの関数の戻り値で突き合わせる (同じ式を 2 か所に書くと必ず食い違う)。
    """
    projection = {k: v for k, v in adjudication.items() if k != "context"}
    blob = json.dumps(
        projection, sort_keys=True, ensure_ascii=False, separators=(",", ":")
    ).encode("utf-8")
    return hashlib.sha256(blob).hexdigest()


def fragment_present(fragment: str, text: str) -> bool:
    """断片が識別子の境界で現れるか。

    無境界の部分文字列一致だと、`T095` を要求しているのに本文へ `T0950` しか残っていない
    場合でも通ってしまう (短い参照が長い別参照へ誤って当たる)。
    断片の端が識別子文字のときだけ、その側に識別子文字が続かないことを要求する。
    """
    if not fragment:
        return False
    guard_left = fragment[0] in _IDENT_CHARS
    guard_right = fragment[-1] in _IDENT_CHARS
    i = text.find(fragment)
    while i >= 0:
        j = i + len(fragment)
        left_ok = not guard_left or i == 0 or text[i - 1] not in _IDENT_CHARS
        right_ok = not guard_right or j >= len(text) or text[j] not in _IDENT_CHARS
        if left_ok and right_ok:
            return True
        i = text.find(fragment, i + 1)
    return False


def _no_duplicate_keys(pairs):
    """重複キーを拒否する。json.loads の既定は後勝ちで、静かに片方を捨てるため。"""
    seen: dict = {}
    for key, value in pairs:
        if key in seen:
            raise ValueError(f"duplicate key: {key!r}")
        seen[key] = value
    return seen


def _reject_non_finite(token):
    raise ValueError(f"non-finite number is not allowed: {token}")


def _loads(text: str):
    return json.loads(
        text, object_pairs_hook=_no_duplicate_keys, parse_constant=_reject_non_finite
    )


def _is_filled_str(value) -> bool:
    """非空文字列 (空白だけの値は非空と認めない)。"""
    return isinstance(value, str) and value.strip() != ""


def _is_positive_int(value) -> bool:
    """正の整数 (bool は int の派生なので明示的に拒否する)。"""
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


def _check_inline_text(value, where: str) -> str:
    """出力の 1 行に出る文字列の検査 (非空 / マーカー混入 / CR・LF)。

    改行を許すと、行頭から項目境界のマーカーを偽装できてしまう。
    """
    if not _is_filled_str(value):
        raise RenderError(f"{where}: 非空文字列である必要がある: {value!r}")
    if ENTRY_MARKER_PREFIX in value:
        raise RenderError(f"{where}: 機械マーカーの接頭辞を含んではならない")
    if "\n" in value or "\r" in value:
        raise RenderError(f"{where}: 改行を含んではならない")
    return value


def _check_block_text(value, where: str) -> str:
    """複数行を許す本文の検査 (非空 / マーカー混入)。"""
    if not _is_filled_str(value):
        raise RenderError(f"{where}: 非空文字列である必要がある: {value!r}")
    if ENTRY_MARKER_PREFIX in value:
        raise RenderError(f"{where}: 機械マーカーの接頭辞を含んではならない")
    return value


# ---------------------------------------------------------------------
# 入力の読み込みと検証
# ---------------------------------------------------------------------
def _validate_context(context, where: str) -> None:
    """経緯の欄を検証する (閉じた集合。deny-by-default)。"""
    if not isinstance(context, dict):
        raise RenderError(f"{where}: context は object である必要がある")
    for key in context:
        if key not in CONTEXT_KEYS:
            raise RenderError(f"{where}: context に未知のキー: {key!r}")
    for key in CONTEXT_REQUIRED:
        if key not in context:
            raise RenderError(f"{where}: context.{key} が無い")
    _check_inline_text(context["title"], f"{where}: context.title")
    _check_block_text(context["narrative"], f"{where}: context.narrative")
    basis = context["spec_basis"]
    if not isinstance(basis, list) or not basis:
        raise RenderError(f"{where}: context.spec_basis は非空の配列である必要がある")
    for index, reference in enumerate(basis):
        _check_inline_text(reference, f"{where}: context.spec_basis[{index}]")
    if "reopen_condition" in context:
        _check_inline_text(
            context["reopen_condition"], f"{where}: context.reopen_condition"
        )


def _validate_machine_fields(record: dict, where: str) -> None:
    """生成で参照する機械項目だけを見る (照合器の全検証は重複させない)。"""
    for field in RENDERED_MACHINE_FIELDS:
        if field not in record:
            raise RenderError(f"{where}: 機械項目 {field} が無い")
    _check_inline_text(record["verdict"], f"{where}: verdict")
    scope = record["scope"]
    if not isinstance(scope, dict):
        raise RenderError(f"{where}: scope は object である必要がある")
    for key in ("scope_kind", "scope_value"):
        if key not in scope:
            raise RenderError(f"{where}: scope.{key} が無い")
        _check_inline_text(scope[key], f"{where}: scope.{key}")
    finding_ids = record["source_finding_ids"]
    if not isinstance(finding_ids, list) or not finding_ids:
        raise RenderError(f"{where}: source_finding_ids は非空の配列である必要がある")
    for index, finding_id in enumerate(finding_ids):
        _check_inline_text(finding_id, f"{where}: source_finding_ids[{index}]")
    _check_inline_text(record["adjudicated_at_run"], f"{where}: adjudicated_at_run")
    _check_inline_text(
        record["adjudicated_at_commit"], f"{where}: adjudicated_at_commit"
    )
    if not _is_positive_int(record["review_after_days"]):
        raise RenderError(
            f"{where}: review_after_days は正の整数である必要がある: "
            f"{record['review_after_days']!r}"
        )
    if "supersedes" in record:
        _check_inline_text(record["supersedes"], f"{where}: supersedes")


def _check_supersede_graph(records: list) -> None:
    """差し替え関係の 4 点 (書式 / 実在 / 自己参照 / 循環) を検証する。

    照合器が registry を無効化しているのに生成物だけが誤った有効性を表示する、
    という食い違いを避けるため、生成器も同じ 4 点を見る。
    """
    known = {record["adjudication_id"] for record in records}
    links = {}
    for record in records:
        target = record.get("supersedes")
        if target is None:
            continue
        adjudication_id = record["adjudication_id"]
        if not _ADJ_ID_RE.fullmatch(target):
            raise RenderError(f"{adjudication_id}: supersedes の書式が不正: {target!r}")
        if target not in known:
            raise RenderError(f"{adjudication_id}: supersedes の指す先が無い: {target}")
        if target == adjudication_id:
            raise RenderError(f"{adjudication_id}: supersedes が自己参照している")
        links[adjudication_id] = target
    for start in links:
        seen = set()
        current = start
        while current in links:
            current = links[current]
            if current == start or current in seen:
                raise RenderError(f"supersedes が循環している: {start}")
            seen.add(current)


def load_adjudications(path: str) -> list:
    """裁定登録を読み、生成に必要な範囲で検証する。"""
    if not os.path.isfile(path):
        raise RenderError(f"裁定登録が無い: {path}")
    records: list = []
    with open(path, encoding="utf-8") as handle:
        for lineno, raw in enumerate(handle, 1):
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            where = f"{os.path.basename(path)}:{lineno}"
            try:
                record = _loads(line)
            except ValueError as error:  # JSONDecodeError は ValueError の派生
                raise RenderError(f"{where}: JSON として読めない: {error}") from error
            if not isinstance(record, dict):
                raise RenderError(f"{where}: 1 行は object である必要がある")
            records.append(record)
    if not records:
        raise RenderError(f"裁定登録が 1 件も無い: {path}")

    seen: set = set()
    for index, record in enumerate(records, 1):
        where = f"{os.path.basename(path)} の {index} 件目"
        adjudication_id = record.get("adjudication_id")
        if not isinstance(adjudication_id, str) or not _ADJ_ID_RE.fullmatch(adjudication_id):
            raise RenderError(f"{where}: adjudication_id の書式が不正: {adjudication_id!r}")
        if adjudication_id in seen:
            raise RenderError(f"adjudication_id が重複している: {adjudication_id}")
        seen.add(adjudication_id)
        _validate_machine_fields(record, adjudication_id)
        if "context" in record:
            _validate_context(record["context"], adjudication_id)
    _check_supersede_graph(records)
    return records


def _check_closed_vocabulary(value, vocabulary, where: str) -> None:
    if value not in vocabulary:
        raise RenderError(f"{where}: 語彙外の値: {value!r} (許すのは {list(vocabulary)})")


def load_migration(path: str) -> dict:
    """移行台帳を読み、閉じた語彙と件数の一致を検証する。

    **検査の順序は意図的である**。件数の pin (`EXPECTED_BLOCK_COUNT`) を先に見ると、
    件数を動かす形の壊れ方 (鍵の重複・見出しの重複) が pin の失敗に隠れて
    「その検査を消しても赤にならない」状態になる。したがって
    「1 件ずつ見れば分かること」→「件数の突き合わせ」→「pin」の順に並べる。
    """
    if not os.path.isfile(path):
        raise RenderError(f"移行台帳が無い: {path}")
    try:
        migration = _loads(pathlib.Path(path).read_text(encoding="utf-8"))
    except ValueError as error:
        raise RenderError(f"移行台帳が JSON として読めない: {error}") from error
    if not isinstance(migration, dict):
        raise RenderError("移行台帳は単一の object である必要がある")

    if not _is_positive_int(migration.get("version")):
        raise RenderError(f"移行台帳 version は正の整数: {migration.get('version')!r}")
    block_count = migration.get("block_count")
    if not _is_positive_int(block_count):
        raise RenderError(f"移行台帳 block_count は正の整数: {block_count!r}")

    provenance = migration.get("provenance")
    if not isinstance(provenance, dict):
        raise RenderError("移行台帳 provenance は object である必要がある")
    for key in PROVENANCE_KEYS:
        if key not in provenance:
            raise RenderError(f"移行台帳 provenance.{key} が無い")
    for key in ("source_file", "source_commit", "source_lines", "migrated_at", "note"):
        if not _is_filled_str(provenance[key]):
            raise RenderError(f"移行台帳 provenance.{key} は非空文字列である必要がある")
    headings = provenance["source_block_headings"]
    if not isinstance(headings, list) or not all(_is_filled_str(h) for h in headings):
        raise RenderError("移行台帳 provenance.source_block_headings は非空文字列の配列")
    if len(set(headings)) != len(headings):
        raise RenderError("移行台帳 provenance.source_block_headings に重複がある")
    pins = provenance["machine_projection_sha256"]
    if not isinstance(pins, dict) or not pins:
        raise RenderError("移行台帳 provenance.machine_projection_sha256 は非空の object")
    for adjudication_id, digest in pins.items():
        if not _ADJ_ID_RE.fullmatch(str(adjudication_id)):
            raise RenderError(f"pin の鍵が adjudication_id ではない: {adjudication_id!r}")
        if not isinstance(digest, str) or not _SHA256_RE.fullmatch(digest):
            raise RenderError(f"pin の値が 64 桁 hex ではない: {adjudication_id}")

    entries = migration.get("entries")
    if not isinstance(entries, list):
        raise RenderError("移行台帳 entries は配列である必要がある")
    keys: set = set()
    for entry in entries:
        if not isinstance(entry, dict):
            raise RenderError("移行台帳 entries の要素は object である必要がある")
        key = entry.get("key")
        if not _is_filled_str(key):
            raise RenderError(f"移行台帳 entries の key が不正: {key!r}")
        if key in keys:
            raise RenderError(f"移行台帳 entries の key が重複している: {key}")
        keys.add(key)
        _check_closed_vocabulary(entry.get("key_kind"), MIGRATION_KEY_KINDS, f"{key} の key_kind")
        _check_closed_vocabulary(entry.get("target"), MIGRATION_TARGETS, f"{key} の target")
        minimums = entry.get("field_minimums")
        if not isinstance(minimums, dict) or not minimums:
            raise RenderError(f"{key}: field_minimums は非空の object である必要がある")
        for field, minimum in minimums.items():
            _check_closed_vocabulary(field, CONTEXT_KEYS, f"{key} の field_minimums の欄名")
            if not _is_positive_int(minimum):
                raise RenderError(f"{key}: field_minimums.{field} は正の整数: {minimum!r}")
        fragments = entry.get("required_fragments")
        if not isinstance(fragments, list) or not fragments:
            raise RenderError(f"{key}: required_fragments は非空の配列である必要がある")
        pairs: set = set()
        for fragment in fragments:
            if not isinstance(fragment, dict) or set(fragment) != {"field", "value"}:
                raise RenderError(f"{key}: required_fragments の要素は field / value の 2 欄")
            _check_closed_vocabulary(
                fragment["field"], CONTEXT_KEYS, f"{key} の required_fragments の field"
            )
            if not _is_filled_str(fragment["value"]):
                raise RenderError(f"{key}: required_fragments の value が空")
            pair = (fragment["field"], fragment["value"])
            if pair in pairs:
                raise RenderError(f"{key}: required_fragments が重複している: {pair}")
            pairs.add(pair)

    # 件数の突き合わせ (三点一致)。1 件ずつ見れば分かる壊れ方をすべて弾いた後に置く。
    if len(entries) != block_count:
        raise RenderError(
            f"entries の件数が block_count と食い違う: {len(entries)} != {block_count}"
        )
    if len(headings) != block_count:
        raise RenderError(
            f"移行元見出しの件数が block_count と食い違う: {len(headings)} != {block_count}"
        )
    if block_count != EXPECTED_BLOCK_COUNT:
        raise RenderError(
            f"移行元ブロック数の pin と食い違う: {block_count} != {EXPECTED_BLOCK_COUNT}"
        )
    return migration


def _context_field_text(context: dict, field: str, where: str) -> str:
    if field not in context:
        raise RenderError(f"{where}: 移行台帳が要求する欄 {field} が context に無い")
    value = context[field]
    if isinstance(value, list):
        return "\n".join(value)
    return value


def check_migration(migration: dict, records: list) -> None:
    """移行元の内容が痩せずに登録の経緯へ移っていることを検査する。"""
    by_id = {record["adjudication_id"]: record for record in records}
    for entry in migration["entries"]:
        key = entry["key"]
        record = by_id.get(key)
        if record is None:
            raise RenderError(f"移行台帳の鍵が解決できない: {key}")
        context = record.get("context")
        if not context:
            raise RenderError(f"移行台帳の鍵 {key} の登録に context が無い")
        for field, minimum in entry["field_minimums"].items():
            text = _context_field_text(context, field, key)
            if len(text) < minimum:
                raise RenderError(
                    f"{key}: context.{field} が痩せている ({len(text)} 文字 < 下限 {minimum})"
                )
        for fragment in entry["required_fragments"]:
            field, value = fragment["field"], fragment["value"]
            text = _context_field_text(context, field, key)
            if not fragment_present(value, text):
                raise RenderError(f"{key}: context.{field} に必須の断片が無い: {value!r}")

    pins = migration["provenance"]["machine_projection_sha256"]
    for adjudication_id, digest in pins.items():
        record = by_id.get(adjudication_id)
        if record is None:
            raise RenderError(f"pin の指す登録が無い: {adjudication_id}")
        actual = canonical_machine_projection(record)
        if actual != digest:
            raise RenderError(
                f"{adjudication_id}: 機械項目が移行時点から変わっている "
                f"(append-only + supersede で扱うこと)"
            )


# ---------------------------------------------------------------------
# 出力の組み立て
# ---------------------------------------------------------------------
def active_ids(records: list) -> set:
    """有効な登録の id 集合。**照合器と同じ規則** (未 supersede のものだけ)。"""
    superseded = {r["supersedes"] for r in records if r.get("supersedes")}
    return {
        r["adjudication_id"] for r in records if r["adjudication_id"] not in superseded
    }


def _sort_key(adjudication_id: str) -> int:
    return int(adjudication_id.split("-", 1)[1])


HEADER = f"""<!-- generated by .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->
<!-- DO NOT EDIT: 入力は ledger/adjudications.jsonl (登録一覧と経緯) と
     ledger/spec-ledger-migration.json (移行検査)。
     再生成: {REGENERATE_COMMAND} -->

# bug-hunt 仕様台帳 (spec-ledger) — 裁定済み事象の可視化

**このファイルは生成物である。手で編集しない。**
経緯は `ledger/adjudications.jsonl` の `context` に書き、上のコマンドで再生成する。
手編集と再生成忘れは `--check` が検出する。**ただし CI では走らないので、
再生成を忘れたまま古い内容が残っている状態は起こり得る** (下の「この文書の限界」)。
運用手順の正本は `ledger/README.md` であり、本ファイルは「登録の可視化」だけを担う。

## 使い方 (bug-hunt 実行者へ)

- finding を起票する前に本台帳を検索すること。**有効性が `active` の項目に載っている事象は
  再起票しない** (「既知」と一行記録して次へ)。
- **`superseded` の項目は履歴である。判断の正本は後継の登録**であり、
  `superseded` を根拠に再起票を止めてはならない。
  照合器 (`validate_findings.py --annotate`) も `active` の登録だけを照合に使う。
- 同一事象が再発したと感じたら、**仕様根拠**を実コードで確認する。コードが台帳と乖離していれば
  regression の可能性があるので、その差分を根拠に新規 finding として起票してよい。

## この文書の限界

- 内容が最新である保証は無い。`--check` を人が走らせたときにだけ drift が分かる。
- 経緯の**正しさ**は機械が見ていない (形・全数性・痩せ・drift だけを見る)。

---

## 登録一覧 (adjudications.jsonl の可視化)
"""

MIGRATION_SECTION_HEAD = """---

## 移行の全数性 (機械可読)

移行元 spec-ledger.md の全ブロックが上のどこかへ移ったことを機械が突き合わせる索引。
1 行 1 鍵。人向けの本文中の言及と取り違えないため、完全一致で比べる。

<!-- migration-keys:begin -->
"""


def _render_entry(record: dict, *, active: set, superseded_by: dict) -> str:
    adjudication_id = record["adjudication_id"]
    context = record.get("context")
    title = context["title"] if context else "(経緯は未記入)"
    lines = [f"{ENTRY_MARKER_PREFIX} {adjudication_id} -->", f"### {adjudication_id} — {title}", ""]
    if adjudication_id in active:
        lines.append("- 有効性: **active**")
    else:
        successors = " / ".join(sorted(superseded_by[adjudication_id], key=_sort_key))
        lines.append(
            f"- 有効性: **superseded** ({successors} に差し替えられた。判断の正本は後継)"
        )
    if record.get("supersedes"):
        lines.append(f"- 差し替え: {record['supersedes']} を差し替えた")
    lines.append("- 由来 finding: " + " / ".join(record["source_finding_ids"]))
    scope = record["scope"]
    lines.append(
        f"- 判定: {record['verdict']} / 対象面: "
        f"{scope['scope_kind']}={scope['scope_value']}"
    )
    lines.append(
        f"- 確定: run {record['adjudicated_at_run']} "
        f"(commit {record['adjudicated_at_commit']}) / "
        f"見直し期限: {record['review_after_days']} 日"
    )
    if context:
        lines.append("- 仕様根拠: " + " ; ".join(context["spec_basis"]))
        if "reopen_condition" in context:
            lines.append(f"- 再オープン条件: {context['reopen_condition']}")
        lines.extend(["", context["narrative"].rstrip("\n")])
    else:
        lines.append(
            f"- {NO_CONTEXT_MARK} (この登録には `context` が無い。書くときは "
            "`adjudications.jsonl` の当該行へ `context` を足して再生成する)"
        )
    return "\n".join(lines) + "\n"


def render(records: list, migration: dict) -> str:
    """検証済みの入力から生成物の全文を組み立てる (ファイルには触れない)。"""
    superseded_by: dict = {}
    for record in records:
        target = record.get("supersedes")
        if target:
            superseded_by.setdefault(target, []).append(record["adjudication_id"])
    active = active_ids(records)
    ordered = sorted(records, key=lambda r: _sort_key(r["adjudication_id"]))

    parts = [HEADER]
    for record in ordered:
        parts.append("\n" + _render_entry(record, active=active, superseded_by=superseded_by))
    parts.append("\n" + MIGRATION_SECTION_HEAD)
    for entry in migration["entries"]:
        parts.append(f"- key: {entry['key']}\n")
    parts.append("<!-- migration-keys:end -->\n")
    return "".join(parts)


def build(
    *,
    adjudications_path: str = ADJUDICATIONS_PATH,
    migration_path: str = MIGRATION_PATH,
) -> str:
    """入力を検証して生成物の全文を返す。失敗は RenderError で、出力には触れない。"""
    records = load_adjudications(adjudications_path)
    migration = load_migration(migration_path)
    check_migration(migration, records)
    return render(records, migration)


# ---------------------------------------------------------------------
# 書き出し (原子的)
# ---------------------------------------------------------------------
def write_atomically(text: str, path: str) -> None:
    """同一ディレクトリの一時ファイルへ書いてから置換する。

    保証する: 通常の失敗 (検証エラー・書き込みエラー・置換エラー) では
              既存ファイルが 1 バイトも変わらないこと。一時ファイルを残さないこと。
    保証しない: 電源断・ファイルシステム破損に対する耐性 (fsync していない)。
    """
    directory = os.path.dirname(os.path.abspath(path))  # 別 FS を跨がないため出力と同じ場所
    mode = os.stat(path).st_mode & 0o777 if os.path.exists(path) else 0o644
    fd, tmp = tempfile.mkstemp(dir=directory, prefix=".spec-ledger.", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(text)
        os.chmod(tmp, mode)  # mkstemp は 0600 を作るので、生成物の mode を明示する
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="spec-ledger.md を生成する")
    parser.add_argument("--check", action="store_true", help="生成結果と現物を比較する")
    parser.add_argument("--output", default=OUTPUT_PATH)
    parser.add_argument("--adjudications", default=ADJUDICATIONS_PATH)
    parser.add_argument("--migration", default=MIGRATION_PATH)
    args = parser.parse_args(argv)

    try:
        text = build(
            adjudications_path=args.adjudications, migration_path=args.migration
        )
    except RenderError as error:
        print(f"render error: {error}", file=sys.stderr)
        print(f"再生成: {REGENERATE_COMMAND}", file=sys.stderr)
        return 1

    if args.check:
        if not os.path.isfile(args.output):
            print(f"生成物が無い: {args.output}", file=sys.stderr)
            print(f"再生成: {REGENERATE_COMMAND}", file=sys.stderr)
            return 1
        # 比較はバイト列で行う (read_text() は CRLF を LF に畳むため、
        # 改行だけ変えた手編集を「一致」と誤判定する)。
        current_bytes = pathlib.Path(args.output).read_bytes()
        if current_bytes == text.encode("utf-8"):
            return 0
        current = current_bytes.decode("utf-8", errors="replace")
        diff = difflib.unified_diff(
            current.splitlines(keepends=True),
            text.splitlines(keepends=True),
            fromfile=f"{args.output} (現物)",
            tofile="(生成結果)",
        )
        for line in list(diff)[:200]:
            sys.stderr.write(line)
        print(f"\n生成物が古い (または手編集されている)。再生成: {REGENERATE_COMMAND}",
              file=sys.stderr)
        return 1

    write_atomically(text, args.output)
    print(f"wrote {args.output} ({len(text)} chars)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
