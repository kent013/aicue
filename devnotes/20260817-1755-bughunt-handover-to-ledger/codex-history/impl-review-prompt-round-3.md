# 実装レビュー Round 3 (T223 / bug-hunt 申し送りの生成物化)

Round 2 の指摘 (Critical 2 / Warning 2) にすべて対応した。反論・見送りはゼロ件である。

## 対応マトリクス

# 実装レビュー Round 2 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 2 / Warning 1 + 検証未提示 1)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Critical] `adjudication_id` の CR/LF 防御漏れ。`re.match` + `$` は Python では末尾の改行 1 個の直前にも一致するため `"A-001\n"` を受理し、機械マーカーと見出しへそのまま出て掲載の完全性を壊す。`supersedes` と移行 hash の鍵も同じ式を使っている | **対応する** | `_ADJ_ID_RE` / `_SHA256_RE` から行頭・行末アンカーを外し、**照合をすべて `fullmatch` に統一**した (`load_adjudications` の id / `_check_supersede_graph` の supersedes / `load_migration` の pin の鍵と値の 4 か所)。理由を定数の直上にコメントで残した。テスト側の `SPEC_BASIS_FORM_RE` も同じ理由で `fullmatch` に揃えた |
| 2 | [Critical] CR/LF 表駆動テストに `adjudication_id` が漏れている。少なくとも `"A-001\n"` / `"A-001\r"` の拒否ケースを足すこと | **対応する** | `test_identifier_with_trailing_newline_is_rejected` を新設 (id と supersedes × CR / LF の 4 ケース)。`test_bad_adjudication_id_form_is_rejected` にも `"A-001\n"` / `"A-001\r"` / `" A-001"` を足した。移行 hash の鍵と値の末尾改行も `test_provenance_shape_and_heading_count` のケースに追加した |
| 3 | [Warning] `SPEC_BASIS_EXTENSIONS` と正規表現が別々に手書きで、許可側の pin になっていない (式だけ広げても拒否例に無ければ全緑) | **対応する** | 正規表現を `SPEC_BASIS_EXTENSIONS` から組み立てる形に変え、**定数を唯一の正本**にした (長い順に並べて `jsonl` が `json` に食われないようにしてある) |
| 4 | [Warning] `pnpm test:packages` の結果が未提示 | **対応する** | 完了を待って実測した (Test Files 10 passed / Tests 106 passed)。これで AGENTS.md の検証コマンド一式が全 green である |

## 追加でこちらから行ったこと (Round 2 の指摘から派生した自己点検)

Round 2 の Critical 1 を修正した直後に**変異試験**を行ったところ、
`test_identifier_with_trailing_newline_is_rejected` が「id 検査を `match` に戻しても緑のまま」だった —
id を壊すと移行台帳の鍵が解決できなくなり、**別の理由の `RenderError`** が上がっていたためである。
同じ masking は機械項目の変更全般に起きる (機械項目を触ると `machine_projection_sha256` の pin も外れる)。

そこで**否定系テストの期待を「例外の型」から「失敗理由」へ引き上げた** —
主要な negative test を `assertRaisesRegex` にし、生成器側のエラーメッセージと 1:1 で突き合わせるようにした
(marker 混入 / 改行 / id 書式 / supersede の 4 点 / 機械項目の欠落 / context の形 / JSON の読み方 /
移行台帳の語彙・整数・痩せ・断片・解決不能・形)。

変異試験でこの点検を裏づけた (いずれも赤になることを確認し、その後に復元して全緑を再確認した):

| 変異 | 結果 |
|---|---|
| id の照合を `fullmatch` → `match` に戻す | 4 failures / 1 error |
| `_check_inline_text` から機械マーカーの検査を外す | 9 failures |
| 移行台帳の鍵の重複検査を外す | 1 failure |

反論・見送りはゼロ件である。


