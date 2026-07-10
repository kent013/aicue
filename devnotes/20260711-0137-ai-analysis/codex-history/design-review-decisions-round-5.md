# 対応マトリクス: design-review Round 5

## [Warning] 予約 TTL のハードコード（30 * 60）では台帳変更を検出できない
- 判断: 対応する（修正案どおり。設計へ反映済み）
- 対応内容: `AnalysisTimeBudgetInvariantTest` を「固定時刻（travelTo）で台帳の公開 API
  `reserve()` を実行し、`expires_at − now` を実 TTL として時間 budget 連鎖を検証する」形へ変更。
  private 定数の複製を排し、台帳側の TTL 変更をテストが実際に検出できるようにした
  （台帳実装は変更しない = 公開 API 経由の契約固定）。

## [Suggestion] AnalysisPipelineTest の「sync queue」表現
- 判断: 採用する
- 対応内容: テスト計画の記述を「`AnalysisPipeline::run()` の直接呼び出し」に統一
  （施策 6 の運用ノート・施策 12 の共通セットアップも同語に揃えた）。

## ラウンド上限の扱い（記録）
- 詳細レビューは Round 5 で残指摘が上記 Warning 1 件 + Suggestion 1 件まで収束
  （Round 5 講評: 「残る問題は、TTL 不変条件テストが実際の台帳設定と接続されていない点だけ」）。
- いずれもレビュアーの修正案をそのまま設計へ反映し、**新規論点のない「反映確認」交換**
  （design-review-prompt-round-6 / detailed-review-round-6）で **APPROVED** を取得。
- ラウンド算入の考え方: Round 2 は Codex 側の bwrap 制約で改訂本文を読めず暫定判定のみ
  （実質的な全文レビュー不成立）。Round 6 は合意済み修正の確認であり新規合議ではない。
  詳細は design-review-decisions-round-6.md を参照。
