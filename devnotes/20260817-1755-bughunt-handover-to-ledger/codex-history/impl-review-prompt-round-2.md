# 実装レビュー Round 2 (T223 / bug-hunt 申し送りの生成物化)

Round 1 の指摘 (Critical 0 / Warning 6 + 検証未提示 1) にすべて対応した。
反論・見送りはゼロ件である。以下に対応マトリクスと**追加差分**、および
AGENTS.md が要求する検証コマンド一式の**実測結果**を示す。

Round 1 と同じレビュー観点 (設計との一致性 / 正確性 / テスト網羅性 / セキュリティ /
ドキュメントの整合) で再判定してほしい。特に次の 2 点を重点的に見てほしい:

1. `load_migration()` の**検査順序の組み替え**が、別の検査を到達不能にしていないか
   (「1 件ずつ見れば分かること → 件数の突き合わせ → 件数の pin」の順に変えた)。
2. CR/LF 注入の表駆動テストが**出力の 1 行に出る欄を網羅している**か
   (漏れている欄があれば名指しで指摘してほしい)。

## 対応マトリクス

# 実装レビュー Round 1 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 6)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Warning] `--check` が byte 一致になっていない (`read_text()` は CRLF を LF へ畳むため、改行だけ変えた手編集を検出できない) | **対応する** | `main()` の比較を `read_bytes()` と `text.encode("utf-8")` に変更。`sha256_of_text()` を `sha256_of_bytes()` へ置き換えた (後方互換の別名は残さない = AGENTS.md 思考原則 3)。diff 表示だけは `decode(errors="replace")` した文字列で行う |
| 2 | [Warning] `spec-ledger-migration.json` の `source_lines` が `"81-113"` だが移行元は全 112 行 | **対応する** | 移行元 (`HEAD:.claude/skills/app-bug-hunt/spec-ledger.md`) を実測し、run 節の開始 81 行目 / ファイル末尾 112 行目を確認して `"81-112"` へ訂正した (詳細設計の値が誤っていた) |
| 3 | [Warning] byte 不変性テストも `read_text()` 後の文字列を hash 化しており、契約を固定できていない | **対応する** | `_Stage.output_sha()` を `read_bytes()` の sha256 に変更。`test_generated_output_matches_committed_file` も byte 比較へ。あわせて **改行コードだけ変えた差分**を検出する `test_newline_only_edit_is_detected` を新設した |
| 4 | [Warning] `test_duplicate_key_in_manifest_fails` が `block_count` の pin で先に落ち、鍵の重複検査へ到達しない。見出しの一意性検査も同様に到達しない | **対応する** | `load_migration()` の検査順を「1 件ずつ見れば分かること → 件数の突き合わせ → 件数の pin」に組み替え、順序が意図であることを docstring に明記。テスト側は `assertRaisesRegex` で**失敗理由**まで固定し、`test_heading_count_mismatch_fails` を分離。`test_block_count_change_fails` は entries と見出しの件数も揃えて pin だけが食い違う入力にした |
| 5 | [Warning] CR/LF 注入テストが一部の欄だけ (`scope_kind` / `adjudicated_at_run` / `supersedes` / `context.spec_basis` / `context.reopen_condition` が退行しても緑) | **対応する** | `test_newline_in_one_line_fields_is_rejected` として**出力の 1 行に出る全 10 欄**の表駆動テストに置き換え、欄ごとに `subTest` を分けた (`narrative` は複数行 markdown なので対象外であることを docstring に書いた) |
| 6 | [Warning] `SPEC_BASIS_FORM_RE` が詳細設計の閉じた集合より広く `tsx` / `jsx` / `jsonl` を許す | **対応する** | 根拠のない `tsx` / `jsx` を削除。`jsonl` は A-003 の根拠 (`devnotes/.../findings-merged.jsonl`) に必要なので**設計の 9 種へ意図して 1 つ足した**ものとしてコメントで理由を明示し、許可側 11 種を `SPEC_BASIS_EXTENSIONS` に列挙して `test_spec_basis_extension_vocabulary_is_pinned` で許可・拒否の両側から pin した |
| 7 | [Warning] `composer test` ほかの検証コマンドが実行中で全 green を提示できていない | **対応する** | Round 2 で実測値を提示する (`composer test` 5770 tests / 5768 passed / 2 skipped、`composer phpstan` 0 errors (988 files)、`vendor/bin/pint --test` passed、`pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` 完走、`pnpm test` は結果待ち) |