## 現時点の生成器と契約テストの差分 (main 起点。2 ファイル全文)

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
new file mode 100644
index 0000000..6a0da46
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
@@ -0,0 +1,679 @@
+#!/usr/bin/env python3
+"""申し送り `spec-ledger.md` の生成器 (stdlib のみ)。
+
+入力は 2 つである:
+
+  - `ledger/adjudications.jsonl` — 裁定登録の一覧。各行の任意項目 `context` に
+    「なぜそう確定したか」の経緯を書く (`title` / `spec_basis` / `narrative` /
+    任意の `reopen_condition`。未知キーは拒否する)。
+  - `ledger/spec-ledger-migration.json` — 手書き時代の申し送りが痩せずに移ったことの検査。
+
+出力は `.claude/skills/app-bug-hunt/spec-ledger.md` である。**手で編集しない。**
+
+**照合器 (`validate_findings.py`) との関係**: 照合器は `context` を読まない。
+JSON として妥当なまま `context` の形だけが壊れている場合、抑制機構は止まらず、
+止まるのは生成だけである。**JSONL の構文そのものを壊した場合は従来どおり
+registry 全体が fail-closed で無効になる** (経緯も同じ 1 行に載っているため)。
+
+**保証しないもの**: 本生成器も自己テストも CI では走らない。生成物のドリフトは
+人が `--check` か `python3 -m unittest` を走らせたときにだけ見つかる。
+経緯の**内容が正しいこと**は機械が見ていない (形・全数性・痩せ・drift だけを見る)。
+
+使い方:
+    python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py            # 生成
+    python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check    # drift 検出
+"""
+
+from __future__ import annotations
+
+import argparse
+import difflib
+import hashlib
+import json
+import os
+import pathlib
+import re
+import sys
+import tempfile
+
+HERE = pathlib.Path(__file__).resolve().parent
+SKILL_DIR = HERE.parent
+# .claude/skills/app-bug-hunt -> parents[0]=skills / parents[1]=.claude / parents[2]=リポジトリルート。
+REPO_ROOT = SKILL_DIR.parents[2]
+ADJUDICATIONS_PATH = os.path.join(HERE, "adjudications.jsonl")
+MIGRATION_PATH = os.path.join(HERE, "spec-ledger-migration.json")
+OUTPUT_PATH = os.path.join(SKILL_DIR, "spec-ledger.md")
+
+REGENERATE_COMMAND = "python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py"
+
+# 移行元 spec-ledger.md の実ブロック数 (`^#### ` のうちコードフェンス外のもの)。
+# 2026-08-17 の実測で 1 件 (F-1-02)。もう 1 つの `####` は初回登録テンプレートの
+# フェンス内なので移行対象ではない。件数を pin しないと「1 件に痩せても通る」検査になる。
+EXPECTED_BLOCK_COUNT = 1
+
+# 経緯の欄。閉じた集合で、未知キーは拒否する (deny-by-default)。
+CONTEXT_KEYS = ("title", "spec_basis", "narrative", "reopen_condition")
+CONTEXT_REQUIRED = ("title", "spec_basis", "narrative")
+
+# 移行台帳の語彙。どちらも現時点で 1 語である。
+# 参照実装 (aigenba) は run 修飾つき finding id を鍵にするが、aicue の source_finding_ids は
+# run 修飾を持たず、F-3-01 が A-002 と A-003 の両方に現れるため一意に解決できない。
+# 一意性を照合器が強制している識別子は adjudication_id だけなので、それを鍵にする。
+MIGRATION_KEY_KINDS = ("adjudication_id",)
+MIGRATION_TARGETS = ("adjudications",)
+PROVENANCE_KEYS = (
+    "source_file",
+    "source_commit",
+    "source_lines",
+    "source_block_headings",
+    "migrated_at",
+    "machine_projection_sha256",
+    "note",
+)
+
+# 生成に使う機械項目 (欠けたら RenderError。KeyError で落とさない)。
+RENDERED_MACHINE_FIELDS = (
+    "verdict",
+    "scope",
+    "source_finding_ids",
+    "adjudicated_at_run",
+    "adjudicated_at_commit",
+    "review_after_days",
+)
+
+# 照合には**必ず fullmatch を使う**。`re.match` + `$` は Python では末尾の改行 1 個の
+# 直前にも一致するため、`"A-001\n"` のような値を通してしまう
+# (この id は機械マーカーと見出しへそのまま出るので、掲載の完全性を壊す経路になる)。
+_ADJ_ID_RE = re.compile(r"A-[0-9]{3,}")
+_SHA256_RE = re.compile(r"[0-9a-f]{64}")
+ENTRY_MARKER_PREFIX = "<!-- entry:"
+NO_CONTEXT_MARK = "**経緯は未記入**"
+
+# 識別子を構成する文字。台帳が実際に使う識別子の文字集合に揃える
+# (finding id `F-1-02` / TODO id `T095` / `feedback-probe.js`)。
+# `-` と `.` を外すと `F-1-02` が `F-1-02-extra` の一部にも当たる。
+# 日本語は含めない — 「T095 の実装フェーズ」のように直後へ日本語が続くのは正当な出現である。
+_IDENT_CHARS = frozenset(
+    "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_.-"
+)
+
+
+class RenderError(Exception):
+    """生成できない入力に対する例外。生成物には 1 バイトも書かずに落ちる。"""
+
+
+# ---------------------------------------------------------------------
+# 小道具
+# ---------------------------------------------------------------------
+def sha256_of_bytes(data: bytes) -> str:
+    """バイト列の sha256 (hex)。
+
+    比較は**必ずバイト列で行う**。テキストとして読み直すと universal newline 変換で
+    CRLF が LF に畳まれ、改行だけ変えた手編集を「一致」と誤判定するため。
+    """
+    return hashlib.sha256(data).hexdigest()
+
+
+def canonical_machine_projection(adjudication: dict) -> str:
+    """登録から context を除いた「機械項目だけの射影」の sha256 (hex)。
+
+    正規化方式をここ 1 か所に固定する。生成器・テスト・移行台帳の pin は
+    すべてこの関数の戻り値で突き合わせる (同じ式を 2 か所に書くと必ず食い違う)。
+    """
+    projection = {k: v for k, v in adjudication.items() if k != "context"}
+    blob = json.dumps(
+        projection, sort_keys=True, ensure_ascii=False, separators=(",", ":")
+    ).encode("utf-8")
+    return hashlib.sha256(blob).hexdigest()
+
+
+def fragment_present(fragment: str, text: str) -> bool:
+    """断片が識別子の境界で現れるか。
+
+    無境界の部分文字列一致だと、`T095` を要求しているのに本文へ `T0950` しか残っていない
+    場合でも通ってしまう (短い参照が長い別参照へ誤って当たる)。
+    断片の端が識別子文字のときだけ、その側に識別子文字が続かないことを要求する。
+    """
+    if not fragment:
+        return False
+    guard_left = fragment[0] in _IDENT_CHARS
+    guard_right = fragment[-1] in _IDENT_CHARS
+    i = text.find(fragment)
+    while i >= 0:
+        j = i + len(fragment)
+        left_ok = not guard_left or i == 0 or text[i - 1] not in _IDENT_CHARS
+        right_ok = not guard_right or j >= len(text) or text[j] not in _IDENT_CHARS
+        if left_ok and right_ok:
+            return True
+        i = text.find(fragment, i + 1)
+    return False
+
+
+def _no_duplicate_keys(pairs):
+    """重複キーを拒否する。json.loads の既定は後勝ちで、静かに片方を捨てるため。"""
+    seen: dict = {}
+    for key, value in pairs:
+        if key in seen:
+            raise ValueError(f"duplicate key: {key!r}")
+        seen[key] = value
+    return seen
+
+
+def _reject_non_finite(token):
+    raise ValueError(f"non-finite number is not allowed: {token}")
+
+
+def _loads(text: str):
+    return json.loads(
+        text, object_pairs_hook=_no_duplicate_keys, parse_constant=_reject_non_finite
+    )
+
+
+def _is_filled_str(value) -> bool:
+    """非空文字列 (空白だけの値は非空と認めない)。"""
+    return isinstance(value, str) and value.strip() != ""
+
+
+def _is_positive_int(value) -> bool:
+    """正の整数 (bool は int の派生なので明示的に拒否する)。"""
+    return isinstance(value, int) and not isinstance(value, bool) and value > 0
+
+
+def _check_inline_text(value, where: str) -> str:
+    """出力の 1 行に出る文字列の検査 (非空 / マーカー混入 / CR・LF)。
+
+    改行を許すと、行頭から項目境界のマーカーを偽装できてしまう。
+    """
+    if not _is_filled_str(value):
+        raise RenderError(f"{where}: 非空文字列である必要がある: {value!r}")
+    if ENTRY_MARKER_PREFIX in value:
+        raise RenderError(f"{where}: 機械マーカーの接頭辞を含んではならない")
+    if "\n" in value or "\r" in value:
+        raise RenderError(f"{where}: 改行を含んではならない")
+    return value
+
+
+def _check_block_text(value, where: str) -> str:
+    """複数行を許す本文の検査 (非空 / マーカー混入)。"""
+    if not _is_filled_str(value):
+        raise RenderError(f"{where}: 非空文字列である必要がある: {value!r}")
+    if ENTRY_MARKER_PREFIX in value:
+        raise RenderError(f"{where}: 機械マーカーの接頭辞を含んではならない")
+    return value
+
+
+# ---------------------------------------------------------------------
+# 入力の読み込みと検証
+# ---------------------------------------------------------------------
+def _validate_context(context, where: str) -> None:
+    """経緯の欄を検証する (閉じた集合。deny-by-default)。"""
+    if not isinstance(context, dict):
+        raise RenderError(f"{where}: context は object である必要がある")
+    for key in context:
+        if key not in CONTEXT_KEYS:
+            raise RenderError(f"{where}: context に未知のキー: {key!r}")
+    for key in CONTEXT_REQUIRED:
+        if key not in context:
+            raise RenderError(f"{where}: context.{key} が無い")
+    _check_inline_text(context["title"], f"{where}: context.title")
+    _check_block_text(context["narrative"], f"{where}: context.narrative")
+    basis = context["spec_basis"]
+    if not isinstance(basis, list) or not basis:
+        raise RenderError(f"{where}: context.spec_basis は非空の配列である必要がある")
+    for index, reference in enumerate(basis):
+        _check_inline_text(reference, f"{where}: context.spec_basis[{index}]")
+    if "reopen_condition" in context:
+        _check_inline_text(
+            context["reopen_condition"], f"{where}: context.reopen_condition"
+        )
+
+
+def _validate_machine_fields(record: dict, where: str) -> None:
+    """生成で参照する機械項目だけを見る (照合器の全検証は重複させない)。"""
+    for field in RENDERED_MACHINE_FIELDS:
+        if field not in record:
+            raise RenderError(f"{where}: 機械項目 {field} が無い")
+    _check_inline_text(record["verdict"], f"{where}: verdict")
+    scope = record["scope"]
+    if not isinstance(scope, dict):
+        raise RenderError(f"{where}: scope は object である必要がある")
+    for key in ("scope_kind", "scope_value"):
+        if key not in scope:
+            raise RenderError(f"{where}: scope.{key} が無い")
+        _check_inline_text(scope[key], f"{where}: scope.{key}")
+    finding_ids = record["source_finding_ids"]
+    if not isinstance(finding_ids, list) or not finding_ids:
+        raise RenderError(f"{where}: source_finding_ids は非空の配列である必要がある")
+    for index, finding_id in enumerate(finding_ids):
+        _check_inline_text(finding_id, f"{where}: source_finding_ids[{index}]")
+    _check_inline_text(record["adjudicated_at_run"], f"{where}: adjudicated_at_run")
+    _check_inline_text(
+        record["adjudicated_at_commit"], f"{where}: adjudicated_at_commit"
+    )
+    if not _is_positive_int(record["review_after_days"]):
+        raise RenderError(
+            f"{where}: review_after_days は正の整数である必要がある: "
+            f"{record['review_after_days']!r}"
+        )
+    if "supersedes" in record:
+        _check_inline_text(record["supersedes"], f"{where}: supersedes")
+
+
+def _check_supersede_graph(records: list) -> None:
+    """差し替え関係の 4 点 (書式 / 実在 / 自己参照 / 循環) を検証する。
+
+    照合器が registry を無効化しているのに生成物だけが誤った有効性を表示する、
+    という食い違いを避けるため、生成器も同じ 4 点を見る。
+    """
+    known = {record["adjudication_id"] for record in records}
+    links = {}
+    for record in records:
+        target = record.get("supersedes")
+        if target is None:
+            continue
+        adjudication_id = record["adjudication_id"]
+        if not _ADJ_ID_RE.fullmatch(target):
+            raise RenderError(f"{adjudication_id}: supersedes の書式が不正: {target!r}")
+        if target not in known:
+            raise RenderError(f"{adjudication_id}: supersedes の指す先が無い: {target}")
+        if target == adjudication_id:
+            raise RenderError(f"{adjudication_id}: supersedes が自己参照している")
+        links[adjudication_id] = target
+    for start in links:
+        seen = set()
+        current = start
+        while current in links:
+            current = links[current]
+            if current == start or current in seen:
+                raise RenderError(f"supersedes が循環している: {start}")
+            seen.add(current)
+
+
+def load_adjudications(path: str) -> list:
+    """裁定登録を読み、生成に必要な範囲で検証する。"""
+    if not os.path.isfile(path):
+        raise RenderError(f"裁定登録が無い: {path}")
+    records: list = []
+    with open(path, encoding="utf-8") as handle:
+        for lineno, raw in enumerate(handle, 1):
+            line = raw.strip()
+            if not line or line.startswith("#"):
+                continue
+            where = f"{os.path.basename(path)}:{lineno}"
+            try:
+                record = _loads(line)
+            except ValueError as error:  # JSONDecodeError は ValueError の派生
+                raise RenderError(f"{where}: JSON として読めない: {error}") from error
+            if not isinstance(record, dict):
+                raise RenderError(f"{where}: 1 行は object である必要がある")
+            records.append(record)
+    if not records:
+        raise RenderError(f"裁定登録が 1 件も無い: {path}")
+
+    seen: set = set()
+    for index, record in enumerate(records, 1):
+        where = f"{os.path.basename(path)} の {index} 件目"
+        adjudication_id = record.get("adjudication_id")
+        if not isinstance(adjudication_id, str) or not _ADJ_ID_RE.fullmatch(adjudication_id):
+            raise RenderError(f"{where}: adjudication_id の書式が不正: {adjudication_id!r}")
+        if adjudication_id in seen:
+            raise RenderError(f"adjudication_id が重複している: {adjudication_id}")
+        seen.add(adjudication_id)
+        _validate_machine_fields(record, adjudication_id)
+        if "context" in record:
+            _validate_context(record["context"], adjudication_id)
+    _check_supersede_graph(records)
+    return records
+
+
+def _check_closed_vocabulary(value, vocabulary, where: str) -> None:
+    if value not in vocabulary:
+        raise RenderError(f"{where}: 語彙外の値: {value!r} (許すのは {list(vocabulary)})")
+
+
+def load_migration(path: str) -> dict:
+    """移行台帳を読み、閉じた語彙と件数の一致を検証する。
+
+    **検査の順序は意図的である**。件数の pin (`EXPECTED_BLOCK_COUNT`) を先に見ると、
+    件数を動かす形の壊れ方 (鍵の重複・見出しの重複) が pin の失敗に隠れて
+    「その検査を消しても赤にならない」状態になる。したがって
+    「1 件ずつ見れば分かること」→「件数の突き合わせ」→「pin」の順に並べる。
+    """
+    if not os.path.isfile(path):
+        raise RenderError(f"移行台帳が無い: {path}")
+    try:
+        migration = _loads(pathlib.Path(path).read_text(encoding="utf-8"))
+    except ValueError as error:
+        raise RenderError(f"移行台帳が JSON として読めない: {error}") from error
+    if not isinstance(migration, dict):
+        raise RenderError("移行台帳は単一の object である必要がある")
+
+    if not _is_positive_int(migration.get("version")):
+        raise RenderError(f"移行台帳 version は正の整数: {migration.get('version')!r}")
+    block_count = migration.get("block_count")
+    if not _is_positive_int(block_count):
+        raise RenderError(f"移行台帳 block_count は正の整数: {block_count!r}")
+
+    provenance = migration.get("provenance")
+    if not isinstance(provenance, dict):
+        raise RenderError("移行台帳 provenance は object である必要がある")
+    for key in PROVENANCE_KEYS:
+        if key not in provenance:
+            raise RenderError(f"移行台帳 provenance.{key} が無い")
+    for key in ("source_file", "source_commit", "source_lines", "migrated_at", "note"):
+        if not _is_filled_str(provenance[key]):
+            raise RenderError(f"移行台帳 provenance.{key} は非空文字列である必要がある")
+    headings = provenance["source_block_headings"]
+    if not isinstance(headings, list) or not all(_is_filled_str(h) for h in headings):
+        raise RenderError("移行台帳 provenance.source_block_headings は非空文字列の配列")
+    if len(set(headings)) != len(headings):
+        raise RenderError("移行台帳 provenance.source_block_headings に重複がある")
+    pins = provenance["machine_projection_sha256"]
+    if not isinstance(pins, dict) or not pins:
+        raise RenderError("移行台帳 provenance.machine_projection_sha256 は非空の object")
+    for adjudication_id, digest in pins.items():
+        if not _ADJ_ID_RE.fullmatch(str(adjudication_id)):
+            raise RenderError(f"pin の鍵が adjudication_id ではない: {adjudication_id!r}")
+        if not isinstance(digest, str) or not _SHA256_RE.fullmatch(digest):
+            raise RenderError(f"pin の値が 64 桁 hex ではない: {adjudication_id}")
+
+    entries = migration.get("entries")
+    if not isinstance(entries, list):
+        raise RenderError("移行台帳 entries は配列である必要がある")
+    keys: set = set()
+    for entry in entries:
+        if not isinstance(entry, dict):
+            raise RenderError("移行台帳 entries の要素は object である必要がある")
+        key = entry.get("key")
+        if not _is_filled_str(key):
+            raise RenderError(f"移行台帳 entries の key が不正: {key!r}")
+        if key in keys:
+            raise RenderError(f"移行台帳 entries の key が重複している: {key}")
+        keys.add(key)
+        _check_closed_vocabulary(entry.get("key_kind"), MIGRATION_KEY_KINDS, f"{key} の key_kind")
+        _check_closed_vocabulary(entry.get("target"), MIGRATION_TARGETS, f"{key} の target")
+        minimums = entry.get("field_minimums")
+        if not isinstance(minimums, dict) or not minimums:
+            raise RenderError(f"{key}: field_minimums は非空の object である必要がある")
+        for field, minimum in minimums.items():
+            _check_closed_vocabulary(field, CONTEXT_KEYS, f"{key} の field_minimums の欄名")
+            if not _is_positive_int(minimum):
+                raise RenderError(f"{key}: field_minimums.{field} は正の整数: {minimum!r}")
+        fragments = entry.get("required_fragments")
+        if not isinstance(fragments, list) or not fragments:
+            raise RenderError(f"{key}: required_fragments は非空の配列である必要がある")
+        pairs: set = set()
+        for fragment in fragments:
+            if not isinstance(fragment, dict) or set(fragment) != {"field", "value"}:
+                raise RenderError(f"{key}: required_fragments の要素は field / value の 2 欄")
+            _check_closed_vocabulary(
+                fragment["field"], CONTEXT_KEYS, f"{key} の required_fragments の field"
+            )
+            if not _is_filled_str(fragment["value"]):
+                raise RenderError(f"{key}: required_fragments の value が空")
+            pair = (fragment["field"], fragment["value"])
+            if pair in pairs:
+                raise RenderError(f"{key}: required_fragments が重複している: {pair}")
+            pairs.add(pair)
+
+    # 件数の突き合わせ (三点一致)。1 件ずつ見れば分かる壊れ方をすべて弾いた後に置く。
+    if len(entries) != block_count:
+        raise RenderError(
+            f"entries の件数が block_count と食い違う: {len(entries)} != {block_count}"
+        )
+    if len(headings) != block_count:
+        raise RenderError(
+            f"移行元見出しの件数が block_count と食い違う: {len(headings)} != {block_count}"
+        )
+    if block_count != EXPECTED_BLOCK_COUNT:
+        raise RenderError(
+            f"移行元ブロック数の pin と食い違う: {block_count} != {EXPECTED_BLOCK_COUNT}"
+        )
+    return migration
+
+
+def _context_field_text(context: dict, field: str, where: str) -> str:
+    if field not in context:
+        raise RenderError(f"{where}: 移行台帳が要求する欄 {field} が context に無い")
+    value = context[field]
+    if isinstance(value, list):
+        return "\n".join(value)
+    return value
+
+
+def check_migration(migration: dict, records: list) -> None:
+    """移行元の内容が痩せずに登録の経緯へ移っていることを検査する。"""
+    by_id = {record["adjudication_id"]: record for record in records}
+    for entry in migration["entries"]:
+        key = entry["key"]
+        record = by_id.get(key)
+        if record is None:
+            raise RenderError(f"移行台帳の鍵が解決できない: {key}")
+        context = record.get("context")
+        if not context:
+            raise RenderError(f"移行台帳の鍵 {key} の登録に context が無い")
+        for field, minimum in entry["field_minimums"].items():
+            text = _context_field_text(context, field, key)
+            if len(text) < minimum:
+                raise RenderError(
+                    f"{key}: context.{field} が痩せている ({len(text)} 文字 < 下限 {minimum})"
+                )
+        for fragment in entry["required_fragments"]:
+            field, value = fragment["field"], fragment["value"]
+            text = _context_field_text(context, field, key)
+            if not fragment_present(value, text):
+                raise RenderError(f"{key}: context.{field} に必須の断片が無い: {value!r}")
+
+    pins = migration["provenance"]["machine_projection_sha256"]
+    for adjudication_id, digest in pins.items():
+        record = by_id.get(adjudication_id)
+        if record is None:
+            raise RenderError(f"pin の指す登録が無い: {adjudication_id}")
+        actual = canonical_machine_projection(record)
+        if actual != digest:
+            raise RenderError(
+                f"{adjudication_id}: 機械項目が移行時点から変わっている "
+                f"(append-only + supersede で扱うこと)"
+            )
+
+
+# ---------------------------------------------------------------------
+# 出力の組み立て
+# ---------------------------------------------------------------------
+def active_ids(records: list) -> set:
+    """有効な登録の id 集合。**照合器と同じ規則** (未 supersede のものだけ)。"""
+    superseded = {r["supersedes"] for r in records if r.get("supersedes")}
+    return {
+        r["adjudication_id"] for r in records if r["adjudication_id"] not in superseded
+    }
+
+
+def _sort_key(adjudication_id: str) -> int:
+    return int(adjudication_id.split("-", 1)[1])
+
+
+HEADER = f"""<!-- generated by .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py -->
+<!-- DO NOT EDIT: 入力は ledger/adjudications.jsonl (登録一覧と経緯) と
+     ledger/spec-ledger-migration.json (移行検査)。
+     再生成: {REGENERATE_COMMAND} -->
+
+# bug-hunt 仕様台帳 (spec-ledger) — 裁定済み事象の可視化
+
+**このファイルは生成物である。手で編集しない。**
+経緯は `ledger/adjudications.jsonl` の `context` に書き、上のコマンドで再生成する。
+手編集と再生成忘れは `--check` が検出する。**ただし CI では走らないので、
+再生成を忘れたまま古い内容が残っている状態は起こり得る** (下の「この文書の限界」)。
+運用手順の正本は `ledger/README.md` であり、本ファイルは「登録の可視化」だけを担う。
+
+## 使い方 (bug-hunt 実行者へ)
+
+- finding を起票する前に本台帳を検索すること。**有効性が `active` の項目に載っている事象は
+  再起票しない** (「既知」と一行記録して次へ)。
+- **`superseded` の項目は履歴である。判断の正本は後継の登録**であり、
+  `superseded` を根拠に再起票を止めてはならない。
+  照合器 (`validate_findings.py --annotate`) も `active` の登録だけを照合に使う。
+- 同一事象が再発したと感じたら、**仕様根拠**を実コードで確認する。コードが台帳と乖離していれば
+  regression の可能性があるので、その差分を根拠に新規 finding として起票してよい。
+
+## この文書の限界
+
+- 内容が最新である保証は無い。`--check` を人が走らせたときにだけ drift が分かる。
+- 経緯の**正しさ**は機械が見ていない (形・全数性・痩せ・drift だけを見る)。
+
+---
+
+## 登録一覧 (adjudications.jsonl の可視化)
+"""
+
+MIGRATION_SECTION_HEAD = """---
+
+## 移行の全数性 (機械可読)
+
+移行元 spec-ledger.md の全ブロックが上のどこかへ移ったことを機械が突き合わせる索引。
+1 行 1 鍵。人向けの本文中の言及と取り違えないため、完全一致で比べる。
+
+<!-- migration-keys:begin -->
+"""
+
+
+def _render_entry(record: dict, *, active: set, superseded_by: dict) -> str:
+    adjudication_id = record["adjudication_id"]
+    context = record.get("context")
+    title = context["title"] if context else "(経緯は未記入)"
+    lines = [f"{ENTRY_MARKER_PREFIX} {adjudication_id} -->", f"### {adjudication_id} — {title}", ""]
+    if adjudication_id in active:
+        lines.append("- 有効性: **active**")
+    else:
+        successors = " / ".join(sorted(superseded_by[adjudication_id], key=_sort_key))
+        lines.append(
+            f"- 有効性: **superseded** ({successors} に差し替えられた。判断の正本は後継)"
+        )
+    if record.get("supersedes"):
+        lines.append(f"- 差し替え: {record['supersedes']} を差し替えた")
+    lines.append("- 由来 finding: " + " / ".join(record["source_finding_ids"]))
+    scope = record["scope"]
+    lines.append(
+        f"- 判定: {record['verdict']} / 対象面: "
+        f"{scope['scope_kind']}={scope['scope_value']}"
+    )
+    lines.append(
+        f"- 確定: run {record['adjudicated_at_run']} "
+        f"(commit {record['adjudicated_at_commit']}) / "
+        f"見直し期限: {record['review_after_days']} 日"
+    )
+    if context:
+        lines.append("- 仕様根拠: " + " ; ".join(context["spec_basis"]))
+        if "reopen_condition" in context:
+            lines.append(f"- 再オープン条件: {context['reopen_condition']}")
+        lines.extend(["", context["narrative"].rstrip("\n")])
+    else:
+        lines.append(
+            f"- {NO_CONTEXT_MARK} (この登録には `context` が無い。書くときは "
+            "`adjudications.jsonl` の当該行へ `context` を足して再生成する)"
+        )
+    return "\n".join(lines) + "\n"
+
+
+def render(records: list, migration: dict) -> str:
+    """検証済みの入力から生成物の全文を組み立てる (ファイルには触れない)。"""
+    superseded_by: dict = {}
+    for record in records:
+        target = record.get("supersedes")
+        if target:
+            superseded_by.setdefault(target, []).append(record["adjudication_id"])
+    active = active_ids(records)
+    ordered = sorted(records, key=lambda r: _sort_key(r["adjudication_id"]))
+
+    parts = [HEADER]
+    for record in ordered:
+        parts.append("\n" + _render_entry(record, active=active, superseded_by=superseded_by))
+    parts.append("\n" + MIGRATION_SECTION_HEAD)
+    for entry in migration["entries"]:
+        parts.append(f"- key: {entry['key']}\n")
+    parts.append("<!-- migration-keys:end -->\n")
+    return "".join(parts)
+
+
+def build(
+    *,
+    adjudications_path: str = ADJUDICATIONS_PATH,
+    migration_path: str = MIGRATION_PATH,
+) -> str:
+    """入力を検証して生成物の全文を返す。失敗は RenderError で、出力には触れない。"""
+    records = load_adjudications(adjudications_path)
+    migration = load_migration(migration_path)
+    check_migration(migration, records)
+    return render(records, migration)
+
+
+# ---------------------------------------------------------------------
+# 書き出し (原子的)
+# ---------------------------------------------------------------------
+def write_atomically(text: str, path: str) -> None:
+    """同一ディレクトリの一時ファイルへ書いてから置換する。
+
+    保証する: 通常の失敗 (検証エラー・書き込みエラー・置換エラー) では
+              既存ファイルが 1 バイトも変わらないこと。一時ファイルを残さないこと。
+    保証しない: 電源断・ファイルシステム破損に対する耐性 (fsync していない)。
+    """
+    directory = os.path.dirname(os.path.abspath(path))  # 別 FS を跨がないため出力と同じ場所
+    mode = os.stat(path).st_mode & 0o777 if os.path.exists(path) else 0o644
+    fd, tmp = tempfile.mkstemp(dir=directory, prefix=".spec-ledger.", suffix=".tmp")
+    try:
+        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
+            handle.write(text)
+        os.chmod(tmp, mode)  # mkstemp は 0600 を作るので、生成物の mode を明示する
+        os.replace(tmp, path)
+    except BaseException:
+        if os.path.exists(tmp):
+            os.unlink(tmp)
+        raise
+
+
+def main(argv=None) -> int:
+    parser = argparse.ArgumentParser(description="spec-ledger.md を生成する")
+    parser.add_argument("--check", action="store_true", help="生成結果と現物を比較する")
+    parser.add_argument("--output", default=OUTPUT_PATH)
+    parser.add_argument("--adjudications", default=ADJUDICATIONS_PATH)
+    parser.add_argument("--migration", default=MIGRATION_PATH)
+    args = parser.parse_args(argv)
+
+    try:
+        text = build(
+            adjudications_path=args.adjudications, migration_path=args.migration
+        )
+    except RenderError as error:
+        print(f"render error: {error}", file=sys.stderr)
+        print(f"再生成: {REGENERATE_COMMAND}", file=sys.stderr)
+        return 1
+
+    if args.check:
+        if not os.path.isfile(args.output):
+            print(f"生成物が無い: {args.output}", file=sys.stderr)
+            print(f"再生成: {REGENERATE_COMMAND}", file=sys.stderr)
+            return 1
+        # 比較はバイト列で行う (read_text() は CRLF を LF に畳むため、
+        # 改行だけ変えた手編集を「一致」と誤判定する)。
+        current_bytes = pathlib.Path(args.output).read_bytes()
+        if current_bytes == text.encode("utf-8"):
+            return 0
+        current = current_bytes.decode("utf-8", errors="replace")
+        diff = difflib.unified_diff(
+            current.splitlines(keepends=True),
+            text.splitlines(keepends=True),
+            fromfile=f"{args.output} (現物)",
+            tofile="(生成結果)",
+        )
+        for line in list(diff)[:200]:
+            sys.stderr.write(line)
+        print(f"\n生成物が古い (または手編集されている)。再生成: {REGENERATE_COMMAND}",
+              file=sys.stderr)
+        return 1
+
+    write_atomically(text, args.output)
+    print(f"wrote {args.output} ({len(text)} chars)")
+    return 0
+
+
+if __name__ == "__main__":
+    raise SystemExit(main())
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
index 2ce8856..ef0c883 100644
--- a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
+++ b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
@@ -1,195 +1,1045 @@
-"""spec-ledger.md の腐り検知 (stdlib のみ)。
+"""spec-ledger.md (生成物) の契約テスト (stdlib のみ)。
 
