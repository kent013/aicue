### `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl`

**問題なし**

A-001〜A-003 は変更されず、A-004 が1行追加されています。A-004 は `supersedes: "A-001"` を持ち、機械項目は A-001 と一致し、`watch_globs` に `resources/js/lib/stores/toast.ts` が追加されています。移行時点の hash pin 関連ファイルも変更されていません。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py`

**問題なし**

`_unused_adjudication_id()` によって実登録との ID 衝突を避けつつ、「A-002 を複数登録が差し替えた場合に全後継 ID を表示する」という既存契約の意図を維持しています。

### `.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py`

**指摘あり**

- **Warning**: 「A-001 を supersede した active な登録」を一体として検証できていません。現在は以下を独立に検証しています。

  - 同じ `species_key` / `scope_value` の active 登録が `toast.ts` を持つ
  - 何らかの登録が A-001 を supersede している

  したがって、別々の登録がこの2条件を満たしても通ります。また、`A-004.supersedes == "A-001"` を直接固定していません。A-004 を ID で取得し、直接の supersede 関係、active 状態、`scope_kind` を含む同一機械項目、`toast.ts` をまとめて検証すべきです。

- **Warning**: `test_a001_itself_is_unchanged_and_now_superseded` が固定しているのは `species_key` と `watch_globs` だけです。「A-001 自身は不変」というテスト名・要求に対して、`scope`、`conditions`、`symptom`、`verdict`、帰属情報、`context` などの改変を検出できません。A-001 の完全な期待値、または安定した全レコード hash を固定してください。

### `.claude/skills/app-bug-hunt/spec-ledger.md`

**問題なし**

提示された A-004 の内容と整合し、A-001 は A-004 による superseded、A-004 は active として掲載されています。提示された `--check` 成功結果とも整合します。

CHANGES_REQUESTED