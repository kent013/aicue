# 対応マトリクス: design-review Round 1

全体 CHANGES_REQUESTED。Critical 2 / Warning 3 に対応する。

## [Critical] 施策3 `latest('id')` が最新の定義に依存
- 判断: **根拠提示 + 設計注記**
- 根拠: `latest('id')` は**既存 `VideoManualController::show` と同一**の「最新」定義（現行コードが
  `analysisJobs()->latest('id')->first()` / render/preview も同様）。job はアプリ内で採番される
  auto-increment id のみで、バックフィル等の非時系列挿入経路は無い。ここを `created_at` に変えると
  既存挙動からの逸脱になり、かえって回帰リスク。
- 対応内容: 施策3/4 に「本ドメインの最新 = id 降順（既存 controller と一致、job の id は単調増加で
  バックフィル無し）」を明記し `latest('id')` を固定。

## [Critical] 施策7 テスト先行の実行保証が宣言止まり
- 判断: **対応する**
- 対応内容: 各テストに「最初に追加する失敗テスト名」と実装順（red→green）を固定した番号付きリストを追記。

## [Warning] 施策5 `strlen`（bytes）基準の短文判定が日本語で厳しすぎる懸念
- 判断: **仕様明確化（bytes 基準を意図として維持）**
- 根拠: `analysis_min_text_bytes`（=100）は **token budget と同じ byte 基準**（`analysis_max_text_bytes`
  と対。`AnalysisTokenBudgetInvariantTest` が byte 算術を固定）で、閾値の見直しは概念設計でスコープ外と
  合意済み（「文言の弁別のみ」）。100 bytes ≈ 日本語 33 文字程度で下限として妥当。本施策は**分岐先の
  例外種別と文言のみ**を変え、判定基準（bytes）は不変。
- 対応内容: 施策5 に「判定は byte 基準を意図的に維持（token budget 整合）。ユーザー文言は byte を
  露出しない汎用表現」と明記。閾値変更・`mb_strlen` 化はスコープ外。

## [Warning] 施策7-A が save 実経路（no-op でも version++）契約と疎
- 判断: **対応する**
- 対応内容: 7-A に「最小 1 ケースは `ScenarioService::save` 実経路を通す統合ケース」（no-op 保存 →
  version++ → 失敗 null 化）を追加し、保存世代基準の契約とのズレを防ぐ。

## [Warning] 施策2 preview 失敗時の manual lock 追加
- 判断: **対応する（テスト固定 + 許容根拠）**
- 根拠: preview 失敗は頻度が低く、正しい stale 判定には失敗時 version の snapshot が必須。lock 順は
  job→manual で不変。
- 対応内容: 7-B に「preview 失敗でも manual.status は不変（snapshot のみ入る）」の明示 assertion を追加。
