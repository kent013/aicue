# 対応マトリクス: design-review Round 4

## [Critical] 施策 6: Queueable trait と `$connection` プロパティ再宣言の衝突
- 判断: 対応する（修正案どおり）
- 対応内容: `public string $connection = ...` のプロパティ宣言を削除し、コンストラクタで
  `$this->onConnection('database-analysis')` を呼ぶ形に変更（typed 再宣言は trait composition
  エラーになる旨をコメントで明記）。

## [Warning] 「sync では影響なし」の運用ノートが不正確・worker 未起動時の滞留
- 判断: 対応する（修正案どおり）
- 対応内容: 運用ノートを訂正: connection 明示 job は `QUEUE_CONNECTION=sync` でも
  database-analysis へ投入される（専用 worker 不在なら滞留。滞留は stale 回復 cron が
  30 分で failJob するため監視で気づける）。ローカル/テストの検証は
  「パイプライン同期実行 = `AnalysisPipeline::run()` 直接呼び出し / dispatch 検証 =
  `Queue::fake()`」と明記。**本番/ステージングの worker プロセス定義・デプロイ手順・監視対象に
  `queue:work database-analysis` を必須登録**する運用契約を施策 6 と施策 13
  （docs/architecture.md への転記）に追加。

## [Suggestion] connection/queue 名の drift 検出
- 判断: 採用する
- 対応内容: `AnalysisTimeBudgetInvariantTest` に「job->connection === 'database-analysis' /
  connections.database-analysis.queue === 'analysis' / driver === 'database'」のテストを追加。
