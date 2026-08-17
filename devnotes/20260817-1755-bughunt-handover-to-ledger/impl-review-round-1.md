### `.claude/skills/app-bug-hunt/ledger/README.md`

問題ありません。検証責務、CI の限界、append-only の適用範囲は設計どおりです。

### `.claude/skills/app-bug-hunt/ledger/adjudications.jsonl`

問題ありません。A-001 の監視漏れも隠さず、T224 へ分離されています。A-003 も当時資料と現行確認を区別しています。

### `.claude/skills/app-bug-hunt/ledger/render_spec_ledger.py`

- [Warning] `--check` が byte 一致になっていません。`Path.read_text()` は universal newline 変換を行うため、生成物を LF から CRLF に変えても同一文字列となり、drift を検出できません。「byte 一致」「手編集を検出」という契約から外れます。`read_bytes()` と `text.encode("utf-8")` を比較すべきです。

それ以外の fail-closed 検証、supersede 検証、原子的置換、マーカー偽装防止は設計と一致しています。

### `.claude/skills/app-bug-hunt/ledger/spec-ledger-migration.json`

- [Warning] `source_lines` が `"81-113"` ですが、提示された diff は移行前ファイルを全112行と示しています（`@@ -1,112`）。113行目は存在しないため、移行 provenance が不正確です。実際の範囲を再確認してください。

### `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py`

- [Warning] byte 不変性テストも `read_text()` 後の文字列を hash 化しています。改行コードだけ変わる破損を検出できず、「既存ファイルが1バイトも変わらない」という契約を固定できていません。`read_bytes()` の SHA-256 が必要です。

- [Warning] `test_duplicate_key_in_manifest_fails` は `block_count` を2へ変えた時点で `EXPECTED_BLOCK_COUNT=1` により失敗するため、重複鍵検査へ到達しません。同様に、見出し重複ケースも block_count 1 では空白見出しの検査へ置き換わっており、一意性検査を実際には通っていません。重複検出コードを削ってもテストが緑のままです。

- [Warning] CR/LF 注入テストが一部の欄だけです。`scope_kind`、`adjudicated_at_run`、`supersedes`、`context.spec_basis`、`context.reopen_condition` の検査が個別に退行しても緑になります。設計が要求する全出力欄の表駆動テストが必要です。

- [Warning] `SPEC_BASIS_FORM_RE` は詳細設計の閉じた拡張子集合より広く、`tsx`、`jsx`、`jsonl` を許可しています。A-003 には JSONL が必要なので、設計側で `jsonl` を正式に追加したうえで、許可・拒否集合をテストで pin してください。根拠のない `tsx` / `jsx` の追加は閉じた語彙と矛盾します。

### `.claude/skills/app-bug-hunt/spec-ledger.md`

現在の生成内容自体に不整合は見当たりません。ただし上記の改行正規化により、現物との byte 一致は保証されていません。

### `AGENTS.md`

記述は詳細設計と一致しています。renderer の drift 検出を修正すれば保証内容とも整合します。

### `docs/TODO.md`

問題ありません。必須の後続タスクは T224 として登録されています。

### 検証状況

- [Warning] `composer test`、フロントおよび package 系コマンドがまだ実行中で、AGENTS.md が要求する全コマンド green を満たした結果が提示されていません。完了結果を確認するまで承認できません。

Critical はありませんが、明示された契約を空振りするテストと byte drift の見逃しがあるため修正が必要です。

CHANGES_REQUESTED