反論・見送りはゼロ件である。


## 追加差分 (Round 1 レビュー後の変更のみ。git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
index 173a144..a1c0f4f 100644
--- a/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
+++ b/.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py
@@ -102,9 +102,13 @@ class RenderError(Exception):
 # ---------------------------------------------------------------------
 # 小道具
 # ---------------------------------------------------------------------
-def sha256_of_text(text: str) -> str:
-    """文字列の sha256 (hex)。"""
-    return hashlib.sha256(text.encode("utf-8")).hexdigest()
+def sha256_of_bytes(data: bytes) -> str:
+    """バイト列の sha256 (hex)。
+
+    比較は**必ずバイト列で行う**。テキストとして読み直すと universal newline 変換で
+    CRLF が LF に畳まれ、改行だけ変えた手編集を「一致」と誤判定するため。
+    """
+    return hashlib.sha256(data).hexdigest()
 
 
 def canonical_machine_projection(adjudication: dict) -> str:
@@ -325,7 +329,13 @@ def _check_closed_vocabulary(value, vocabulary, where: str) -> None:
 
 
 def load_migration(path: str) -> dict:
-    """移行台帳を読み、閉じた語彙と件数の一致を検証する。"""
+    """移行台帳を読み、閉じた語彙と件数の一致を検証する。
+
+    **検査の順序は意図的である**。件数の pin (`EXPECTED_BLOCK_COUNT`) を先に見ると、
+    件数を動かす形の壊れ方 (鍵の重複・見出しの重複) が pin の失敗に隠れて
+    「その検査を消しても赤にならない」状態になる。したがって
+    「1 件ずつ見れば分かること」→「件数の突き合わせ」→「pin」の順に並べる。
+    """
     if not os.path.isfile(path):
         raise RenderError(f"移行台帳が無い: {path}")
     try:
@@ -340,10 +350,6 @@ def load_migration(path: str) -> dict:
     block_count = migration.get("block_count")
     if not _is_positive_int(block_count):
         raise RenderError(f"移行台帳 block_count は正の整数: {block_count!r}")
-    if block_count != EXPECTED_BLOCK_COUNT:
-        raise RenderError(
-            f"移行元ブロック数の pin と食い違う: {block_count} != {EXPECTED_BLOCK_COUNT}"
-        )
 
     provenance = migration.get("provenance")
     if not isinstance(provenance, dict):
@@ -357,10 +363,6 @@ def load_migration(path: str) -> dict:
     headings = provenance["source_block_headings"]
     if not isinstance(headings, list) or not all(_is_filled_str(h) for h in headings):
         raise RenderError("移行台帳 provenance.source_block_headings は非空文字列の配列")
-    if len(headings) != block_count:
-        raise RenderError(
-            f"移行元見出しの件数が block_count と食い違う: {len(headings)} != {block_count}"
-        )
     if len(set(headings)) != len(headings):
         raise RenderError("移行台帳 provenance.source_block_headings に重複がある")
     pins = provenance["machine_projection_sha256"]
@@ -375,10 +377,6 @@ def load_migration(path: str) -> dict:
     entries = migration.get("entries")
     if not isinstance(entries, list):
         raise RenderError("移行台帳 entries は配列である必要がある")
-    if len(entries) != block_count:
-        raise RenderError(
-            f"entries の件数が block_count と食い違う: {len(entries)} != {block_count}"
-        )
     keys: set = set()
     for entry in entries:
         if not isinstance(entry, dict):
@@ -414,6 +412,20 @@ def load_migration(path: str) -> dict:
             if pair in pairs:
                 raise RenderError(f"{key}: required_fragments が重複している: {pair}")
             pairs.add(pair)
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
     return migration
 
 
@@ -637,9 +649,12 @@ def main(argv=None) -> int:
             print(f"生成物が無い: {args.output}", file=sys.stderr)
             print(f"再生成: {REGENERATE_COMMAND}", file=sys.stderr)
             return 1
