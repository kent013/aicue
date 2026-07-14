# 対応マトリクス: design-review Round 5（APPROVED）

全体判定 APPROVED。非ブロッキング Suggestion を実装時堅牢化として設計へ反映済み。

## [Suggestion] promote 例外時に未確定 tmp が残る
- 判断: 対応する（実装時）
- 対応内容: `storeStreamed`/`putStreamWithMeta` の promote 呼び出しを `finally { if (is_file($tmp)) @unlink($tmp); }` で囲む。

## [Suggestion] flock LOCK_UN 失敗の扱いを明文化
- 判断: 対応する
- 対応内容: LOCK_UN 戻り値は無視（fail-loud 対象外）とコメント明記。

## [Suggestion] concurrency テストの決定的同期
- 判断: 対応する
- 対応内容: 子プロセス同期用 pipe/marker で「ロック取得待ち」を決定的に再現（timeout 依存を避け flaky 化防止）。
