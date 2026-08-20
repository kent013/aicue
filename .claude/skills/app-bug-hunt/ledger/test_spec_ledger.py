"""spec-ledger.md (生成物) の契約テスト (stdlib のみ)。

`spec-ledger.md` は **生成物**であり、入力は 2 つ —
`ledger/adjudications.jsonl` (登録一覧と、各登録の `context` に書かれた経緯) と
`ledger/spec-ledger-migration.json` (手書き時代の申し送りが痩せずに移ったことの検査) である。

本テストが固定するのは次の 5 群である:

  A. 生成物であること (再生成の一致・手編集の検出・原子的書き込み)
  B. 掲載の完全性 (登録は 1 件残らずちょうど 1 回載る。機械マーカーで数える)
  C. `context` の形と、照合器 (`validate_findings.py`) との fail-closed 境界
  D. 移行台帳 (痩せ・断片の欠落・台帳自身を弱める変更の検出)
  E. 既存方針の継承 (根拠パスの実在・生成器が照合器から隔離されていること)

**保証しないもの**: これらは CI では 1 つも走らない。人が
`python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` か
`render_spec_ledger.py --check` を走らせたときにだけ腐りが分かる。
経緯の**内容が正しいこと**も機械は見ていない (形・全数性・痩せ・drift だけを見る)。

実行: python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
"""

from __future__ import annotations

import contextlib
import io
import json
import os
import re
import shutil
import tempfile
import unittest
from collections import Counter
from pathlib import Path
from unittest import mock

import render_spec_ledger as renderer
import validate_findings as v

LEDGER_DIR = Path(__file__).resolve().parent
SKILL_ROOT = LEDGER_DIR.parent
# .claude/skills/app-bug-hunt -> parents[0]=skills / parents[1]=.claude / parents[2]=リポジトリルート。
REPO_ROOT = SKILL_ROOT.parents[2]
SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"
MIGRATION = LEDGER_DIR / "spec-ledger-migration.json"
MATCHER_SOURCE = LEDGER_DIR / "validate_findings.py"

ENTRY_MARKER_RE = re.compile(r"^<!-- entry: (?P<aid>A-[0-9]+) -->$", re.MULTILINE)

# 移行台帳の期待値。**台帳自身を弱める変更を赤にする**ための意図した二重管理である
# (台帳だけに置くと、断片や下限を消す変更が台帳の書き換えだけで通ってしまう)。
EXPECTED_MIGRATION = {
    "A-001": {
        "key_kind": "adjudication_id",
        "target": "adjudications",
        "field_minimums": {"narrative": 437, "reopen_condition": 230},
        "required_fragments": [
            ("narrative", "feedback-probe.js"),
            ("narrative", "T095"),
            ("reopen_condition", "AUTO_DISMISS_MS"),
            ("reopen_condition", "installed_now"),
        ],
    },
}
EXPECTED_BLOCK_COUNT = 1

# 移行時点の「機械項目だけの射影」の sha256。移行台帳・現在の登録と**三点**で突き合わせる
# (二点だと、機械項目を書き換えると同時に台帳の hash を更新すれば通ってしまう)。
EXPECTED_MACHINE_PROJECTION_SHA256 = {
    "A-001": "e873bfdd2e4a90400788577ddbf90db51c853b5583be3a0f0ad03b1cd5ca39b6",
    "A-002": "1116927afad77292d301cb2cca57d0370b23cfd9ac616f94e751af796b9b4ad9",
    "A-003": "a96092441ecc66054c11c2eecf846cc4949f6ecfc1a634105e3a59e0431b7fae",
}

# 根拠 (`context.spec_basis`) の 1 要素の先頭トークンの書式。
# 位置指定 (`:230-232`) とアンカー (`#見出し`) は任意で、実在検査では捨てる。
#
# 拡張子は**閉じた集合**である。詳細設計が列挙した 9 種に `jsonl` を 1 つだけ足した
# (A-003 の根拠が run 成果物 `findings-merged.jsonl` を指すため。`json` だけだと
# 末尾の `l` が余って書式不正になり、実在する根拠が失敗扱いになる)。
# **これ以外は増やさない** — 集合を広げるほど「書式を外して検査から逃げる」余地が増える。
SPEC_BASIS_EXTENSIONS = (
    "php", "ts", "js", "svelte", "md", "jsonl", "json", "yaml", "yml", "py", "sh",
)
# 正規表現は**この定数から組み立てる**。別々に手書きすると、式だけを広げても
# 拒否例に無い拡張子は全テストが緑のまま通ってしまう (許可側の pin にならない)。
# 長い順に並べるのは `jsonl` が `json` に食われないようにするため。
_SPEC_BASIS_EXT_ALTERNATION = "|".join(
    re.escape(extension)
    for extension in sorted(SPEC_BASIS_EXTENSIONS, key=len, reverse=True)
)
SPEC_BASIS_FORM_RE = re.compile(
    rf"(?P<path>[\w./-]+\.(?:{_SPEC_BASIS_EXT_ALTERNATION}))(?:[:#][\w.\-#]*)*"
)


def setUpModule() -> None:
    """前提確認: REPO_ROOT の数え方が正しいこと。

    ここを間違えると根拠パスの実在検査が別ディレクトリを見て全件緑になってしまう。
    """
    if not (REPO_ROOT / "AGENTS.md").is_file():
        raise AssertionError(f"REPO_ROOT の導出が誤っている: {REPO_ROOT}")


def _spec_basis_problem(reference: str, repo_root: Path) -> str | None:
    """根拠 1 要素の問題点を返す (無ければ None)。

    形式不正は「対象外」ではなく**失敗**として扱う (書式を外せば検査から逃げられるため)。
    行番号は見ない (通常のリファクタで台帳テストが壊れる保守負債を作らないため)。
    """
    tokens = reference.split()
    if not tokens:
        return "空の根拠"
    token = tokens[0]
    # fullmatch を使う (`match` + `$` は末尾の改行 1 個を通してしまう)。
    matched = SPEC_BASIS_FORM_RE.fullmatch(token)
    if matched is None:
        return f"書式不正: {token!r}"
    path = matched.group("path")
    if path.startswith("/"):
        return f"絶対パス: {path!r}"
    if ".." in path.split("/"):
        return f"親ディレクトリ参照: {path!r}"
    root = repo_root.resolve()
    resolved = (root / path).resolve()
    if root != resolved and root not in resolved.parents:
        return f"リポジトリ外へ脱出: {path!r}"
    if not resolved.is_file():
        return f"実在しない (または通常ファイルでない): {path!r}"
    return None