-`spec-ledger.md` は機械 registry (`adjudications.jsonl`) の「対」であり、人間向け申し送りの正本。
-台帳は放置すると腐る (根拠に書いたファイルが消える / registry に「登録済」と書いたのに実体が無い)
-ため、次の 3 点だけを機械検知する:
+`spec-ledger.md` は **生成物**であり、入力は 2 つ —
+`ledger/adjudications.jsonl` (登録一覧と、各登録の `context` に書かれた経緯) と
+`ledger/spec-ledger-migration.json` (手書き時代の申し送りが痩せずに移ったことの検査) である。
 
- (1) 確定項目の必須欄が揃っているか (初回登録テンプレートの「欄を削らない」の機械化)
- (2) 根拠欄に書いたファイルが実在するか (**行番号は見ない**)
- (3) 「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在するか
+本テストが固定するのは次の 5 群である:
 
-(2) で行番号を検証しないのは意図的である。通常のリファクタで台帳テストが壊れる保守負債になるため。
-旧 registry 18 件が「実在しないパス」を指し watch_globs invalidation が永久に発火しなかった事故
-(`ledger/README.md` 運用ガード (d)) の再発防止が目的なので、**実在**だけを見れば足りる。
+  A. 生成物であること (再生成の一致・手編集の検出・原子的書き込み)
+  B. 掲載の完全性 (登録は 1 件残らずちょうど 1 回載る。機械マーカーで数える)
+  C. `context` の形と、照合器 (`validate_findings.py`) との fail-closed 境界
+  D. 移行台帳 (痩せ・断片の欠落・台帳自身を弱める変更の検出)
+  E. 既存方針の継承 (根拠パスの実在・生成器が照合器から隔離されていること)
 
