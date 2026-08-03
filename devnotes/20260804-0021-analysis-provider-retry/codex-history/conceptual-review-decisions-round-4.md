# 対応マトリクス: conceptual-review Round 4

## [Warning] `$timeout` の起点 (`handle()` 入口) と deadline の T0 (`run()` 入口) の差 P が式に無い
- 判断: **対応する (指摘どおり抜けていた)**
- 根拠: `$timeout` の pcntl_alarm は job 実行開始 (`handle()`) から計測される一方、
  deadline の T0 は `AnalysisPipeline::run()` 入口。その差
  (job payload の deserialize / DI 解決 / `AnalysisJob::findOrFail`) が `D + C + M₁ + S` に
  含まれていなかった。
- 対応内容: Codex の提案 2 案のうち **「pre-pipeline 予算 P を明示し、90 秒の安全余白 S に含める」**
  を採用した (最小変更・値の変更なし)。設計書に:
  - P の定義 (= `handle()` 入口 → `run()` 入口)
  - `startJob()` (予約作成・行ロック) は T0 の**後**なので D の内側であること
  - **受容条件 `P + その他モデル外要因 ≤ 90 秒`** を明記
  - P の内訳が単一行 SELECT + コンテナ解決でミリ秒オーダーであること
  を追記した。T0 を `handle()` 入口へ移す案は、`AnalysisPipeline` の signature 変更
  (deadline の外部注入) を招くため採らない。

## [Suggestion] 「5xx 全般」ではなく実際の集合 `500/502/503/504` に表現を統一せよ
- 判断: **対応する**
- 対応内容: 設計書中の `5xx` 表記をすべて `500/502/503/504` (+ 408) に置換した。

## [Suggestion] Feature テストは cron の実行順序が逆でも最終状態が同じことを確認できれば十分
- 判断: **対応する**
- 対応内容: 詳細設計のテスト計画に「`recoverStale` → `releaseStale` / `releaseStale` → `recoverStale`
  のどちらの順でも最終会計状態が同じ」を確認項目として書く。

## [Suggestion] 使命整合 / 禁止事項 / 実測 / 例外分類 / スコープ・型安全性
- 判断: **そのまま維持** (いずれも「妥当」との評価)。