def _entry_blocks(text: str) -> dict[str, str]:
    """機械マーカーで区切った項目本文の辞書 {adjudication_id: 本文}。"""
    blocks: dict[str, str] = {}
    positions = [(m.group("aid"), m.start(), m.end()) for m in ENTRY_MARKER_RE.finditer(text)]
    for index, (aid, _start, end) in enumerate(positions):
        stop = positions[index + 1][1] if index + 1 < len(positions) else len(text)
        blocks[aid] = text[end:stop]
    return blocks


def _unused_adjudication_id(records: list[dict]) -> str:
    """現物の登録に無い `A-NNN` を 1 つ作る (実登録の増減で合成テストが衝突しないため)。"""
    used = {r.get("adjudication_id") for r in records}
    n = 1
    while f"A-{n:03d}" in used:
        n += 1
    return f"A-{n:03d}"


class _Stage:
    """入力 2 点の写しを持つ一時作業場。**現物は絶対に書き換えない**。"""

    def __init__(self, directory: Path) -> None:
        self.dir = directory
        self.adjudications = directory / "adjudications.jsonl"
        self.migration = directory / "spec-ledger-migration.json"
        self.output = directory / "spec-ledger.md"
        shutil.copy2(ADJUDICATIONS, self.adjudications)
        shutil.copy2(MIGRATION, self.migration)

    # --- 入力の読み書き -------------------------------------------------
    def records(self) -> list[dict]:
        out = []
        for raw in self.adjudications.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            out.append(json.loads(line))
        return out

    def record(self, adjudication_id: str) -> dict:
        for record in self.records():
            if record.get("adjudication_id") == adjudication_id:
                return record
        raise AssertionError(f"登録が無い: {adjudication_id}")

    def write_records(self, records: list[dict]) -> None:
        lines = [json.dumps(r, ensure_ascii=False, sort_keys=False) for r in records]
        self.adjudications.write_text("\n".join(lines) + "\n", encoding="utf-8")

    def write_lines(self, lines: list[str]) -> None:
        self.adjudications.write_text("\n".join(lines) + "\n", encoding="utf-8")

    def patch_record(self, adjudication_id: str, mutate) -> None:
        records = self.records()
        for record in records:
            if record.get("adjudication_id") == adjudication_id:
                mutate(record)
        self.write_records(records)

    def migration_obj(self) -> dict:
        return json.loads(self.migration.read_text(encoding="utf-8"))

    def write_migration(self, obj) -> None:
        self.migration.write_text(
            json.dumps(obj, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )

    def write_migration_text(self, text: str) -> None:
        self.migration.write_text(text, encoding="utf-8")

    # --- 生成 -----------------------------------------------------------
    def build(self) -> str:
        return renderer.build(
            adjudications_path=str(self.adjudications),
            migration_path=str(self.migration),
        )

    def cli(self, *args: str) -> tuple[int, str, str]:
        argv = [
            "--adjudications", str(self.adjudications),
            "--migration", str(self.migration),
            "--output", str(self.output),
            *args,
        ]
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = renderer.main(argv)
        return code, out.getvalue(), err.getvalue()

    def seed_output(self, text: str = "sentinel\n") -> str:
        """出力位置に見張り用の中身を置き、その sha256 を返す。"""
        self.output.write_text(text, encoding="utf-8")
        return self.output_sha()

    def output_sha(self) -> str:
        """**バイト列**の sha256 (テキストで読み直すと改行の変化を見逃す)。"""
        return renderer.sha256_of_bytes(self.output.read_bytes())

    def temp_files(self) -> list[Path]:
        return sorted(self.dir.glob(".spec-ledger.*.tmp"))


@contextlib.contextmanager
def staged():
    with tempfile.TemporaryDirectory() as tmp:
        yield _Stage(Path(tmp))


# =====================================================================
# A. 生成物であること (契約 1-9)
# =====================================================================
class GeneratedArtifactTest(unittest.TestCase):
    def test_generated_output_matches_committed_file(self) -> None:
        """契約 1: 生成結果が現物と byte 一致する (再生成忘れの検出)。

        比較は**バイト列**で行う。`read_text()` は CRLF を LF へ畳むため、
        改行だけ変えた差分を「一致」と誤判定する。
        """
        self.assertEqual(SPEC_LEDGER.read_bytes(), renderer.build().encode("utf-8"))

    def test_check_passes_on_committed_file(self) -> None:
        """契約 2: `--check` は現物に対して exit 0。"""
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = renderer.main(["--check"])
        self.assertEqual(code, 0, err.getvalue())

    def test_manual_edit_is_detected(self) -> None:
        """契約 3: 手編集は exit 1 で検出し、stderr に再生成コマンドを出す。"""
        with staged() as stage:
            stage.output.write_text(stage.build(), encoding="utf-8")
            self.assertEqual(stage.cli("--check")[0], 0)
            edited = stage.output.read_text(encoding="utf-8").replace("有効性", "有効生")
            stage.output.write_text(edited, encoding="utf-8")
            code, _out, err = stage.cli("--check")
            self.assertEqual(code, 1)
            self.assertIn(renderer.REGENERATE_COMMAND, err)

    def test_newline_only_edit_is_detected(self) -> None:
        """契約 3 (改行だけの差分): 中身が同じでも改行コードが変われば exit 1。

        文字列として比べると CRLF が LF に畳まれて素通りする経路を塞ぐ。
        """
        with staged() as stage:
            text = stage.build()
            stage.output.write_bytes(text.encode("utf-8"))
            self.assertEqual(stage.cli("--check")[0], 0)
            stage.output.write_bytes(text.replace("\n", "\r\n").encode("utf-8"))
            code, _out, err = stage.cli("--check")
            self.assertEqual(code, 1)
            self.assertIn(renderer.REGENERATE_COMMAND, err)

    def test_check_fails_when_output_is_absent(self) -> None:
        """契約 4: 出力が無ければ `--check` は exit 1。"""
        with staged() as stage:
            self.assertFalse(stage.output.exists())
            self.assertEqual(stage.cli("--check")[0], 1)

    def test_render_is_atomic_on_input_validation_failure(self) -> None:
        """契約 5: 入力が壊れていても既存の出力は 1 バイトも変わらない。"""
        with staged() as stage:
            before = stage.seed_output()
            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
            code, _out, err = stage.cli()
            self.assertEqual(code, 1, err)
            self.assertEqual(stage.output_sha(), before)
            self.assertEqual(stage.temp_files(), [])

    def test_render_is_atomic_when_replace_fails(self) -> None:
        """契約 6: 置換が失敗しても既存の出力は変わらない (障害注入)。"""
        with staged() as stage:
            before = stage.seed_output()
            with mock.patch.object(renderer.os, "replace", side_effect=OSError("replace 失敗")):
                with self.assertRaises(OSError):
                    renderer.write_atomically("新しい中身\n", str(stage.output))
            self.assertEqual(stage.output_sha(), before)
            self.assertEqual(stage.temp_files(), [])

    def test_render_is_atomic_when_write_fails(self) -> None:
        """契約 7 (書き込み経路): 一時ファイルへの書き込み失敗でも出力は変わらない。"""

        class _ExplodingFile:
            def __init__(self, fd: int) -> None:
                self._fd = fd

            def __enter__(self):
                return self

            def __exit__(self, *_args) -> bool:
                os.close(self._fd)
                return False

            def write(self, _text: str) -> int:
                raise OSError("write 失敗")

        with staged() as stage:
            before = stage.seed_output()
            with mock.patch.object(renderer.os, "fdopen", lambda fd, *a, **k: _ExplodingFile(fd)):
                with self.assertRaises(OSError):
                    renderer.write_atomically("新しい中身\n", str(stage.output))
            self.assertEqual(stage.output_sha(), before)
            self.assertEqual(stage.temp_files(), [])

    def test_render_is_atomic_when_chmod_fails(self) -> None:
        """契約 7 (mode 設定経路): chmod 失敗でも出力は変わらない。"""
        with staged() as stage:
            before = stage.seed_output()
            with mock.patch.object(renderer.os, "chmod", side_effect=OSError("chmod 失敗")):
                with self.assertRaises(OSError):
                    renderer.write_atomically("新しい中身\n", str(stage.output))
            self.assertEqual(stage.output_sha(), before)
            self.assertEqual(stage.temp_files(), [])

    def test_render_leaves_no_temp_file_behind(self) -> None:
        """契約 8: 3 経路すべての失敗の後に一時ファイルが残らない。"""
        with staged() as stage:
            stage.seed_output()
            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
            stage.cli()
            self.assertEqual(stage.temp_files(), [])
            for target, kwargs in (
                ("replace", {"side_effect": OSError("x")}),
                ("chmod", {"side_effect": OSError("x")}),
            ):
                with mock.patch.object(renderer.os, target, **kwargs):
                    with self.assertRaises(OSError):
                        renderer.write_atomically("中身\n", str(stage.output))
                self.assertEqual(stage.temp_files(), [])

    def test_output_mode_is_preserved_or_0644(self) -> None:
        """契約 9: 既存出力の mode を保ち、新規出力は 0644 (mkstemp の 0600 を引き継がない)。"""
        with staged() as stage:
            stage.output.write_text("見張り\n", encoding="utf-8")
            os.chmod(stage.output, 0o640)
            renderer.write_atomically("中身\n", str(stage.output))
            self.assertEqual(stage.output.stat().st_mode & 0o777, 0o640)

            fresh = stage.dir / "new-spec-ledger.md"
            renderer.write_atomically("中身\n", str(fresh))
            self.assertEqual(fresh.stat().st_mode & 0o777, 0o644)


# =====================================================================
# B. 掲載の完全性 (契約 10-17)
# =====================================================================
class ListingCompletenessTest(unittest.TestCase):
    def test_every_adjudication_id_is_listed_exactly_once(self) -> None:
        """契約 10: 機械マーカーの多重集合が登録の id 集合と一致し、各 1 回。"""
        text = renderer.build()
        listed = Counter(m.group("aid") for m in ENTRY_MARKER_RE.finditer(text))
        registered = Counter(
            r["adjudication_id"] for r in renderer.load_adjudications(str(ADJUDICATIONS))
        )
        self.assertEqual(listed, registered)

        with staged() as stage:
            records = stage.records()
            extra = json.loads(json.dumps(stage.record("A-001")))
            extra["adjudication_id"] = "A-900"
            extra.pop("context", None)
            records.append(extra)
            stage.write_records(records)
            listed = Counter(m.group("aid") for m in ENTRY_MARKER_RE.finditer(stage.build()))
            self.assertEqual(listed["A-900"], 1)
            self.assertEqual(sum(listed.values()), len(records))

    def test_forged_marker_in_context_fields_is_rejected(self) -> None:
        """契約 11 (経緯側): `context` へ機械マーカーを入れると RenderError。"""
        forged = f"{renderer.ENTRY_MARKER_PREFIX} A-999 -->"
        mutations = {
            "title": lambda r: r["context"].__setitem__("title", "題" + forged),
            "narrative": lambda r: r["context"].__setitem__(
                "narrative", r["context"]["narrative"] + forged
            ),
            "spec_basis": lambda r: r["context"]["spec_basis"].append("AGENTS.md " + forged),
            "reopen_condition": lambda r: r["context"].__setitem__(
                "reopen_condition", r["context"]["reopen_condition"] + forged
            ),
        }
        for name, mutate in mutations.items():
            with self.subTest(field=name), staged() as stage:
                stage.patch_record("A-001", mutate)
                with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
                    stage.build()

    def test_forged_marker_in_machine_fields_is_rejected(self) -> None:
        """契約 11 (機械項目側): 出力に出る機械項目への注入も RenderError。"""
        forged = f"{renderer.ENTRY_MARKER_PREFIX} A-999 -->"
        mutations = {
            "verdict": lambda r: r.__setitem__("verdict", r["verdict"] + forged),
            "scope_kind": lambda r: r["scope"].__setitem__(
                "scope_kind", r["scope"]["scope_kind"] + forged
            ),
            "scope_value": lambda r: r["scope"].__setitem__(
                "scope_value", r["scope"]["scope_value"] + forged
            ),
            "source_finding_ids": lambda r: r["source_finding_ids"].__setitem__(
                0, r["source_finding_ids"][0] + forged
            ),
            "adjudicated_at_run": lambda r: r.__setitem__(
                "adjudicated_at_run", r["adjudicated_at_run"] + forged
            ),
            "adjudicated_at_commit": lambda r: r.__setitem__(
                "adjudicated_at_commit", r["adjudicated_at_commit"] + forged
            ),
        }
        # **理由まで固定する**。機械項目を書き換えると移行台帳の hash pin も外れるため、
        # 単に RenderError を待つだけだと marker 検査を消してもテストが緑のままになる。
        for name, mutate in mutations.items():
            with self.subTest(field=name), staged() as stage:
                stage.patch_record("A-001", mutate)
                with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
                    stage.build()
        with staged() as stage:  # supersedes は A-003 が持つ
            stage.patch_record("A-003", lambda r: r.__setitem__("supersedes", "A-002" + forged))
            # 期待は marker 検査**だけ**に絞る。`書式が不正` を許すと、marker 検査を外しても
            # 後段の supersede 書式検査が同じ入力を捕まえてテストが緑のままになる。
            with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
                stage.build()

    def test_newline_in_one_line_fields_is_rejected(self) -> None:
        """契約 11-12 (改行): 出力の 1 行に出る欄はすべて CR / LF を拒否する。

        改行を許すと行頭から項目境界のマーカーを偽装できる。**欄ごとに個別のケース**にして
        あるので、1 欄でも検査が退行すればその subTest だけが赤になる
        (`narrative` は複数行の markdown なので対象外 — 行頭が本文であって解析対象ではない)。
        """
        # (適用先の登録, 欄名, 値を差し込む関数)
        injections = [
            ("A-001", "verdict", lambda r, s: r.__setitem__("verdict", s)),
            ("A-001", "scope.scope_kind", lambda r, s: r["scope"].__setitem__("scope_kind", s)),
            ("A-001", "scope.scope_value", lambda r, s: r["scope"].__setitem__("scope_value", s)),
            ("A-001", "source_finding_ids[0]",
             lambda r, s: r["source_finding_ids"].__setitem__(0, s)),
            ("A-001", "adjudicated_at_run", lambda r, s: r.__setitem__("adjudicated_at_run", s)),
            ("A-001", "adjudicated_at_commit",
             lambda r, s: r.__setitem__("adjudicated_at_commit", s)),
            ("A-003", "supersedes", lambda r, s: r.__setitem__("supersedes", s)),
            ("A-001", "context.title", lambda r, s: r["context"].__setitem__("title", s)),
            ("A-001", "context.spec_basis[0]",
             lambda r, s: r["context"]["spec_basis"].__setitem__(0, s)),
            ("A-001", "context.reopen_condition",
             lambda r, s: r["context"].__setitem__("reopen_condition", s)),
        ]
        for newline in ("\n", "\r"):
            payload = f"前{newline}後"
            for adjudication_id, name, inject in injections:
                with self.subTest(field=name, newline=repr(newline)), staged() as stage:
                    stage.patch_record(
                        adjudication_id, lambda r, inject=inject: inject(r, payload)
                    )
                    # 理由まで固定する (機械項目の変更は hash pin でも落ちるため、
                    # 単に RenderError を待つと改行検査を消しても緑のままになる)。
                    with self.assertRaisesRegex(renderer.RenderError, "改行を含んではならない"):
                        stage.build()

    def test_identifier_with_trailing_newline_is_rejected(self) -> None:
        """契約 11 (末尾改行): id 系の欄は**末尾の改行 1 個**も通さない。

        Python の `re.match` は `$` を末尾の改行の直前にも合わせるため、
        `"A-001\\n"` が id 検査を素通りしうる。id は機械マーカーと見出しへそのまま出るので、
        通してしまうと `<!-- entry: A-001` の後に改行が入り、掲載の完全性が壊れる。
        """
        for suffix in ("\n", "\r"):
            with self.subTest(field="adjudication_id", suffix=repr(suffix)), staged() as stage:
                stage.patch_record(
                    "A-001",
                    lambda r, suffix=suffix: r.__setitem__("adjudication_id", "A-001" + suffix),
                )
                with self.assertRaisesRegex(
                    renderer.RenderError, "adjudication_id の書式が不正"
                ):
                    stage.build()
            with self.subTest(field="supersedes", suffix=repr(suffix)), staged() as stage:
                stage.patch_record(
                    "A-003",
                    lambda r, suffix=suffix: r.__setitem__("supersedes", "A-002" + suffix),
                )
                # supersedes は先に「1 行に出る欄」の改行検査で捕まる (書式検査より前)。
                with self.assertRaisesRegex(
                    renderer.RenderError, "supersedes: 改行を含んではならない"
                ):
                    stage.build()

    def test_entry_without_context_is_still_listed(self) -> None:
        """契約 13: 経緯を持たない登録も掲載され、「経緯は未記入」の印が付く。"""
        with staged() as stage:
            records = stage.records()
            extra = json.loads(json.dumps(stage.record("A-001")))
            extra["adjudication_id"] = "A-901"
            extra.pop("context", None)
            records.append(extra)
            stage.write_records(records)
            blocks = _entry_blocks(stage.build())
            self.assertIn("A-901", blocks)
            self.assertIn(renderer.NO_CONTEXT_MARK, blocks["A-901"])

    def test_active_and_superseded_are_labelled_like_the_matcher(self) -> None:
        """契約 14: 有効性の判定が照合器の `active` 算出と一致する。"""
        rows = v.load_jsonl(str(ADJUDICATIONS))
        valid = [a for _, a, _ in rows if isinstance(a, dict)]
        superseded = {a["supersedes"] for a in valid if a.get("supersedes")}
        matcher_active = {
            a["adjudication_id"] for a in valid if a.get("adjudication_id") not in superseded
        }
        records = renderer.load_adjudications(str(ADJUDICATIONS))
        self.assertEqual(renderer.active_ids(records), matcher_active)

        blocks = _entry_blocks(renderer.build())
        for aid, body in blocks.items():
            with self.subTest(adjudication_id=aid):
                if aid in matcher_active:
                    self.assertIn("有効性: **active**", body)
                else:
                    self.assertIn("有効性: **superseded**", body)

    def test_supersede_relations_are_rendered_deterministically(self) -> None:
        """契約 15: 同じ id を差し替える登録が 2 件あれば、両方の id が昇順で出る。"""
        with staged() as stage:
            records = stage.records()
            new_id = _unused_adjudication_id(records)
            extra = json.loads(json.dumps(stage.record("A-003")))
            extra["adjudication_id"] = new_id
            extra["supersedes"] = "A-002"
            extra.pop("context", None)
            records.append(extra)
            stage.write_records(records)
            blocks = _entry_blocks(stage.build())
            self.assertIn(f"A-003 / {new_id} に差し替えられた", blocks["A-002"])

    def test_broken_supersede_relations_are_rejected(self) -> None:
        """契約 16: 書式不正 / 実在しない id / 自己参照 / 循環はいずれも RenderError。"""
        cases = {
            "書式不正": ("A-003", "A-2", "supersedes の書式が不正"),
            "実在しない": ("A-003", "A-777", "supersedes の指す先が無い"),
            "自己参照": ("A-003", "A-003", "自己参照"),
        }
        for name, (target, value, expected) in cases.items():
            with self.subTest(case=name), staged() as stage:
                stage.patch_record(target, lambda r, value=value: r.__setitem__("supersedes", value))
                with self.assertRaisesRegex(renderer.RenderError, expected):
                    stage.build()
        with staged() as stage:  # 循環 A-001 -> A-003 -> A-002 -> A-001
            stage.patch_record("A-001", lambda r: r.__setitem__("supersedes", "A-003"))
            stage.patch_record("A-002", lambda r: r.__setitem__("supersedes", "A-001"))
            with self.assertRaisesRegex(renderer.RenderError, "循環"):
                stage.build()

    def test_ids_are_sorted_numerically(self) -> None:
        """契約 17: 並びは id の数値順 (`A-999` < `A-1000`。文字列順ではない)。"""
        with staged() as stage:
            records = stage.records()
            for new_id in ("A-1000", "A-999"):
                extra = json.loads(json.dumps(stage.record("A-001")))
                extra["adjudication_id"] = new_id
                extra.pop("context", None)
                records.append(extra)
            stage.write_records(records)
            listed = [m.group("aid") for m in ENTRY_MARKER_RE.finditer(stage.build())]
            self.assertEqual(listed.index("A-999") + 1, listed.index("A-1000"))
            self.assertEqual(listed, sorted(listed, key=lambda a: int(a.split("-")[1])))


# =====================================================================
# C. context の検証と fail-closed 境界 (契約 18-26)
# =====================================================================
class ContextValidationTest(unittest.TestCase):
    def test_unknown_context_key_is_rejected(self) -> None:
        """契約 18: 欄は閉じた集合 (deny-by-default)。"""
        with staged() as stage:
            stage.patch_record("A-001", lambda r: r["context"].__setitem__("memo", "余計な欄"))
            with self.assertRaisesRegex(renderer.RenderError, "未知のキー"):
                stage.build()

    def test_context_field_type_and_emptiness_rejected(self) -> None:
        """契約 19: 型と「空 / 空白だけ」を拒否する。"""
        mutations = {
            "title 空": lambda r: r["context"].__setitem__("title", ""),
            "title 空白のみ": lambda r: r["context"].__setitem__("title", "   "),
            "title 非文字列": lambda r: r["context"].__setitem__("title", 1),
            "narrative 非文字列": lambda r: r["context"].__setitem__("narrative", ["a"]),
            "narrative 空白のみ": lambda r: r["context"].__setitem__("narrative", " \n "),
            "spec_basis 空配列": lambda r: r["context"].__setitem__("spec_basis", []),
            "spec_basis 非配列": lambda r: r["context"].__setitem__("spec_basis", "AGENTS.md"),
            "spec_basis 要素が空": lambda r: r["context"]["spec_basis"].append(""),
            "spec_basis 要素が空白のみ": lambda r: r["context"]["spec_basis"].append("  "),
            "spec_basis 要素が非文字列": lambda r: r["context"]["spec_basis"].append(3),
            "reopen_condition 空": lambda r: r["context"].__setitem__("reopen_condition", ""),
            "context 非 dict": lambda r: r.__setitem__("context", "経緯"),
        }
        for name, mutate in mutations.items():
            with self.subTest(case=name), staged() as stage:
                stage.patch_record("A-001", mutate)
                with self.assertRaisesRegex(renderer.RenderError, "context"):
                    stage.build()

    def test_schema_broken_context_does_not_affect_the_matcher(self) -> None:
        """契約 20: JSON として妥当なまま `context` の形だけ壊しても照合器は止まらない。"""
        with staged() as stage:
            stage.patch_record("A-001", lambda r: r["context"].__setitem__("title", ""))
            errors = v.validate_adjudications(v.load_jsonl(str(stage.adjudications)))
            self.assertEqual(errors, [])
            with self.assertRaises(renderer.RenderError):
                stage.build()

    def test_json_syntax_error_fails_both(self) -> None:
        """契約 21: JSONL の構文を壊した場合は照合器も従来どおり fail-closed になる。"""
        with staged() as stage:
            lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
            lines.append('{"adjudication_id": "A-500"')
            stage.write_lines(lines)
            errors = v.validate_adjudications(v.load_jsonl(str(stage.adjudications)))
            self.assertNotEqual(errors, [])
            with self.assertRaisesRegex(renderer.RenderError, "JSON として読めない"):
                stage.build()

    def test_duplicate_json_keys_are_rejected(self) -> None:
        """契約 22: 重複キーは後勝ちで黙って片方を捨てるので拒否する。"""
        with staged() as stage:
            lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
            lines.append('{"adjudication_id": "A-500", "adjudication_id": "A-501"}')
            stage.write_lines(lines)
            with self.assertRaisesRegex(renderer.RenderError, "duplicate key"):
                stage.build()

    def test_non_finite_numbers_are_rejected(self) -> None:
        """契約 23: NaN / Infinity / -Infinity を拒否する。"""
        for token in ("NaN", "Infinity", "-Infinity"):
            with self.subTest(token=token), staged() as stage:
                lines = [json.dumps(r, ensure_ascii=False) for r in stage.records()]
                lines.append('{"adjudication_id": "A-500", "review_after_days": %s}' % token)
                stage.write_lines(lines)
                with self.assertRaisesRegex(renderer.RenderError, "non-finite"):
                    stage.build()

    def test_duplicate_adjudication_id_is_rejected_by_renderer(self) -> None:
        """契約 24: 生成器は照合器が走った前提に寄りかからない。"""
        with staged() as stage:
            records = stage.records()
            records.append(json.loads(json.dumps(stage.record("A-001"))))
            stage.write_records(records)
            with self.assertRaisesRegex(renderer.RenderError, "adjudication_id が重複"):
                stage.build()

    def test_bad_adjudication_id_form_is_rejected(self) -> None:
        """契約 25: id は `^A-[0-9]{3,}$`。"""
        for bad in ("A-1", "B-001", "A-001x", "", "A-001\n", "A-001\r", " A-001"):
            with self.subTest(adjudication_id=bad), staged() as stage:
                records = stage.records()
                extra = json.loads(json.dumps(stage.record("A-001")))
                extra["adjudication_id"] = bad
                extra.pop("context", None)
                records.append(extra)
                stage.write_records(records)
                with self.assertRaisesRegex(
                    renderer.RenderError, "adjudication_id の書式が不正"
                ):
                    stage.build()

    def test_missing_machine_field_raises_render_error_not_key_error(self) -> None:
        """契約 26: 生成に使う機械項目の欠落は RenderError (KeyError で落とさない)。"""
        for field in renderer.RENDERED_MACHINE_FIELDS:
            with self.subTest(field=field), staged() as stage:
                stage.patch_record("A-001", lambda r, field=field: r.pop(field, None))
                with self.assertRaisesRegex(
                    renderer.RenderError, f"機械項目 {field} が無い"
                ):
                    stage.build()


# =====================================================================
# D. 移行台帳 (契約 27-40)
# =====================================================================
class MigrationManifestTest(unittest.TestCase):
    def test_migration_manifest_matches_expected_semantics(self) -> None:
        """契約 27: 台帳の意味内容がテスト定数と完全一致する (弱める変更を赤にする)。"""
        migration = renderer.load_migration(str(MIGRATION))
        actual = {}
        for entry in migration["entries"]:
            actual[entry["key"]] = {
                "key_kind": entry["key_kind"],
                "target": entry["target"],
                "field_minimums": entry["field_minimums"],
                "required_fragments": [
                    (f["field"], f["value"]) for f in entry["required_fragments"]
                ],
            }
        self.assertEqual(actual, EXPECTED_MIGRATION)
        self.assertEqual(migration["block_count"], EXPECTED_BLOCK_COUNT)
        self.assertEqual(renderer.EXPECTED_BLOCK_COUNT, EXPECTED_BLOCK_COUNT)

    def test_duplicate_required_fragment_is_rejected(self) -> None:
        """契約 28: `(field, value)` の重複した台帳を拒否する。"""
        with staged() as stage:
            migration = stage.migration_obj()
            fragments = migration["entries"][0]["required_fragments"]
            fragments.append(json.loads(json.dumps(fragments[0])))
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "required_fragments が重複"):
                stage.build()

    def test_block_count_change_fails(self) -> None:
        """契約 29 (件数の pin): `block_count` を動かすと落ちる。

        **pin そのものへ到達させる**ため、entries と見出しの件数も揃えたうえで
        pin だけが食い違う状態を作る (件数不一致で先に落ちると pin の検査を通っていない)。
        """
        with staged() as stage:
            migration = stage.migration_obj()
            extra = json.loads(json.dumps(migration["entries"][0]))
            extra["key"] = "A-002"
            extra["field_minimums"] = {"title": 1}
            extra["required_fragments"] = [{"field": "title", "value": "x"}]
            migration["entries"].append(extra)
            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
            migration["block_count"] = EXPECTED_BLOCK_COUNT + 1
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "ブロック数の pin"):
                stage.build()

    def test_entries_count_mismatch_fails(self) -> None:
        """契約 29 (件数の三点一致): `entries` の件数が `block_count` と食い違えば落ちる。"""
        with staged() as stage:
            migration = stage.migration_obj()
            migration["entries"] = []
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "entries の件数"):
                stage.build()

    def test_heading_count_mismatch_fails(self) -> None:
        """契約 29 (件数の三点一致): 移行元見出しの件数が `block_count` と食い違えば落ちる。"""
        with staged() as stage:
            migration = stage.migration_obj()
            migration["provenance"]["source_block_headings"].append("#### 余計な見出し")
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "移行元見出しの件数"):
                stage.build()

    def test_duplicate_key_in_manifest_fails(self) -> None:
        """契約 30 (重複): 同じ鍵を 2 度書いた台帳を拒否する。

        件数の pin より**前**に鍵の重複を見ていることまで固定する (順序が逆だと、
        重複検出を削っても pin の失敗に隠れてテストが緑のままになる)。
        """
        with staged() as stage:
            migration = stage.migration_obj()
            migration["entries"].append(json.loads(json.dumps(migration["entries"][0])))
            migration["block_count"] = len(migration["entries"])
            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "key が重複"):
                stage.build()

    def test_unknown_key_does_not_resolve(self) -> None:
        """契約 30 (解決不能): 実在しない鍵は RenderError。"""
        with staged() as stage:
            migration = stage.migration_obj()
            migration["entries"][0]["key"] = "A-777"
            stage.write_migration(migration)
            with self.assertRaisesRegex(renderer.RenderError, "鍵が解決できない"):
                stage.build()

    def test_key_kind_and_target_vocabulary_is_closed(self) -> None:
        """契約 31: 語彙外の値・欄名を拒否する (deny-by-default)。"""
        mutations = {
            "key_kind": lambda m: m["entries"][0].__setitem__("key_kind", "finding_id"),
            "target": lambda m: m["entries"][0].__setitem__("target", "spec_notes"),
            "field_minimums の欄名": lambda m: m["entries"][0]["field_minimums"].__setitem__(
                "memo", 10
            ),
            "required_fragments の field": lambda m: m["entries"][0]["required_fragments"][0]
            .__setitem__("field", "memo"),
        }
        for name, mutate in mutations.items():
            with self.subTest(case=name), staged() as stage:
                migration = stage.migration_obj()
                mutate(migration)
                stage.write_migration(migration)
                with self.assertRaisesRegex(renderer.RenderError, "語彙外の値"):
                    stage.build()

    def test_integer_fields_reject_bool_and_non_positive(self) -> None:
        """契約 32: 整数の欄は bool / 0 / 負 / 文字列 / null を拒否する。"""
        bad_values = [True, 0, -1, "900", None]
        for bad in bad_values:
            for name, mutate, expected in (
                ("version",
                 lambda m, bad=bad: m.__setitem__("version", bad),
                 "version は正の整数"),
                ("block_count",
                 lambda m, bad=bad: m.__setitem__("block_count", bad),
                 "block_count は正の整数"),
                ("field_minimums",
                 lambda m, bad=bad: m["entries"][0]["field_minimums"].__setitem__(
                     "narrative", bad),
                 "field_minimums.narrative は正の整数"),
            ):
                with self.subTest(field=name, value=repr(bad)), staged() as stage:
                    migration = stage.migration_obj()
                    mutate(migration)
                    stage.write_migration(migration)
                    with self.assertRaisesRegex(renderer.RenderError, expected):
                        stage.build()

    def test_field_below_minimum_fails(self) -> None:
        """契約 33: 経緯が痩せたら落ちる (欄の削除も下限割れも)。"""
        with staged() as stage:
            stage.patch_record("A-001", lambda r: r["context"].__setitem__("narrative", "短い経緯"))
            with self.assertRaisesRegex(renderer.RenderError, "痩せている"):
                stage.build()
        with staged() as stage:
            stage.patch_record("A-001", lambda r: r["context"].pop("reopen_condition"))
            with self.assertRaisesRegex(renderer.RenderError, "要求する欄 reopen_condition"):
                stage.build()

    def test_required_fragment_missing_fails(self) -> None:
        """契約 34: 必須断片が消えたら落ちる (長さだけ保った書き換えを止める)。"""
        with staged() as stage:
            stage.patch_record(
                "A-001",
                lambda r: r["context"].__setitem__(
                    "narrative",
                    r["context"]["narrative"].replace("feedback-probe.js", "probe-file.txt"),
                ),
            )
            with self.assertRaisesRegex(renderer.RenderError, "必須の断片が無い"):
                stage.build()

    def test_fragment_is_searched_only_in_its_declared_field(self) -> None:
        """契約 35: 宣言された欄の外にあっても一致とみなさない。"""
        with staged() as stage:

            def mutate(record: dict) -> None:
                context = record["context"]
                context["narrative"] = context["narrative"] + " AUTO_DISMISS_MS installed_now"
                context["reopen_condition"] = (
                    context["reopen_condition"]
                    .replace("AUTO_DISMISS_MS", "自動消去の時間")
                    .replace("installed_now", "仕込み済みか")
                )

            stage.patch_record("A-001", mutate)
            with self.assertRaisesRegex(renderer.RenderError, "必須の断片が無い"):
                stage.build()

    def test_fragment_identifier_boundary(self) -> None:
        """契約 36: 短い参照が長い別参照へ誤って当たらない。"""
        self.assertFalse(renderer.fragment_present("T095", "T0950 を参照"))
        self.assertFalse(renderer.fragment_present("T095", "xT095 を参照"))
        self.assertFalse(renderer.fragment_present("T095", "T095-extra を参照"))
        self.assertTrue(renderer.fragment_present("T095", "T095 の実装フェーズ"))
        self.assertTrue(renderer.fragment_present("T095", "`T095` を参照"))
        self.assertTrue(renderer.fragment_present("T095", "対応は T095"))
        self.assertFalse(renderer.fragment_present("", "何か"))

    def test_provenance_shape_and_heading_count(self) -> None:
        """契約 37: 由来の必須キー・型と、見出し件数が `block_count` と一致すること。"""
        migration = renderer.load_migration(str(MIGRATION))
        provenance = migration["provenance"]
        for key in renderer.PROVENANCE_KEYS:
            self.assertIn(key, provenance)
        headings = provenance["source_block_headings"]
        self.assertEqual(len(headings), migration["block_count"])
        self.assertEqual(len(set(headings)), len(headings))
        self.assertTrue(all(isinstance(h, str) and h.strip() for h in headings))

        def _duplicate_headings(migration: dict) -> None:
            """見出しを重複させる。件数の突き合わせより前に一意性を見ていることも固定する。"""
            head = migration["provenance"]["source_block_headings"][0]
            migration["provenance"]["source_block_headings"] = [head, head]

        mutations = {
            "必須キー欠落": (lambda m: m["provenance"].pop("note"), "provenance.note"),
            "見出しが空白のみ": (
                lambda m: m["provenance"].__setitem__("source_block_headings", ["  "]),
                "非空文字列の配列",
            ),
            "見出しの重複": (_duplicate_headings, "source_block_headings に重複"),
            "hash の書式不正": (
                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
                    "A-001", "短すぎる"
                ),
                "64 桁 hex",
            ),
            "hash に末尾改行": (
                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
                    "A-001", "0" * 64 + "\n"
                ),
                "64 桁 hex",
            ),
            "hash の鍵に末尾改行": (
                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
                    "A-002\n", "0" * 64
                ),
                "adjudication_id ではない",
            ),
            "hash の鍵が id でない": (
                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
                    "F-1-02", "0" * 64
                ),
                "adjudication_id ではない",
            ),
        }
        for name, (mutate, expected) in mutations.items():
            with self.subTest(case=name), staged() as stage:
                migration = stage.migration_obj()
                mutate(migration)
                stage.write_migration(migration)
                with self.assertRaisesRegex(renderer.RenderError, expected):
                    stage.build()

    def test_machine_projection_sha256_is_pinned_in_three_places(self) -> None:
        """契約 38: テスト定数 / 移行台帳 / 現在の登録の三点で一致する。"""
        migration = renderer.load_migration(str(MIGRATION))
        pinned = migration["provenance"]["machine_projection_sha256"]
        self.assertEqual(pinned, EXPECTED_MACHINE_PROJECTION_SHA256)
        records = {
            r["adjudication_id"]: r for r in renderer.load_adjudications(str(ADJUDICATIONS))
        }
        for adjudication_id, expected in EXPECTED_MACHINE_PROJECTION_SHA256.items():
            with self.subTest(adjudication_id=adjudication_id):
                self.assertEqual(
                    renderer.canonical_machine_projection(records[adjudication_id]), expected
                )

    def test_machine_field_change_turns_red(self) -> None:
        """契約 39: 機械項目を書き換え、台帳の hash も同時に更新しても赤になる。"""
        with staged() as stage:
            stage.patch_record("A-001", lambda r: r.__setitem__("review_after_days", 90))
            mutated = {
                r["adjudication_id"]: r for r in renderer.load_adjudications(str(stage.adjudications))
            }["A-001"]
            recomputed = renderer.canonical_machine_projection(mutated)
            migration = stage.migration_obj()
            migration["provenance"]["machine_projection_sha256"]["A-001"] = recomputed
            stage.write_migration(migration)
            # 台帳側の hash を合わせたので生成は通る。しかしテスト定数とは食い違う。
            stage.build()
            self.assertNotEqual(recomputed, EXPECTED_MACHINE_PROJECTION_SHA256["A-001"])

    def test_manifest_shape_is_rejected_when_not_a_single_object(self) -> None:
        """契約 40: 配列 / 不在ファイルを拒否する。"""
        with staged() as stage:
            stage.write_migration_text("[]\n")
            with self.assertRaisesRegex(renderer.RenderError, "単一の object"):
                stage.build()
        with staged() as stage:
            stage.migration.unlink()
            with self.assertRaisesRegex(renderer.RenderError, "移行台帳が無い"):
                stage.build()
        with staged() as stage:
            stage.adjudications.unlink()
            with self.assertRaisesRegex(renderer.RenderError, "裁定登録が無い"):
                stage.build()


