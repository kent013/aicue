# 概念設計レビュー Round 4 — Round 3 指摘への対応報告

## [Warning] real-llm 時も TESTING_FAKE_LLM を明示注入 → 対応
- real-llm (既定/--real-llm) は `TESTING_FAKE_LLM=false` を、--fake-llm は `true` を **常に env -i へ明示注入**。
  TESTING_FAKE_STORAGE も既定 `true` / --real-storage `false` を常に明示注入。残留 dotenv/親環境による反転を防止。
- self-test で両モードの実効注入値 (real→false / fake→true / real-storage→false) を固定。

## [Suggestion] --real-llm と --fake-llm の同時指定を fail-fast → 対応
- 引数解析でモード相互排他を fail-fast。

これで両モードの実効値が script により完全決定されます。全体判定をお願いします。