-台帳が空 (エントリ 0 件) のときは 3 つとも vacuous に PASS する (テンプレート初期状態を壊さない)。
+**保証しないもの**: これらは CI では 1 つも走らない。人が
+`python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` か
+`render_spec_ledger.py --check` を走らせたときにだけ腐りが分かる。
+経緯の**内容が正しいこと**も機械は見ていない (形・全数性・痩せ・drift だけを見る)。
 
 実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
 """
 
 from __future__ import annotations
 
+import contextlib
+import io
 import json
+import os
 import re
+import shutil
+import tempfile
 import unittest
+from collections import Counter
 from pathlib import Path
+from unittest import mock
+
+import render_spec_ledger as renderer
+import validate_findings as v
 
 LEDGER_DIR = Path(__file__).resolve().parent
 SKILL_ROOT = LEDGER_DIR.parent
-REPO_ROOT = SKILL_ROOT.parents[2]  # .claude/skills/app-bug-hunt -> repo root
+# .claude/skills/app-bug-hunt -> parents[0]=skills / parents[1]=.claude / parents[2]=リポジトリルート。
+REPO_ROOT = SKILL_ROOT.parents[2]
 SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
 ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"
+MIGRATION = LEDGER_DIR / "spec-ledger-migration.json"
+MATCHER_SOURCE = LEDGER_DIR / "validate_findings.py"
+
+ENTRY_MARKER_RE = re.compile(r"^<!-- entry: (?P<aid>A-[0-9]+) -->$", re.MULTILINE)
+
+# 移行台帳の期待値。**台帳自身を弱める変更を赤にする**ための意図した二重管理である
+# (台帳だけに置くと、断片や下限を消す変更が台帳の書き換えだけで通ってしまう)。
+EXPECTED_MIGRATION = {
+    "A-001": {
+        "key_kind": "adjudication_id",
+        "target": "adjudications",
+        "field_minimums": {"narrative": 437, "reopen_condition": 230},
+        "required_fragments": [
+            ("narrative", "feedback-probe.js"),
+            ("narrative", "T095"),
+            ("reopen_condition", "AUTO_DISMISS_MS"),
+            ("reopen_condition", "installed_now"),
+        ],
+    },
+}
+EXPECTED_BLOCK_COUNT = 1
+
+# 移行時点の「機械項目だけの射影」の sha256。移行台帳・現在の登録と**三点**で突き合わせる
+# (二点だと、機械項目を書き換えると同時に台帳の hash を更新すれば通ってしまう)。
+EXPECTED_MACHINE_PROJECTION_SHA256 = {
+    "A-001": "e873bfdd2e4a90400788577ddbf90db51c853b5583be3a0f0ad03b1cd5ca39b6",
+    "A-002": "1116927afad77292d301cb2cca57d0370b23cfd9ac616f94e751af796b9b4ad9",
+    "A-003": "a96092441ecc66054c11c2eecf846cc4949f6ecfc1a634105e3a59e0431b7fae",
+}
 
-ENTRY_RE = re.compile(r"^#### (?P<fid>\S+) — (?P<title>.+)$")
-HEADING_RE = re.compile(r"^#{1,6} ")
-FENCE_RE = re.compile(r"^\s*```")
-
-# 初回登録テンプレートの全 9 欄。テンプレートを直したらこの定数も直す (1 対 1 の関係)。
-REQUIRED_FIELDS = (
-    "判定",
-    "根拠 (file:line)",
-    "なぜ誤検知に見えたか",
-    "driver 側の再発防止",
-    "watch_globs (機械 registry に載せる場合)",
-    "review_after_days",
-    "確定した run_id",
-    "再オープン条件",
-    "機械 registry",
+# 根拠 (`context.spec_basis`) の 1 要素の先頭トークンの書式。
+# 位置指定 (`:230-232`) とアンカー (`#見出し`) は任意で、実在検査では捨てる。
+#
+# 拡張子は**閉じた集合**である。詳細設計が列挙した 9 種に `jsonl` を 1 つだけ足した
+# (A-003 の根拠が run 成果物 `findings-merged.jsonl` を指すため。`json` だけだと
+# 末尾の `l` が余って書式不正になり、実在する根拠が失敗扱いになる)。
+# **これ以外は増やさない** — 集合を広げるほど「書式を外して検査から逃げる」余地が増える。
+SPEC_BASIS_EXTENSIONS = (
+    "php", "ts", "js", "svelte", "md", "jsonl", "json", "yaml", "yml", "py", "sh",
 )
-# 照合は「キー文字列が本文のどこかにある」ではなく **行形式** で行う
-# (本文中に同じ語が出ただけで PASS する誤検知を避ける)。
-FIELD_LINE = "- **{name}**:"
-FIELD_START_RE = re.compile(r"^- \*\*(?P<name>[^*]+)\*\*:")
-
-BACKTICK_RE = re.compile(r"`([^`]+)`")
-# 位置指定 (`:123-125` / `:12:5` / `#L12` / `#anchor`) は**捨てて**パス部だけを実在確認する。
-# 位置記法を許容集合に入れておかないと、その記法で書かれた根拠が丸ごと検査対象外に
-# すり抜けてしまう (腐りの見逃し)。
-PATH_LIKE = re.compile(
-    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|json|ya?ml|py|sh))(?:[:#][\w.-]*)*$"
+# 正規表現は**この定数から組み立てる**。別々に手書きすると、式だけを広げても
+# 拒否例に無い拡張子は全テストが緑のまま通ってしまう (許可側の pin にならない)。
+# 長い順に並べるのは `jsonl` が `json` に食われないようにするため。
+_SPEC_BASIS_EXT_ALTERNATION = "|".join(
+    re.escape(extension)
+    for extension in sorted(SPEC_BASIS_EXTENSIONS, key=len, reverse=True)
 )
-ADJ_ID_RE = re.compile(r"\bA-\d{3}\b")
+SPEC_BASIS_FORM_RE = re.compile(
+    rf"(?P<path>[\w./-]+\.(?:{_SPEC_BASIS_EXT_ALTERNATION}))(?:[:#][\w.\-#]*)*"
+)
+
+
+def setUpModule() -> None:
+    """前提確認: REPO_ROOT の数え方が正しいこと。
+
+    ここを間違えると根拠パスの実在検査が別ディレクトリを見て全件緑になってしまう。
+    """
+    if not (REPO_ROOT / "AGENTS.md").is_file():
+        raise AssertionError(f"REPO_ROOT の導出が誤っている: {REPO_ROOT}")
 
 
-def _lines_outside_fences(text: str) -> list[str]:
-    """コードフェンス (```) の内側を空行に潰した行リスト。
+def _spec_basis_problem(reference: str, repo_root: Path) -> str | None:
+    """根拠 1 要素の問題点を返す (無ければ None)。
 
-    `## 初回登録テンプレート` のプレースホルダ (`path/to/File.php` 等) を
-    実エントリとして拾わないため。行番号を保つよう「除去」ではなく「空行化」する。
+    形式不正は「対象外」ではなく**失敗**として扱う (書式を外せば検査から逃げられるため)。
+    行番号は見ない (通常のリファクタで台帳テストが壊れる保守負債を作らないため)。
     """
-    out: list[str] = []
-    in_fence = False
-    for line in text.splitlines():
-        if FENCE_RE.match(line):
-            in_fence = not in_fence
-            out.append("")
-            continue
-        out.append("" if in_fence else line)
-    return out
-
-
-def _entries() -> list[tuple[str, str]]:
-    """(finding_id, 本文) のリスト。テンプレート節 (フェンス内) は除外済み。"""
-    if not SPEC_LEDGER.exists():
-        raise AssertionError(f"spec-ledger.md が見つからない: {SPEC_LEDGER}")
-    lines = _lines_outside_fences(SPEC_LEDGER.read_text(encoding="utf-8"))
-    entries: list[tuple[str, str]] = []
-    current_id: str | None = None
-    body: list[str] = []
-    for line in lines:
-        match = ENTRY_RE.match(line)
-        if match:
-            if current_id is not None:
-                entries.append((current_id, "\n".join(body)))
-            current_id = match.group("fid")
-            body = []
-            continue
-        if current_id is not None and HEADING_RE.match(line):
-            entries.append((current_id, "\n".join(body)))
-            current_id = None
-            body = []
-            continue
-        if current_id is not None:
-            body.append(line)
-    if current_id is not None:
-        entries.append((current_id, "\n".join(body)))
-    return entries
-
-
-def _field_body(entry_body: str, name: str) -> str:
-    """`- **{name}**:` 欄の本文 (次の欄が始まるまでの継続行を含む)。無ければ空文字。"""
-    prefix = FIELD_LINE.format(name=name)
-    collected: list[str] = []
-    capturing = False
-    for line in entry_body.splitlines():
-        if capturing:
-            if FIELD_START_RE.match(line):
-                break
-            collected.append(line)
-            continue
-        if line.startswith(prefix):
-            capturing = True
-            collected.append(line[len(prefix) :])
-    return "\n".join(collected)
-
-
-def _registered_adjudication_ids() -> set[str]:
-    if not ADJUDICATIONS.exists():
-        return set()
-    ids: set[str] = set()
-    for raw in ADJUDICATIONS.read_text(encoding="utf-8").splitlines():
-        line = raw.strip()
-        if not line or line.startswith("#"):
-            continue
-        record = json.loads(line)
-        adjudication_id = record.get("adjudication_id")
-        if isinstance(adjudication_id, str):
-            ids.add(adjudication_id)
-    return ids
-
-
-class SpecLedgerTest(unittest.TestCase):
-    def test_required_fields_present(self) -> None:
-        """確定項目はテンプレートの全 9 欄を `- **欄名**:` の行形式で持つ。"""
-        missing: list[str] = []
-        for finding_id, body in _entries():
-            for name in REQUIRED_FIELDS:
-                prefix = FIELD_LINE.format(name=name)
-                if not any(line.startswith(prefix) for line in body.splitlines()):
-                    missing.append(f"{finding_id}: 欄 '{name}' が無い")
-        self.assertEqual(
-            missing,
-            [],
-            "spec-ledger.md の確定項目に必須欄の欠落:\n" + "\n".join(missing),
+    tokens = reference.split()
+    if not tokens:
+        return "空の根拠"
+    token = tokens[0]
+    # fullmatch を使う (`match` + `$` は末尾の改行 1 個を通してしまう)。
+    matched = SPEC_BASIS_FORM_RE.fullmatch(token)
+    if matched is None:
+        return f"書式不正: {token!r}"
+    path = matched.group("path")
+    if path.startswith("/"):
+        return f"絶対パス: {path!r}"
+    if ".." in path.split("/"):
+        return f"親ディレクトリ参照: {path!r}"
+    root = repo_root.resolve()
+    resolved = (root / path).resolve()
+    if root != resolved and root not in resolved.parents:
+        return f"リポジトリ外へ脱出: {path!r}"
+    if not resolved.is_file():
+        return f"実在しない (または通常ファイルでない): {path!r}"
+    return None
+
+
+def _entry_blocks(text: str) -> dict[str, str]:
+    """機械マーカーで区切った項目本文の辞書 {adjudication_id: 本文}。"""
+    blocks: dict[str, str] = {}
+    positions = [(m.group("aid"), m.start(), m.end()) for m in ENTRY_MARKER_RE.finditer(text)]
+    for index, (aid, _start, end) in enumerate(positions):
+        stop = positions[index + 1][1] if index + 1 < len(positions) else len(text)
+        blocks[aid] = text[end:stop]
+    return blocks
+
+
+class _Stage:
+    """入力 2 点の写しを持つ一時作業場。**現物は絶対に書き換えない**。"""
+
+    def __init__(self, directory: Path) -> None:
+        self.dir = directory
+        self.adjudications = directory / "adjudications.jsonl"
+        self.migration = directory / "spec-ledger-migration.json"
+        self.output = directory / "spec-ledger.md"
+        shutil.copy2(ADJUDICATIONS, self.adjudications)
+        shutil.copy2(MIGRATION, self.migration)
+
+    # --- 入力の読み書き -------------------------------------------------
+    def records(self) -> list[dict]:
+        out = []
+        for raw in self.adjudications.read_text(encoding="utf-8").splitlines():
+            line = raw.strip()
+            if not line or line.startswith("#"):
+                continue
+            out.append(json.loads(line))
+        return out
+
+    def record(self, adjudication_id: str) -> dict:
+        for record in self.records():
+            if record.get("adjudication_id") == adjudication_id:
+                return record
+        raise AssertionError(f"登録が無い: {adjudication_id}")
+
+    def write_records(self, records: list[dict]) -> None:
+        lines = [json.dumps(r, ensure_ascii=False, sort_keys=False) for r in records]
+        self.adjudications.write_text("\n".join(lines) + "\n", encoding="utf-8")
+
+    def write_lines(self, lines: list[str]) -> None:
+        self.adjudications.write_text("\n".join(lines) + "\n", encoding="utf-8")
+
+    def patch_record(self, adjudication_id: str, mutate) -> None:
+        records = self.records()
+        for record in records:
+            if record.get("adjudication_id") == adjudication_id:
+                mutate(record)
+        self.write_records(records)
+
+    def migration_obj(self) -> dict:
+        return json.loads(self.migration.read_text(encoding="utf-8"))
+
+    def write_migration(self, obj) -> None:
+        self.migration.write_text(
+            json.dumps(obj, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
+        )
+
+    def write_migration_text(self, text: str) -> None:
+        self.migration.write_text(text, encoding="utf-8")
+
+    # --- 生成 -----------------------------------------------------------
+    def build(self) -> str:
+        return renderer.build(
+            adjudications_path=str(self.adjudications),
+            migration_path=str(self.migration),
         )
 
-    def test_evidence_paths_exist(self) -> None:
-        """根拠欄に書いたファイルパスがリポジトリに実在する (行番号は見ない)。"""
-        broken: list[str] = []
-        for finding_id, body in _entries():
-            evidence = _field_body(body, "根拠 (file:line)")
-            for token in BACKTICK_RE.findall(evidence):
-                matched = PATH_LIKE.match(token.strip())
-                if matched is None:
-                    continue
-                path = matched.group("path")
-                if not (REPO_ROOT / path).exists():
-                    broken.append(f"{finding_id}: 根拠パスが実在しない: {path}")
-        self.assertEqual(
-            broken,
-            [],
-            "spec-ledger.md の根拠パスが腐っている:\n" + "\n".join(broken),
+    def cli(self, *args: str) -> tuple[int, str, str]:
+        argv = [
+            "--adjudications", str(self.adjudications),
+            "--migration", str(self.migration),
+            "--output", str(self.output),
+            *args,
+        ]
+        out, err = io.StringIO(), io.StringIO()
+        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
+            code = renderer.main(argv)
+        return code, out.getvalue(), err.getvalue()
+
+    def seed_output(self, text: str = "sentinel\n") -> str:
+        """出力位置に見張り用の中身を置き、その sha256 を返す。"""
+        self.output.write_text(text, encoding="utf-8")
+        return self.output_sha()
+
+    def output_sha(self) -> str:
+        """**バイト列**の sha256 (テキストで読み直すと改行の変化を見逃す)。"""
+        return renderer.sha256_of_bytes(self.output.read_bytes())
+
+    def temp_files(self) -> list[Path]:
+        return sorted(self.dir.glob(".spec-ledger.*.tmp"))
+
+
+@contextlib.contextmanager
+def staged():
+    with tempfile.TemporaryDirectory() as tmp:
+        yield _Stage(Path(tmp))
+
+
+# =====================================================================
+# A. 生成物であること (契約 1-9)
+# =====================================================================
+class GeneratedArtifactTest(unittest.TestCase):
+    def test_generated_output_matches_committed_file(self) -> None:
+        """契約 1: 生成結果が現物と byte 一致する (再生成忘れの検出)。
+
+        比較は**バイト列**で行う。`read_text()` は CRLF を LF へ畳むため、
+        改行だけ変えた差分を「一致」と誤判定する。
+        """
+        self.assertEqual(SPEC_LEDGER.read_bytes(), renderer.build().encode("utf-8"))
+
+    def test_check_passes_on_committed_file(self) -> None:
+        """契約 2: `--check` は現物に対して exit 0。"""
+        out, err = io.StringIO(), io.StringIO()
+        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
+            code = renderer.main(["--check"])
+        self.assertEqual(code, 0, err.getvalue())
+
+    def test_manual_edit_is_detected(self) -> None:
+        """契約 3: 手編集は exit 1 で検出し、stderr に再生成コマンドを出す。"""
+        with staged() as stage:
+            stage.output.write_text(stage.build(), encoding="utf-8")
+            self.assertEqual(stage.cli("--check")[0], 0)
+            edited = stage.output.read_text(encoding="utf-8").replace("有効性", "有効生")
+            stage.output.write_text(edited, encoding="utf-8")
+            code, _out, err = stage.cli("--check")
+            self.assertEqual(code, 1)
+            self.assertIn(renderer.REGENERATE_COMMAND, err)
+
+    def test_newline_only_edit_is_detected(self) -> None:
+        """契約 3 (改行だけの差分): 中身が同じでも改行コードが変われば exit 1。
+
+        文字列として比べると CRLF が LF に畳まれて素通りする経路を塞ぐ。
+        """
+        with staged() as stage:
+            text = stage.build()
+            stage.output.write_bytes(text.encode("utf-8"))
+            self.assertEqual(stage.cli("--check")[0], 0)
+            stage.output.write_bytes(text.replace("\n", "\r\n").encode("utf-8"))
+            code, _out, err = stage.cli("--check")
+            self.assertEqual(code, 1)
+            self.assertIn(renderer.REGENERATE_COMMAND, err)
+
+    def test_check_fails_when_output_is_absent(self) -> None:
+        """契約 4: 出力が無ければ `--check` は exit 1。"""
+        with staged() as stage:
+            self.assertFalse(stage.output.exists())
+            self.assertEqual(stage.cli("--check")[0], 1)
+
+    def test_render_is_atomic_on_input_validation_failure(self) -> None:
+        """契約 5: 入力が壊れていても既存の出力は 1 バイトも変わらない。"""
+        with staged() as stage:
+            before = stage.seed_output()
+            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
+            code, _out, err = stage.cli()
+            self.assertEqual(code, 1, err)
+            self.assertEqual(stage.output_sha(), before)
+            self.assertEqual(stage.temp_files(), [])
+
+    def test_render_is_atomic_when_replace_fails(self) -> None:
+        """契約 6: 置換が失敗しても既存の出力は変わらない (障害注入)。"""
+        with staged() as stage:
+            before = stage.seed_output()
+            with mock.patch.object(renderer.os, "replace", side_effect=OSError("replace 失敗")):
+                with self.assertRaises(OSError):
+                    renderer.write_atomically("新しい中身\n", str(stage.output))
+            self.assertEqual(stage.output_sha(), before)
+            self.assertEqual(stage.temp_files(), [])
+
+    def test_render_is_atomic_when_write_fails(self) -> None:
+        """契約 7 (書き込み経路): 一時ファイルへの書き込み失敗でも出力は変わらない。"""
+
+        class _ExplodingFile:
+            def __init__(self, fd: int) -> None:
+                self._fd = fd
+
+            def __enter__(self):
+                return self
+
+            def __exit__(self, *_args) -> bool:
+                os.close(self._fd)
+                return False
+
+            def write(self, _text: str) -> int:
+                raise OSError("write 失敗")
+
+        with staged() as stage:
+            before = stage.seed_output()
+            with mock.patch.object(renderer.os, "fdopen", lambda fd, *a, **k: _ExplodingFile(fd)):
+                with self.assertRaises(OSError):
+                    renderer.write_atomically("新しい中身\n", str(stage.output))
+            self.assertEqual(stage.output_sha(), before)
+            self.assertEqual(stage.temp_files(), [])
+
+    def test_render_is_atomic_when_chmod_fails(self) -> None:
+        """契約 7 (mode 設定経路): chmod 失敗でも出力は変わらない。"""
+        with staged() as stage:
+            before = stage.seed_output()
+            with mock.patch.object(renderer.os, "chmod", side_effect=OSError("chmod 失敗")):
+                with self.assertRaises(OSError):
+                    renderer.write_atomically("新しい中身\n", str(stage.output))
+            self.assertEqual(stage.output_sha(), before)
+            self.assertEqual(stage.temp_files(), [])
+
+    def test_render_leaves_no_temp_file_behind(self) -> None:
+        """契約 8: 3 経路すべての失敗の後に一時ファイルが残らない。"""
+        with staged() as stage:
+            stage.seed_output()
+            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
+            stage.cli()
+            self.assertEqual(stage.temp_files(), [])
+            for target, kwargs in (
+                ("replace", {"side_effect": OSError("x")}),
+                ("chmod", {"side_effect": OSError("x")}),
+            ):
+                with mock.patch.object(renderer.os, target, **kwargs):
+                    with self.assertRaises(OSError):
+                        renderer.write_atomically("中身\n", str(stage.output))
+                self.assertEqual(stage.temp_files(), [])
+
+    def test_output_mode_is_preserved_or_0644(self) -> None:
+        """契約 9: 既存出力の mode を保ち、新規出力は 0644 (mkstemp の 0600 を引き継がない)。"""
+        with staged() as stage:
+            stage.output.write_text("見張り\n", encoding="utf-8")
+            os.chmod(stage.output, 0o640)
+            renderer.write_atomically("中身\n", str(stage.output))
+            self.assertEqual(stage.output.stat().st_mode & 0o777, 0o640)
+
+            fresh = stage.dir / "new-spec-ledger.md"
+            renderer.write_atomically("中身\n", str(fresh))
+            self.assertEqual(fresh.stat().st_mode & 0o777, 0o644)
+
+
+# =====================================================================
+# B. 掲載の完全性 (契約 10-17)
+# =====================================================================
+class ListingCompletenessTest(unittest.TestCase):
+    def test_every_adjudication_id_is_listed_exactly_once(self) -> None:
+        """契約 10: 機械マーカーの多重集合が登録の id 集合と一致し、各 1 回。"""
+        text = renderer.build()
+        listed = Counter(m.group("aid") for m in ENTRY_MARKER_RE.finditer(text))
+        registered = Counter(
+            r["adjudication_id"] for r in renderer.load_adjudications(str(ADJUDICATIONS))
         )
+        self.assertEqual(listed, registered)
+
+        with staged() as stage:
+            records = stage.records()
+            extra = json.loads(json.dumps(stage.record("A-001")))
+            extra["adjudication_id"] = "A-900"
+            extra.pop("context", None)
+            records.append(extra)
+            stage.write_records(records)
+            listed = Counter(m.group("aid") for m in ENTRY_MARKER_RE.finditer(stage.build()))
+            self.assertEqual(listed["A-900"], 1)
+            self.assertEqual(sum(listed.values()), len(records))
+
+    def test_forged_marker_in_context_fields_is_rejected(self) -> None:
+        """契約 11 (経緯側): `context` へ機械マーカーを入れると RenderError。"""
+        forged = f"{renderer.ENTRY_MARKER_PREFIX} A-999 -->"
+        mutations = {
+            "title": lambda r: r["context"].__setitem__("title", "題" + forged),
+            "narrative": lambda r: r["context"].__setitem__(
+                "narrative", r["context"]["narrative"] + forged
+            ),
+            "spec_basis": lambda r: r["context"]["spec_basis"].append("AGENTS.md " + forged),
+            "reopen_condition": lambda r: r["context"].__setitem__(
+                "reopen_condition", r["context"]["reopen_condition"] + forged
+            ),
+        }
+        for name, mutate in mutations.items():
+            with self.subTest(field=name), staged() as stage:
+                stage.patch_record("A-001", mutate)
+                with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
+                    stage.build()
 
-    def test_registry_cross_reference_resolves(self) -> None:
-        """「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在する。"""
-        known = _registered_adjudication_ids()
-        dangling: list[str] = []
-        for finding_id, body in _entries():
-            registry = _field_body(body, "機械 registry")
-            if "登録済" not in registry:
+    def test_forged_marker_in_machine_fields_is_rejected(self) -> None:
+        """契約 11 (機械項目側): 出力に出る機械項目への注入も RenderError。"""
+        forged = f"{renderer.ENTRY_MARKER_PREFIX} A-999 -->"
+        mutations = {
+            "verdict": lambda r: r.__setitem__("verdict", r["verdict"] + forged),
+            "scope_kind": lambda r: r["scope"].__setitem__(
+                "scope_kind", r["scope"]["scope_kind"] + forged
+            ),
+            "scope_value": lambda r: r["scope"].__setitem__(
+                "scope_value", r["scope"]["scope_value"] + forged
+            ),
+            "source_finding_ids": lambda r: r["source_finding_ids"].__setitem__(
+                0, r["source_finding_ids"][0] + forged
+            ),
+            "adjudicated_at_run": lambda r: r.__setitem__(
+                "adjudicated_at_run", r["adjudicated_at_run"] + forged
+            ),
+            "adjudicated_at_commit": lambda r: r.__setitem__(
+                "adjudicated_at_commit", r["adjudicated_at_commit"] + forged
+            ),
+        }
+        # **理由まで固定する**。機械項目を書き換えると移行台帳の hash pin も外れるため、
+        # 単に RenderError を待つだけだと marker 検査を消してもテストが緑のままになる。
+        for name, mutate in mutations.items():
+            with self.subTest(field=name), staged() as stage:
+                stage.patch_record("A-001", mutate)
+                with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
+                    stage.build()
+        with staged() as stage:  # supersedes は A-003 が持つ
+            stage.patch_record("A-003", lambda r: r.__setitem__("supersedes", "A-002" + forged))
+            with self.assertRaisesRegex(renderer.RenderError, "書式が不正|機械マーカーの接頭辞"):
+                stage.build()
+
+    def test_newline_in_one_line_fields_is_rejected(self) -> None:
+        """契約 11-12 (改行): 出力の 1 行に出る欄はすべて CR / LF を拒否する。
+
+        改行を許すと行頭から項目境界のマーカーを偽装できる。**欄ごとに個別のケース**にして
+        あるので、1 欄でも検査が退行すればその subTest だけが赤になる
+        (`narrative` は複数行の markdown なので対象外 — 行頭が本文であって解析対象ではない)。
+        """
+        # (適用先の登録, 欄名, 値を差し込む関数)
+        injections = [
+            ("A-001", "verdict", lambda r, s: r.__setitem__("verdict", s)),
+            ("A-001", "scope.scope_kind", lambda r, s: r["scope"].__setitem__("scope_kind", s)),
+            ("A-001", "scope.scope_value", lambda r, s: r["scope"].__setitem__("scope_value", s)),
+            ("A-001", "source_finding_ids[0]",
+             lambda r, s: r["source_finding_ids"].__setitem__(0, s)),
+            ("A-001", "adjudicated_at_run", lambda r, s: r.__setitem__("adjudicated_at_run", s)),
+            ("A-001", "adjudicated_at_commit",
+             lambda r, s: r.__setitem__("adjudicated_at_commit", s)),
+            ("A-003", "supersedes", lambda r, s: r.__setitem__("supersedes", s)),
+            ("A-001", "context.title", lambda r, s: r["context"].__setitem__("title", s)),
+            ("A-001", "context.spec_basis[0]",
+             lambda r, s: r["context"]["spec_basis"].__setitem__(0, s)),
+            ("A-001", "context.reopen_condition",
+             lambda r, s: r["context"].__setitem__("reopen_condition", s)),
+        ]
+        for newline in ("\n", "\r"):
+            payload = f"前{newline}後"
+            for adjudication_id, name, inject in injections:
+                with self.subTest(field=name, newline=repr(newline)), staged() as stage:
+                    stage.patch_record(
+                        adjudication_id, lambda r, inject=inject: inject(r, payload)
+                    )
+                    # 理由まで固定する (機械項目の変更は hash pin でも落ちるため、
+                    # 単に RenderError を待つと改行検査を消しても緑のままになる)。
+                    with self.assertRaisesRegex(renderer.RenderError, "改行を含んではならない"):
+                        stage.build()
+
+    def test_identifier_with_trailing_newline_is_rejected(self) -> None:
+        """契約 11 (末尾改行): id 系の欄は**末尾の改行 1 個**も通さない。
+
+        Python の `re.match` は `$` を末尾の改行の直前にも合わせるため、
+        `"A-001\\n"` が id 検査を素通りしうる。id は機械マーカーと見出しへそのまま出るので、
+        通してしまうと `<!-- entry: A-001` の後に改行が入り、掲載の完全性が壊れる。
+        """
+        for suffix in ("\n", "\r"):
+            with self.subTest(field="adjudication_id", suffix=repr(suffix)), staged() as stage:
+                stage.patch_record(
+                    "A-001",
+                    lambda r, suffix=suffix: r.__setitem__("adjudication_id", "A-001" + suffix),
+                )
+                with self.assertRaisesRegex(
+                    renderer.RenderError, "adjudication_id の書式が不正"
+                ):
+                    stage.build()
+            with self.subTest(field="supersedes", suffix=repr(suffix)), staged() as stage:
+                stage.patch_record(
+                    "A-003",
+                    lambda r, suffix=suffix: r.__setitem__("supersedes", "A-002" + suffix),
+                )
+                # supersedes は先に「1 行に出る欄」の改行検査で捕まる (書式検査より前)。
+                with self.assertRaisesRegex(
+                    renderer.RenderError, "supersedes: 改行を含んではならない"
+                ):
+                    stage.build()
+
+    def test_entry_without_context_is_still_listed(self) -> None:
+        """契約 13: 経緯を持たない登録も掲載され、「経緯は未記入」の印が付く。"""
+        with staged() as stage:
+            records = stage.records()
+            extra = json.loads(json.dumps(stage.record("A-001")))
+            extra["adjudication_id"] = "A-901"
+            extra.pop("context", None)
+            records.append(extra)
+            stage.write_records(records)
+            blocks = _entry_blocks(stage.build())
+            self.assertIn("A-901", blocks)
+            self.assertIn(renderer.NO_CONTEXT_MARK, blocks["A-901"])
+
+    def test_active_and_superseded_are_labelled_like_the_matcher(self) -> None:
+        """契約 14: 有効性の判定が照合器の `active` 算出と一致する。"""
+        rows = v.load_jsonl(str(ADJUDICATIONS))
+        valid = [a for _, a, _ in rows if isinstance(a, dict)]
+        superseded = {a["supersedes"] for a in valid if a.get("supersedes")}
+        matcher_active = {
+            a["adjudication_id"] for a in valid if a.get("adjudication_id") not in superseded
+        }
+        records = renderer.load_adjudications(str(ADJUDICATIONS))
+        self.assertEqual(renderer.active_ids(records), matcher_active)
+
+        blocks = _entry_blocks(renderer.build())
+        for aid, body in blocks.items():
+            with self.subTest(adjudication_id=aid):
+                if aid in matcher_active:
+                    self.assertIn("有効性: **active**", body)
+                else:
+                    self.assertIn("有効性: **superseded**", body)
+
+    def test_supersede_relations_are_rendered_deterministically(self) -> None:
+        """契約 15: 同じ id を差し替える登録が 2 件あれば、両方の id が昇順で出る。"""
+        with staged() as stage:
+            records = stage.records()
+            extra = json.loads(json.dumps(stage.record("A-003")))
+            extra["adjudication_id"] = "A-004"
+            extra["supersedes"] = "A-002"
+            extra.pop("context", None)
+            records.append(extra)
+            stage.write_records(records)
+            blocks = _entry_blocks(stage.build())
+            self.assertIn("A-003 / A-004 に差し替えられた", blocks["A-002"])
+
+    def test_broken_supersede_relations_are_rejected(self) -> None:
+        """契約 16: 書式不正 / 実在しない id / 自己参照 / 循環はいずれも RenderError。"""
+        cases = {
+            "書式不正": ("A-003", "A-2", "supersedes の書式が不正"),
+            "実在しない": ("A-003", "A-777", "supersedes の指す先が無い"),
+            "自己参照": ("A-003", "A-003", "自己参照"),
+        }
+        for name, (target, value, expected) in cases.items():
+            with self.subTest(case=name), staged() as stage:
+                stage.patch_record(target, lambda r, value=value: r.__setitem__("supersedes", value))
+                with self.assertRaisesRegex(renderer.RenderError, expected):
+                    stage.build()
+        with staged() as stage:  # 循環 A-001 -> A-003 -> A-002 -> A-001
+            stage.patch_record("A-001", lambda r: r.__setitem__("supersedes", "A-003"))
+            stage.patch_record("A-002", lambda r: r.__setitem__("supersedes", "A-001"))
+            with self.assertRaisesRegex(renderer.RenderError, "循環"):
+                stage.build()
+
+    def test_ids_are_sorted_numerically(self) -> None:
+        """契約 17: 並びは id の数値順 (`A-999` < `A-1000`。文字列順ではない)。"""
+        with staged() as stage:
+            records = stage.records()
+            for new_id in ("A-1000", "A-999"):
+                extra = json.loads(json.dumps(stage.record("A-001")))
+                extra["adjudication_id"] = new_id
+                extra.pop("context", None)
+                records.append(extra)
+            stage.write_records(records)
+            listed = [m.group("aid") for m in ENTRY_MARKER_RE.finditer(stage.build())]
+            self.assertEqual(listed.index("A-999") + 1, listed.index("A-1000"))
+            self.assertEqual(listed, sorted(listed, key=lambda a: int(a.split("-")[1])))
+
+
+# =====================================================================
+# C. context の検証と fail-closed 境界 (契約 18-26)
+# =====================================================================
+class ContextValidationTest(unittest.TestCase):
+    def test_unknown_context_key_is_rejected(self) -> None:
+        """契約 18: 欄は閉じた集合 (deny-by-default)。"""
+        with staged() as stage:
+            stage.patch_record("A-001", lambda r: r["context"].__setitem__("memo", "余計な欄"))
+            with self.assertRaisesRegex(renderer.RenderError, "未知のキー"):
+                stage.build()
+
+    def test_context_field_type_and_emptiness_rejected(self) -> None:
+        """契約 19: 型と「空 / 空白だけ」を拒否する。"""
+        mutations = {
+            "title 空": lambda r: r["context"].__setitem__("title", ""),
+            "title 空白のみ": lambda r: r["context"].__setitem__("title", "   "),
+            "title 非文字列": lambda r: r["context"].__setitem__("title", 1),
+            "narrative 非文字列": lambda r: r["context"].__setitem__("narrative", ["a"]),
+            "narrative 空白のみ": lambda r: r["context"].__setitem__("narrative", " \n "),
+            "spec_basis 空配列": lambda r: r["context"].__setitem__("spec_basis", []),
+            "spec_basis 非配列": lambda r: r["context"].__setitem__("spec_basis", "AGENTS.md"),
+            "spec_basis 要素が空": lambda r: r["context"]["spec_basis"].append(""),
+            "spec_basis 要素が空白のみ": lambda r: r["context"]["spec_basis"].append("  "),
+            "spec_basis 要素が非文字列": lambda r: r["context"]["spec_basis"].append(3),
+            "reopen_condition 空": lambda r: r["context"].__setitem__("reopen_condition", ""),
+            "context 非 dict": lambda r: r.__setitem__("context", "経緯"),
+        }
+        for name, mutate in mutations.items():
+            with self.subTest(case=name), staged() as stage:
+                stage.patch_record("A-001", mutate)
+                with self.assertRaisesRegex(renderer.RenderError, "context"):
+                    stage.build()
+
+    def test_schema_broken_context_does_not_affect_the_matcher(self) -> None:
+        """契約 20: JSON として妥当なまま `context` の形だけ壊しても照合器は止まらない。"""
+        with staged() as stage:
+            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
+            errors = v.validate_adjudications(v.load_jsonl(str(stage.adjudications)))
+            self.assertEqual(errors, [])
+            with self.assertRaises(renderer.RenderError):
+                stage.build()
+
+    def test_json_syntax_error_fails_both(self) -> None:
+        """契約 21: JSONL の構文を壊した場合は照合器も従来どおり fail-closed になる。"""
+        with staged() as stage:
+            lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
+            lines.append('{"adjudication_id": "A-500"')
+            stage.write_lines(lines)
+            errors = v.validate_adjudications(v.load_jsonl(str(stage.adjudications)))
+            self.assertNotEqual(errors, [])
+            with self.assertRaisesRegex(renderer.RenderError, "JSON として読めない"):
+                stage.build()
+
+    def test_duplicate_json_keys_are_rejected(self) -> None:
+        """契約 22: 重複キーは後勝ちで黙って片方を捨てるので拒否する。"""
+        with staged() as stage:
+            lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
+            lines.append('{"adjudication_id": "A-500", "adjudication_id": "A-501"}')
+            stage.write_lines(lines)
+            with self.assertRaisesRegex(renderer.RenderError, "duplicate key"):
+                stage.build()
+
+    def test_non_finite_numbers_are_rejected(self) -> None:
+        """契約 23: NaN / Infinity / -Infinity を拒否する。"""
+        for token in ("NaN", "Infinity", "-Infinity"):
+            with self.subTest(token=token), staged() as stage:
+                lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
+                lines.append('{"adjudication_id": "A-500", "review_after_days": %s}' % token)
+                stage.write_lines(lines)
+                with self.assertRaisesRegex(renderer.RenderError, "non-finite"):
+                    stage.build()
+
+    def test_duplicate_adjudication_id_is_rejected_by_renderer(self) -> None:
+        """契約 24: 生成器は照合器が走った前提に寄りかからない。"""
+        with staged() as stage:
+            records = stage.records()
+            records.append(json.loads(json.dumps(stage.record("A-001"))))
+            stage.write_records(records)
+            with self.assertRaisesRegex(renderer.RenderError, "adjudication_id が重複"):
+                stage.build()
+
+    def test_bad_adjudication_id_form_is_rejected(self) -> None:
+        """契約 25: id は `^A-[0-9]{3,}$`。"""
+        for bad in ("A-1", "B-001", "A-001x", "", "A-001\n", "A-001\r", " A-001"):
+            with self.subTest(adjudication_id=bad), staged() as stage:
+                records = stage.records()
+                extra = json.loads(json.dumps(stage.record("A-001")))
+                extra["adjudication_id"] = bad
+                extra.pop("context", None)
+                records.append(extra)
+                stage.write_records(records)
+                with self.assertRaisesRegex(
+                    renderer.RenderError, "adjudication_id の書式が不正"
+                ):
+                    stage.build()
+
+    def test_missing_machine_field_raises_render_error_not_key_error(self) -> None:
+        """契約 26: 生成に使う機械項目の欠落は RenderError (KeyError で落とさない)。"""
+        for field in renderer.RENDERED_MACHINE_FIELDS:
+            with self.subTest(field=field), staged() as stage:
+                stage.patch_record("A-001", lambda r, field=field: r.pop(field, None))
+                with self.assertRaisesRegex(
+                    renderer.RenderError, f"機械項目 {field} が無い"
+                ):
+                    stage.build()
+
+
+# =====================================================================
+# D. 移行台帳 (契約 27-40)
+# =====================================================================
+class MigrationManifestTest(unittest.TestCase):
+    def test_migration_manifest_matches_expected_semantics(self) -> None:
+        """契約 27: 台帳の意味内容がテスト定数と完全一致する (弱める変更を赤にする)。"""
+        migration = renderer.load_migration(str(MIGRATION))
+        actual = {}
+        for entry in migration["entries"]:
+            actual[entry["key"]] = {
+                "key_kind": entry["key_kind"],
+                "target": entry["target"],
+                "field_minimums": entry["field_minimums"],
+                "required_fragments": [
+                    (f["field"], f["value"]) for f in entry["required_fragments"]
+                ],
+            }
+        self.assertEqual(actual, EXPECTED_MIGRATION)
+        self.assertEqual(migration["block_count"], EXPECTED_BLOCK_COUNT)
+        self.assertEqual(renderer.EXPECTED_BLOCK_COUNT, EXPECTED_BLOCK_COUNT)
+
+    def test_duplicate_required_fragment_is_rejected(self) -> None:
+        """契約 28: `(field, value)` の重複した台帳を拒否する。"""
+        with staged() as stage:
+            migration = stage.migration_obj()
+            fragments = migration["entries"][0]["required_fragments"]
+            fragments.append(json.loads(json.dumps(fragments[0])))
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "required_fragments が重複"):
+                stage.build()
+
+    def test_block_count_change_fails(self) -> None:
+        """契約 29 (件数の pin): `block_count` を動かすと落ちる。
+
+        **pin そのものへ到達させる**ため、entries と見出しの件数も揃えたうえで
+        pin だけが食い違う状態を作る (件数不一致で先に落ちると pin の検査を通っていない)。
+        """
+        with staged() as stage:
+            migration = stage.migration_obj()
+            extra = json.loads(json.dumps(migration["entries"][0]))
+            extra["key"] = "A-002"
+            extra["field_minimums"] = {"title": 1}
+            extra["required_fragments"] = [{"field": "title", "value": "x"}]
+            migration["entries"].append(extra)
+            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
+            migration["block_count"] = EXPECTED_BLOCK_COUNT + 1
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "ブロック数の pin"):
+                stage.build()
+
+    def test_entries_count_mismatch_fails(self) -> None:
+        """契約 29 (件数の三点一致): `entries` の件数が `block_count` と食い違えば落ちる。"""
+        with staged() as stage:
+            migration = stage.migration_obj()
+            migration["entries"] = []
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "entries の件数"):
+                stage.build()
+
+    def test_heading_count_mismatch_fails(self) -> None:
+        """契約 29 (件数の三点一致): 移行元見出しの件数が `block_count` と食い違えば落ちる。"""
+        with staged() as stage:
+            migration = stage.migration_obj()
+            migration["provenance"]["source_block_headings"].append("#### 余計な見出し")
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "移行元見出しの件数"):
+                stage.build()
+
+    def test_duplicate_key_in_manifest_fails(self) -> None:
+        """契約 30 (重複): 同じ鍵を 2 度書いた台帳を拒否する。
+
+        件数の pin より**前**に鍵の重複を見ていることまで固定する (順序が逆だと、
+        重複検出を削っても pin の失敗に隠れてテストが緑のままになる)。
+        """
+        with staged() as stage:
+            migration = stage.migration_obj()
+            migration["entries"].append(json.loads(json.dumps(migration["entries"][0])))
+            migration["block_count"] = len(migration["entries"])
+            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "key が重複"):
+                stage.build()
+
+    def test_unknown_key_does_not_resolve(self) -> None:
+        """契約 30 (解決不能): 実在しない鍵は RenderError。"""
+        with staged() as stage:
+            migration = stage.migration_obj()
+            migration["entries"][0]["key"] = "A-777"
+            stage.write_migration(migration)
+            with self.assertRaisesRegex(renderer.RenderError, "鍵が解決できない"):
+                stage.build()
+
+    def test_key_kind_and_target_vocabulary_is_closed(self) -> None:
+        """契約 31: 語彙外の値・欄名を拒否する (deny-by-default)。"""
+        mutations = {
+            "key_kind": lambda m: m["entries"][0].__setitem__("key_kind", "finding_id"),
+            "target": lambda m: m["entries"][0].__setitem__("target", "spec_notes"),
+            "field_minimums の欄名": lambda m: m["entries"][0]["field_minimums"].__setitem__(
+                "memo", 10
+            ),
+            "required_fragments の field": lambda m: m["entries"][0]["required_fragments"][0]
+            .__setitem__("field", "memo"),
+        }
+        for name, mutate in mutations.items():
+            with self.subTest(case=name), staged() as stage:
+                migration = stage.migration_obj()
+                mutate(migration)
+                stage.write_migration(migration)
+                with self.assertRaisesRegex(renderer.RenderError, "語彙外の値"):
+                    stage.build()
+
+    def test_integer_fields_reject_bool_and_non_positive(self) -> None:
+        """契約 32: 整数の欄は bool / 0 / 負 / 文字列 / null を拒否する。"""
+        bad_values = [True, 0, -1, "900", None]
+        for bad in bad_values:
+            for name, mutate, expected in (
+                ("version",
+                 lambda m, bad=bad: m.__setitem__("version", bad),
+                 "version は正の整数"),
+                ("block_count",
+                 lambda m, bad=bad: m.__setitem__("block_count", bad),
+                 "block_count は正の整数"),
+                ("field_minimums",
+                 lambda m, bad=bad: m["entries"][0]["field_minimums"].__setitem__(
+                     "narrative", bad),
+                 "field_minimums.narrative は正の整数"),
+            ):
+                with self.subTest(field=name, value=repr(bad)), staged() as stage:
+                    migration = stage.migration_obj()
+                    mutate(migration)
+                    stage.write_migration(migration)
+                    with self.assertRaisesRegex(renderer.RenderError, expected):
+                        stage.build()
+
+    def test_field_below_minimum_fails(self) -> None:
+        """契約 33: 経緯が痩せたら落ちる (欄の削除も下限割れも)。"""
+        with staged() as stage:
+            stage.patch_record("A-001", lambda r: r["context"].__setitem__("narrative", "短い経緯"))
+            with self.assertRaisesRegex(renderer.RenderError, "痩せている"):
+                stage.build()
+        with staged() as stage:
+            stage.patch_record("A-001", lambda r: r["context"].pop("reopen_condition"))
+            with self.assertRaisesRegex(renderer.RenderError, "要求する欄 reopen_condition"):
+                stage.build()
+
+    def test_required_fragment_missing_fails(self) -> None:
+        """契約 34: 必須断片が消えたら落ちる (長さだけ保った書き換えを止める)。"""
+        with staged() as stage:
+            stage.patch_record(
+                "A-001",
+                lambda r: r["context"].__setitem__(
+                    "narrative",
+                    r["context"]["narrative"].replace("feedback-probe.js", "probe-file.txt"),
+                ),
+            )
+            with self.assertRaisesRegex(renderer.RenderError, "必須の断片が無い"):
+                stage.build()
+
+    def test_fragment_is_searched_only_in_its_declared_field(self) -> None:
+        """契約 35: 宣言された欄の外にあっても一致とみなさない。"""
+        with staged() as stage:
+
+            def mutate(record: dict) -> None:
+                context = record["context"]
+                context["narrative"] = context["narrative"] + " AUTO_DISMISS_MS installed_now"
+                context["reopen_condition"] = (
+                    context["reopen_condition"]
+                    .replace("AUTO_DISMISS_MS", "自動消去の時間")
+                    .replace("installed_now", "仕込み済みか")
+                )
+
+            stage.patch_record("A-001", mutate)
+            with self.assertRaisesRegex(renderer.RenderError, "必須の断片が無い"):
+                stage.build()
+
+    def test_fragment_identifier_boundary(self) -> None:
+        """契約 36: 短い参照が長い別参照へ誤って当たらない。"""
+        self.assertFalse(renderer.fragment_present("T095", "T0950 を参照"))
+        self.assertFalse(renderer.fragment_present("T095", "xT095 を参照"))
+        self.assertFalse(renderer.fragment_present("T095", "T095-extra を参照"))
+        self.assertTrue(renderer.fragment_present("T095", "T095 の実装フェーズ"))
+        self.assertTrue(renderer.fragment_present("T095", "`T095` を参照"))
+        self.assertTrue(renderer.fragment_present("T095", "対応は T095"))
+        self.assertFalse(renderer.fragment_present("", "何か"))
+
+    def test_provenance_shape_and_heading_count(self) -> None:
+        """契約 37: 由来の必須キー・型と、見出し件数が `block_count` と一致すること。"""
+        migration = renderer.load_migration(str(MIGRATION))
+        provenance = migration["provenance"]
+        for key in renderer.PROVENANCE_KEYS:
+            self.assertIn(key, provenance)
+        headings = provenance["source_block_headings"]
+        self.assertEqual(len(headings), migration["block_count"])
+        self.assertEqual(len(set(headings)), len(headings))
+        self.assertTrue(all(isinstance(h, str) and h.strip() for h in headings))
+
+        def _duplicate_headings(migration: dict) -> None:
+            """見出しを重複させる。件数の突き合わせより前に一意性を見ていることも固定する。"""
+            head = migration["provenance"]["source_block_headings"][0]
+            migration["provenance"]["source_block_headings"] = [head, head]
+
+        mutations = {
+            "必須キー欠落": (lambda m: m["provenance"].pop("note"), "provenance.note"),
+            "見出しが空白のみ": (
+                lambda m: m["provenance"].__setitem__("source_block_headings", ["  "]),
+                "非空文字列の配列",
+            ),
+            "見出しの重複": (_duplicate_headings, "source_block_headings に重複"),
+            "hash の書式不正": (
+                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
+                    "A-001", "短すぎる"
+                ),
+                "64 桁 hex",
+            ),
+            "hash に末尾改行": (
+                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
+                    "A-001", "0" * 64 + "\n"
+                ),
+                "64 桁 hex",
+            ),
+            "hash の鍵に末尾改行": (
+                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
+                    "A-002\n", "0" * 64
+                ),
+                "adjudication_id ではない",
+            ),
+            "hash の鍵が id でない": (
+                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
+                    "F-1-02", "0" * 64
+                ),
+                "adjudication_id ではない",
+            ),
+        }
+        for name, (mutate, expected) in mutations.items():
+            with self.subTest(case=name), staged() as stage:
+                migration = stage.migration_obj()
+                mutate(migration)
+                stage.write_migration(migration)
+                with self.assertRaisesRegex(renderer.RenderError, expected):
+                    stage.build()
+
+    def test_machine_projection_sha256_is_pinned_in_three_places(self) -> None:
+        """契約 38: テスト定数 / 移行台帳 / 現在の登録の三点で一致する。"""
+        migration = renderer.load_migration(str(MIGRATION))
+        pinned = migration["provenance"]["machine_projection_sha256"]
+        self.assertEqual(pinned, EXPECTED_MACHINE_PROJECTION_SHA256)
+        records = {
+            r["adjudication_id"]: r for r in renderer.load_adjudications(str(ADJUDICATIONS))
+        }
+        for adjudication_id, expected in EXPECTED_MACHINE_PROJECTION_SHA256.items():
+            with self.subTest(adjudication_id=adjudication_id):
+                self.assertEqual(
+                    renderer.canonical_machine_projection(records[adjudication_id]), expected
+                )
+
+    def test_machine_field_change_turns_red(self) -> None:
+        """契約 39: 機械項目を書き換え、台帳の hash も同時に更新しても赤になる。"""
+        with staged() as stage:
+            stage.patch_record("A-001", lambda r: r.__setitem__("review_after_days", 90))
+            mutated = {
+                r["adjudication_id"]: r for r in renderer.load_adjudications(str(stage.adjudications))
+            }["A-001"]
+            recomputed = renderer.canonical_machine_projection(mutated)
+            migration = stage.migration_obj()
+            migration["provenance"]["machine_projection_sha256"]["A-001"] = recomputed
+            stage.write_migration(migration)
+            # 台帳側の hash を合わせたので生成は通る。しかしテスト定数とは食い違う。
+            stage.build()
+            self.assertNotEqual(recomputed, EXPECTED_MACHINE_PROJECTION_SHA256["A-001"])
+
+    def test_manifest_shape_is_rejected_when_not_a_single_object(self) -> None:
+        """契約 40: 配列 / 不在ファイルを拒否する。"""
+        with staged() as stage:
+            stage.write_migration_text("[]\n")
+            with self.assertRaisesRegex(renderer.RenderError, "単一の object"):
+                stage.build()
+        with staged() as stage:
+            stage.migration.unlink()
+            with self.assertRaisesRegex(renderer.RenderError, "移行台帳が無い"):
+                stage.build()
+        with staged() as stage:
+            stage.adjudications.unlink()
+            with self.assertRaisesRegex(renderer.RenderError, "裁定登録が無い"):
+                stage.build()
+
+
+# =====================================================================
+# E. 既存方針の継承 / 構造的保証 (契約 41-43)
+# =====================================================================
+class SpecBasisAndIsolationTest(unittest.TestCase):
+    def test_spec_basis_references_are_well_formed_and_exist(self) -> None:
+        """契約 41: 根拠は全要素が所定形式で、リポジトリ内の通常ファイルを指す。"""
+        problems: list[str] = []
+        for record in renderer.load_adjudications(str(ADJUDICATIONS)):
+            context = record.get("context")
+            if not context:
                 continue
-            for adjudication_id in ADJ_ID_RE.findall(registry):
-                if adjudication_id not in known:
-                    dangling.append(
-                        f"{finding_id}: {adjudication_id} が adjudications.jsonl に無い"
+            for reference in context["spec_basis"]:
+                problem = _spec_basis_problem(reference, REPO_ROOT)
+                if problem is not None:
+                    problems.append(f"{record['adjudication_id']}: {problem}")
+        self.assertEqual(problems, [], "context.spec_basis が腐っている:\n" + "\n".join(problems))
+
+    def test_spec_basis_extension_vocabulary_is_pinned(self) -> None:
+        """契約 41 (拡張子の閉じた集合): 許す拡張子と拒む拡張子を両側から固定する。
+
+        集合を黙って広げると「書式を外して実在検査から逃げる」余地が増えるため、
+        許可側は完全一致で pin し、代表的な拒否例も明示する。
+        """
+        with tempfile.TemporaryDirectory() as root:
+            root_path = Path(root)
+            for extension in SPEC_BASIS_EXTENSIONS:
+                target = root_path / f"sample.{extension}"
+                target.write_text("x\n", encoding="utf-8")
+                with self.subTest(extension=extension, allowed=True):
+                    self.assertIsNone(_spec_basis_problem(f"sample.{extension} 説明", root_path))
+            for extension in ("txt", "tsx", "jsx", "png", "lock", "csv"):
+                target = root_path / f"sample.{extension}"
+                target.write_text("x\n", encoding="utf-8")
+                with self.subTest(extension=extension, allowed=False):
+                    self.assertIsNotNone(
+                        _spec_basis_problem(f"sample.{extension} 説明", root_path)
                     )
-        self.assertEqual(
-            dangling,
-            [],
-            "spec-ledger.md と機械 registry の相互参照が切れている:\n"
-            + "\n".join(dangling),
-        )
+
+    def test_spec_basis_rejects_traversal_and_escape(self) -> None:
+        """契約 42: 絶対パス / `..` / symlink 脱出 / 書式不正の 4 ケースが失敗する。"""
+        with tempfile.TemporaryDirectory() as outside, tempfile.TemporaryDirectory() as root:
+            root_path, outside_path = Path(root), Path(outside)
+            (outside_path / "secret.md").write_text("外部\n", encoding="utf-8")
+            (root_path / "inside.md").write_text("内部\n", encoding="utf-8")
+            os.symlink(outside_path, root_path / "escape")
+
+            self.assertIsNone(_spec_basis_problem("inside.md 説明", root_path))
+            for reference in (
+                "/etc/passwd.md 絶対パス",
+                "../outside/secret.md 親参照",
+                "escape/secret.md symlink 脱出",
+                "not-a-path 書式不正",
+                "inside.txt 拡張子が対象外",
+            ):
+                with self.subTest(reference=reference):
+                    self.assertIsNotNone(_spec_basis_problem(reference, root_path))
+
+    def test_matcher_source_never_names_the_handover_files(self) -> None:
+        """契約 43: 照合器は申し送りの生成物・生成器・その入力を 1 語も知らない。"""
+        source = MATCHER_SOURCE.read_text(encoding="utf-8")
+        for token in ("spec-ledger", "spec_ledger", "render_spec_ledger", "spec-notes"):
+            with self.subTest(token=token):
+                self.assertNotIn(token, source)
 
 
 if __name__ == "__main__":

```

## 検証コマンドの実測結果 (AGENTS.md の一式。**全 green**)

```
composer test            → pest passed / tests 5770 / passed 5768 / skipped 2 / assertions 25293
composer phpstan         → No errors (988 files, level 10)
vendor/bin/pint --test   → passed
pnpm lint                → 完走 (指摘なし)
pnpm typecheck           → 完走 (エラーなし)
pnpm test                → Test Files 160 passed (160) / Tests 2007 passed (2007)
pnpm build               → built in 5.76s
pnpm typecheck:packages  → 完走 (エラーなし)
pnpm build:packages      → 完走 (エラーなし)
pnpm test:packages       → Test Files 10 passed (10) / Tests 106 passed (106)

本タスク固有:
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
                         → Ran 118 tests / OK (既存 67 + 本タスク 51)
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   → exit 0
python3 ledger/validate_findings.py ledger/example.findings.jsonl \
        --adjudications ledger/adjudications.jsonl
                         → findings 4 / valid 4 / errors 0 / adjudications 3 / invalid 0
bash scripts/bug-hunt-inventory-check.sh
                         → 一致: 画面 71 件 / 操作 79 件 (exit 0)
```

## 重点的に見てほしい点

1. `fullmatch` への統一で、**まだ `match` のままの照合が残っていないか**。
2. `assertRaisesRegex` への引き上げで、**期待メッセージと生成器の実メッセージがずれていて
   別の理由で偶然一致している**ものが無いか。
3. 変異試験の 3 件で足りているか (足すべき変異があれば具体的に指摘してほしい)。

## 出力形式

Round 1 / 2 と同じ。ファイルごとの判定 → [Critical] / [Warning] / [Suggestion] の指摘 →
最後に **APPROVED** または **CHANGES_REQUESTED** の 1 語。