# =====================================================================
# E. 既存方針の継承 / 構造的保証 (契約 41-43)
# =====================================================================
class SpecBasisAndIsolationTest(unittest.TestCase):
    def test_spec_basis_references_are_well_formed_and_exist(self) -> None:
        """契約 41: 根拠は全要素が所定形式で、リポジトリ内の通常ファイルを指す。"""
        problems: list[str] = []
        for record in renderer.load_adjudications(str(ADJUDICATIONS)):
            context = record.get("context")
            if not context:
                continue
            for reference in context["spec_basis"]:
                problem = _spec_basis_problem(reference, REPO_ROOT)
                if problem is not None:
                    problems.append(f"{record['adjudication_id']}: {problem}")
        self.assertEqual(problems, [], "context.spec_basis が腐っている:\n" + "\n".join(problems))

    def test_spec_basis_extension_vocabulary_is_pinned(self) -> None:
        """契約 41 (拡張子の閉じた集合): 許す拡張子と拒む拡張子を両側から固定する。

        集合を黙って広げると「書式を外して実在検査から逃げる」余地が増えるため、
        許可側は完全一致で pin し、代表的な拒否例も明示する。
        """
        with tempfile.TemporaryDirectory() as root:
            root_path = Path(root)
            for extension in SPEC_BASIS_EXTENSIONS:
                target = root_path / f"sample.{extension}"
                target.write_text("x\n", encoding="utf-8")
                with self.subTest(extension=extension, allowed=True):
                    self.assertIsNone(_spec_basis_problem(f"sample.{extension} 説明", root_path))
            for extension in ("txt", "tsx", "jsx", "png", "lock", "csv"):
                target = root_path / f"sample.{extension}"
                target.write_text("x\n", encoding="utf-8")
                with self.subTest(extension=extension, allowed=False):
                    self.assertIsNotNone(
                        _spec_basis_problem(f"sample.{extension} 説明", root_path)
                    )

    def test_spec_basis_rejects_traversal_and_escape(self) -> None:
        """契約 42: 絶対パス / `..` / symlink 脱出 / 書式不正の 4 ケースが失敗する。"""
        with tempfile.TemporaryDirectory() as outside, tempfile.TemporaryDirectory() as root:
            root_path, outside_path = Path(root), Path(outside)
            (outside_path / "secret.md").write_text("外部\n", encoding="utf-8")
            (root_path / "inside.md").write_text("内部\n", encoding="utf-8")
            os.symlink(outside_path, root_path / "escape")

            self.assertIsNone(_spec_basis_problem("inside.md 説明", root_path))
            for reference in (
                "/etc/passwd.md 絶対パス",
                "../outside/secret.md 親参照",
                "escape/secret.md symlink 脱出",
                "not-a-path 書式不正",
                "inside.txt 拡張子が対象外",
            ):
                with self.subTest(reference=reference):
                    self.assertIsNotNone(_spec_basis_problem(reference, root_path))

    def test_matcher_source_never_names_the_handover_files(self) -> None:
        """契約 43: 照合器は申し送りの生成物・生成器・その入力を 1 語も知らない。"""
        source = MATCHER_SOURCE.read_text(encoding="utf-8")
        for token in ("spec-ledger", "spec_ledger", "render_spec_ledger", "spec-notes"):
            with self.subTest(token=token):
                self.assertNotIn(token, source)


if __name__ == "__main__":
    unittest.main()
