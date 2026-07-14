# 対応マトリクス: conceptual-review Round 3

## [Warning] real-llm 時に TESTING_FAKE_LLM を注入しないと script が正本にならない (残留 env で fake 反転)
- 判断: 対応する
- 根拠: env -i 隔離でも、dotenv (.env.bughunt.local) や親環境に残留 TESTING_FAKE_LLM=true があると、
  非注入だと既定走行が fake に反転しうる。両モードで値を完全決定すべき。
- 対応内容: real-llm (既定/--real-llm) でも TESTING_FAKE_LLM=false を明示注入、--fake-llm は true。
  TESTING_FAKE_STORAGE も既定 true / --real-storage false を常に明示注入。self-test で両モードの実効注入値を固定。

## [Suggestion] --real-llm と --fake-llm の同時指定を fail-fast
- 判断: 対応する
- 対応内容: 引数解析でモード相互排他を fail-fast する旨を施策 3 に追記。