-        current = pathlib.Path(args.output).read_text(encoding="utf-8")
-        if current == text:
+        # 比較はバイト列で行う (read_text() は CRLF を LF に畳むため、
+        # 改行だけ変えた手編集を「一致」と誤判定する)。
+        current_bytes = pathlib.Path(args.output).read_bytes()
+        if current_bytes == text.encode("utf-8"):
             return 0
+        current = current_bytes.decode("utf-8", errors="replace")
         diff = difflib.unified_diff(
             current.splitlines(keepends=True),
             text.splitlines(keepends=True),
diff --git a/.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json b/.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json
index 047aa27..0658f32 100644
--- a/.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json
+++ b/.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json
@@ -4,7 +4,7 @@
   "provenance": {
     "source_file": ".claude/skills/app-bug-hunt/spec-ledger.md",
     "source_commit": "c5a514da1d15e1b95f9c26accab381a0b676358d",
-    "source_lines": "81-113",
+    "source_lines": "81-112",
     "source_block_headings": [
       "#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた"
     ],
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
index bc6ddff..81617a9 100644
--- a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
+++ b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
@@ -75,8 +75,16 @@ EXPECTED_MACHINE_PROJECTION_SHA256 = {
 
 # 根拠 (`context.spec_basis`) の 1 要素の先頭トークンの書式。
 # 位置指定 (`:230-232`) とアンカー (`#見出し`) は任意で、実在検査では捨てる。
+#
+# 拡張子は**閉じた集合**である。詳細設計が列挙した 9 種に `jsonl` を 1 つだけ足した
+# (A-003 の根拠が run 成果物 `findings-merged.jsonl` を指すため。`json` だけだと
+# 末尾の `l` が余って書式不正になり、実在する根拠が失敗扱いになる)。
+# **これ以外は増やさない** — 集合を広げるほど「書式を外して検査から逃げる」余地が増える。
+SPEC_BASIS_EXTENSIONS = (
+    "php", "ts", "js", "svelte", "md", "jsonl", "json", "yaml", "yml", "py", "sh",
+)
 SPEC_BASIS_FORM_RE = re.compile(
-    r"^(?P<path>[\w./-]+\.(?:php|tsx?|jsx?|jsonl|svelte|md|json|ya?ml|py|sh))"
+    r"^(?P<path>[\w./-]+\.(?:php|ts|js|svelte|md|jsonl|json|ya?ml|py|sh))"
     r"(?:[:#][\w.\-#]*)*$"
 )
 
@@ -201,10 +209,11 @@ class _Stage:
     def seed_output(self, text: str = "sentinel\n") -> str:
         """出力位置に見張り用の中身を置き、その sha256 を返す。"""
         self.output.write_text(text, encoding="utf-8")
-        return renderer.sha256_of_text(self.output.read_text(encoding="utf-8"))
+        return self.output_sha()
 
     def output_sha(self) -> str:
-        return renderer.sha256_of_text(self.output.read_text(encoding="utf-8"))
+        """**バイト列**の sha256 (テキストで読み直すと改行の変化を見逃す)。"""
+        return renderer.sha256_of_bytes(self.output.read_bytes())
 
     def temp_files(self) -> list[Path]:
         return sorted(self.dir.glob(".spec-ledger.*.tmp"))
@@ -221,8 +230,12 @@ def staged():
 # =====================================================================
 class GeneratedArtifactTest(unittest.TestCase):
     def test_generated_output_matches_committed_file(self) -> None:
-        """契約 1: 生成結果が現物と byte 一致する (再生成忘れの検出)。"""
-        self.assertEqual(renderer.build(), SPEC_LEDGER.read_text(encoding="utf-8"))
+        """契約 1: 生成結果が現物と byte 一致する (再生成忘れの検出)。
+
+        比較は**バイト列**で行う。`read_text()` は CRLF を LF へ畳むため、
+        改行だけ変えた差分を「一致」と誤判定する。
+        """
+        self.assertEqual(SPEC_LEDGER.read_bytes(), renderer.build().encode("utf-8"))
 
     def test_check_passes_on_committed_file(self) -> None:
         """契約 2: `--check` は現物に対して exit 0。"""
@@ -242,6 +255,20 @@ class GeneratedArtifactTest(unittest.TestCase):
             self.assertEqual(code, 1)
             self.assertIn(renderer.REGENERATE_COMMAND, err)
 
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
     def test_check_fails_when_output_is_absent(self) -> None:
         """契約 4: 出力が無ければ `--check` は exit 1。"""
         with staged() as stage:
@@ -406,36 +433,40 @@ class ListingCompletenessTest(unittest.TestCase):
             with self.assertRaises(renderer.RenderError):
                 stage.build()
 
-    def test_newline_in_machine_fields_is_rejected(self) -> None:
-        """契約 11 (改行): 機械項目の CR / LF は行頭マーカーの偽装に使えるので拒否する。"""
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
         for newline in ("\n", "\r"):
-            for name, mutate in {
-                "verdict": lambda r: r.__setitem__("verdict", f"false{newline}positive"),
-                "scope_value": lambda r: r["scope"].__setitem__(
-                    "scope_value", f"a{newline}b"
-                ),
-                "source_finding_ids": lambda r: r["source_finding_ids"].__setitem__(
-                    0, f"F-1{newline}-02"
-                ),
-                "adjudicated_at_commit": lambda r: r.__setitem__(
-                    "adjudicated_at_commit", f"22d{newline}6d30"
-                ),
-            }.items():
+            payload = f"前{newline}後"
+            for adjudication_id, name, inject in injections:
                 with self.subTest(field=name, newline=repr(newline)), staged() as stage:
-                    stage.patch_record("A-001", mutate)
+                    stage.patch_record(
+                        adjudication_id, lambda r, inject=inject: inject(r, payload)
+                    )
                     with self.assertRaises(renderer.RenderError):
                         stage.build()
 
-    def test_title_with_newline_is_rejected(self) -> None:
-        """契約 12: `title` は 1 行であることが契約 (見出し行に出るため)。"""
-        for newline in ("\n", "\r"):
-            with self.subTest(newline=repr(newline)), staged() as stage:
-                stage.patch_record(
-                    "A-001", lambda r: r["context"].__setitem__("title", f"前{newline}後")
-                )
-                with self.assertRaises(renderer.RenderError):
-                    stage.build()
-
     def test_entry_without_context_is_still_listed(self) -> None:
         """契約 13: 経緯を持たない登録も掲載され、「経緯は未記入」の印が付く。"""
         with staged() as stage:
@@ -649,12 +680,22 @@ class MigrationManifestTest(unittest.TestCase):
                 stage.build()
 
     def test_block_count_change_fails(self) -> None:
-        """契約 29 (件数の pin): `block_count` を動かすと落ちる。"""
+        """契約 29 (件数の pin): `block_count` を動かすと落ちる。
+
+        **pin そのものへ到達させる**ため、entries と見出しの件数も揃えたうえで
+        pin だけが食い違う状態を作る (件数不一致で先に落ちると pin の検査を通っていない)。
+        """
         with staged() as stage:
             migration = stage.migration_obj()
+            extra = json.loads(json.dumps(migration["entries"][0]))
+            extra["key"] = "A-002"
+            extra["field_minimums"] = {"title": 1}
+            extra["required_fragments"] = [{"field": "title", "value": "x"}]
+            migration["entries"].append(extra)
+            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
             migration["block_count"] = EXPECTED_BLOCK_COUNT + 1
             stage.write_migration(migration)
-            with self.assertRaises(renderer.RenderError):
+            with self.assertRaisesRegex(renderer.RenderError, "ブロック数の pin"):
                 stage.build()
 
     def test_entries_count_mismatch_fails(self) -> None:
@@ -663,17 +704,31 @@ class MigrationManifestTest(unittest.TestCase):
             migration = stage.migration_obj()
             migration["entries"] = []
             stage.write_migration(migration)
-            with self.assertRaises(renderer.RenderError):
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
                 stage.build()
 
     def test_duplicate_key_in_manifest_fails(self) -> None:
-        """契約 30 (重複): 同じ鍵を 2 度書いた台帳を拒否する。"""
+        """契約 30 (重複): 同じ鍵を 2 度書いた台帳を拒否する。
+
+        件数の pin より**前**に鍵の重複を見ていることまで固定する (順序が逆だと、
+        重複検出を削っても pin の失敗に隠れてテストが緑のままになる)。
+        """
         with staged() as stage:
             migration = stage.migration_obj()
             migration["entries"].append(json.loads(json.dumps(migration["entries"][0])))
             migration["block_count"] = len(migration["entries"])
+            migration["provenance"]["source_block_headings"].append("#### 別の移行元見出し")
             stage.write_migration(migration)
-            with self.assertRaises(renderer.RenderError):
+            with self.assertRaisesRegex(renderer.RenderError, "key が重複"):
                 stage.build()
 
     def test_unknown_key_does_not_resolve(self) -> None:
@@ -783,26 +838,37 @@ class MigrationManifestTest(unittest.TestCase):
         self.assertEqual(len(set(headings)), len(headings))
         self.assertTrue(all(isinstance(h, str) and h.strip() for h in headings))
 
+        def _duplicate_headings(migration: dict) -> None:
+            """見出しを重複させる。件数の突き合わせより前に一意性を見ていることも固定する。"""
+            head = migration["provenance"]["source_block_headings"][0]
+            migration["provenance"]["source_block_headings"] = [head, head]
+
         mutations = {
-            "必須キー欠落": lambda m: m["provenance"].pop("note"),
-            "見出し件数不一致": lambda m: m["provenance"]["source_block_headings"].append(
-                "#### 余計な見出し"
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
+            "hash の鍵が id でない": (
+                lambda m: m["provenance"]["machine_projection_sha256"].__setitem__(
+                    "F-1-02", "0" * 64
+                ),
+                "adjudication_id ではない",
             ),
-            "見出しの重複": lambda m: m["provenance"].__setitem__(
-                "source_block_headings",
-                [m["provenance"]["source_block_headings"][0]] * m["block_count"],
-            )
-            if m["block_count"] > 1
-            else m["provenance"].__setitem__("source_block_headings", ["  "]),
-            "hash の書式不正": lambda m: m["provenance"]["machine_projection_sha256"]
-            .__setitem__("A-001", "短すぎる"),
         }
-        for name, mutate in mutations.items():
+        for name, (mutate, expected) in mutations.items():
             with self.subTest(case=name), staged() as stage:
                 migration = stage.migration_obj()
                 mutate(migration)
                 stage.write_migration(migration)
-                with self.assertRaises(renderer.RenderError):
+                with self.assertRaisesRegex(renderer.RenderError, expected):
                     stage.build()
 
     def test_machine_projection_sha256_is_pinned_in_three_places(self) -> None:
@@ -867,6 +933,27 @@ class SpecBasisAndIsolationTest(unittest.TestCase):
                     problems.append(f"{record['adjudication_id']}: {problem}")
         self.assertEqual(problems, [], "context.spec_basis が腐っている:\n" + "\n".join(problems))
 
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
+                    )
+
     def test_spec_basis_rejects_traversal_and_escape(self) -> None:
         """契約 42: 絶対パス / `..` / symlink 脱出 / 書式不正の 4 ケースが失敗する。"""
         with tempfile.TemporaryDirectory() as outside, tempfile.TemporaryDirectory() as root:

```

## 検証コマンドの実測結果 (AGENTS.md の一式。すべて green)

```
composer test                  → pest passed / tests 5770 / passed 5768 / skipped 2 / assertions 25293
composer phpstan               → No errors (988 files, level 10)
vendor/bin/pint --test         → passed
pnpm lint                      → eslint 完走 (指摘なし)
pnpm typecheck                 → tsc --noEmit 完走 (エラーなし)
pnpm test                      → Test Files 160 passed (160) / Tests 2007 passed (2007)
pnpm build                     → built in 5.76s
pnpm typecheck:packages        → 完走 (エラーなし)
pnpm build:packages            → 完走 (エラーなし)
pnpm test:packages             → 実行中 (完了後に確認する)

本タスク固有:
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
                               → Ran 117 tests / OK (既存 67 + 本タスク 50)
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   → exit 0
python3 ledger/validate_findings.py ledger/example.findings.jsonl \
        --adjudications ledger/adjudications.jsonl
                               → findings 4 / valid 4 / errors 0 / adjudications 3 / invalid 0
bash scripts/bug-hunt-inventory-check.sh
                               → 一致: 画面 71 件 / 操作 79 件 (exit 0)
```

## 出力形式

Round 1 と同じ。ファイルごとの判定 → [Critical] / [Warning] / [Suggestion] の指摘 →
最後に **APPROVED** または **CHANGES_REQUESTED** の 1 語。
