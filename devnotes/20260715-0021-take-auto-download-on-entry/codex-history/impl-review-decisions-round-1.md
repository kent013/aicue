# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Round 1）。Critical なし。以下は Warning/Suggestion への対応。

## [Warning] maxRetries / baseDelayMs に負値ガードがない (auto-download.ts)
- 判断: 対応する
- 根拠: 低コストで堅牢性向上。負値は無意味であり、意図を明示できる。
- 対応内容: コンストラクタで `Math.max(0, options.maxRetries ?? 2)` / `Math.max(0, options.baseDelayMs ?? 1000)` にクランプ。総試行 = 1 + maxRetries が最低 1 を保証。

## [Warning] Content-Length 非安全整数の専用境界テストが未明示 (auto-download.test.ts)
- 判断: 対応する
- 根拠: 詳細設計「非数値/負数/非安全整数はスキップ」の境界を固定すべき。実装は対応済みだがテストで退行を防ぐ。
- 対応内容: `Content-Length: "99999999999999999999"` (>MAX_SAFE_INTEGER) で size 検査をスキップし ACK するケースを追加。

## [Suggestion] collectTargets で採用一致テイクを break する
- 判断: 見送る
- 根拠: 採用は各カット高々 1 テイクという不変条件はサーバ側が保証。break は微小最適化で、現状の全走査でも正しく安全。過剰な最適化を避ける（思考原則: 今必要なものだけ）。

## [Suggestion] toFailureReason に `Error && name==="AbortError"` も許容
- 判断: 見送る
- 根拠: 設計が `DOMException && name==="AbortError"` を明示。標準環境では DOMException で十分。分岐を増やすと設計との差分になる。

## [Suggestion] runAutoDownload の失敗時ログ / run reject 時 UI 非破壊テスト
- 判断: 見送る（一部将来検討）
- 根拠: `run()` は内部で fetch/ACK の例外を捕捉し reject しない設計（ackWithRetry の try/catch・fetchAndDrain の try/catch）。fire-and-forget が UI を落とすことはない。観測性ログは v1 スコープ外（過剰実装回避）。

## [Suggestion] S3 CORS expose headers の runbook 参照 / tsd 型テスト
- 判断: 見送る
- 根拠: 施策 6 は本設計内に受け入れ条件として明記済み。docs/architecture.md にも CORS 条件を追記済み。tsd 導入は依存追加を伴い過剰。
