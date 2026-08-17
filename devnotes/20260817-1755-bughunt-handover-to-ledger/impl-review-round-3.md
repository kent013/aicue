### `.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py`

問題ありません。

`adjudication_id`、`supersedes`、移行 hash の鍵・値はすべて `fullmatch()` へ統一されています。生成器内に、入力検証として不適切な `match()` は残っていません。

`load_migration()` の検査順序も妥当です。要素単体の重複・型・語彙検査、件数一致、固定値との一致という順序で、別の検査を到達不能にする問題は見当たりません。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py`

- [Warning] `supersedes` のマーカー混入テストだけ masking が残っています。

  ```python
  with self.assertRaisesRegex(
      renderer.RenderError,
      "書式が不正|機械マーカーの接頭辞",
  ):
  ```

  `_check_inline_text(record["supersedes"], ...)` からマーカー検査だけを除去しても、後段の `_check_supersede_graph()` が「書式が不正」を投げるため、このテストは緑のままです。実際、提示された変異試験の「marker 検査を外す → 9 failures」という件数も、通常の機械項目6件＋context側3件だけが赤になり、`supersedes` が masking された結果と整合します。

  現在の検査順ではマーカー検査が必ず先なので、期待値を `"機械マーカーの接頭辞"` のみに絞ってください。追加する変異試験は「`supersedes` に対する `_check_inline_text` のマーカー検査だけを無効化する」が適切です。

それ以外の `assertRaisesRegex` は、提示された入力と生成器の検査順序を照合した範囲で、期待メッセージとの不自然なずれはありません。

`SPEC_BASIS_EXTENSIONS` を正規表現の唯一の正本にした変更も適切です。

### `.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json`

問題ありません。

### `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl`

問題ありません。

### `.claude/skills/app-bug-hunt/ledger/README.md`

問題ありません。

### `.claude/skills/app-bug-hunt/spec-ledger.md`

問題ありません。

### `AGENTS.md`

問題ありません。

### `docs/TODO.md`

問題ありません。

### 検証状況

AGENTS.md が要求する検証コマンド一式について、今回はすべて green の結果が提示されています。

CHANGES_REQUESTED