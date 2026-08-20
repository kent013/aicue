### `.claude/skills/app-bug-hunt/ledger/test_t224_a001_watch_globs.py`

**指摘あり**

- **Warning**: `test_active_successor_of_a001_is_a004_and_watches_toast_ts` は、後継の ID が `A-004` であることを検証していません。A-001 を直接 supersede する唯一の登録が A-005 などへ置き換わっても通ります。`successor["adjudication_id"] == "A-004"` を明示的に固定する必要があります。

- **Warning**: A-001 の `context` が不変であることは依然として検証されていません。詳細設計は機械項目だけでなく `context` も変更しないよう要求していますが、テストは明示的に `context` を除外しています。現在の実装では、A-001 の `context` が削除・改変されても成功します。`context` を含む A-001 の全レコードを期待値と比較するか、別途その完全な期待値を固定してください。

直接の supersede 関係、active 状態、機械項目の一致、`toast.ts` の包含を同一レコードに対して検証する改善は有効です。ただし、上記2点により Round 1 の指摘は完全には解消されていません。

CHANGES_REQUESTED