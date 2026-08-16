# 対応マトリクス: conceptual-review Round 4

Codex 判定: **APPROVED** (Critical 0 / Warning 0 / Suggestion 7)。

## [Suggestion] 最終失敗にも同じ `stage` / `failure_category` / `failure_path` を保持できることをテストで固定せよ
- 判断: **対応する (詳細設計 M4 に反映)**
- 根拠: 現行 `AnalysisPipeline` は**再試行のときだけ**構造化ログを出し、打ち切り時は
  `report($exception)` + `failJob` で終わる。これだと「validation 起因の**最終失敗数**」という
  評価指標が集計できず、概念設計で自分が置いた観測条件を満たせない。
- 対応内容: 詳細設計 M4 に、`run()` の catch で `LlmOutputInvalidException` のときだけ
  同じ固定キー (`failure_category` / `failure_path` + `analysis_job_id`) の 1 行を出す変更を追加し、
  テスト計画に「3 試行すべて validation 違反のとき最終失敗ログが 1 行出る」を追加した。
  `stage` は `analysis_jobs.step` 列から分かるため重複させない。

## その他 Suggestion (使命 / 禁止事項 / 実現可能性 / 期待効果 / リスク / スコープ / 型安全性)
- 判断: 受領 (変更なし)。array shape と `list<T>` の確定は詳細設計 M2/M5/M6 で実施済み。
