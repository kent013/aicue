# 詳細設計レビュー Round 2

Round 1 の Critical 2 / Warning 3 に対応し詳細設計を更新しました。判定を返してください。

## 施策3 [Critical] `latest('id')` 依存 → 設計注記で固定
`latest('id')` は**既存 `VideoManualController::show` と同一**の「最新」定義（現行も
`analysisJobs()->latest('id')->first()`）。job の id はアプリ内 auto-increment・単調増加でバックフィル無し。
`created_at` へ変えると既存挙動からの逸脱・回帰リスク。→ 「最新 = id 降順」を施策3 に明記し固定した。

## 施策7 [Critical] テスト先行の実行保証 → 番号付き実装順に固定
最初に追加する失敗テスト名と red→green 順を明記:
1. 7-C-1（SopTextExtractor 短文文言）→ 施策5
2. 7-B-1（analysis failJob snapshot）→ 施策1+2
3. 7-B-2（render/preview failJob snapshot・preview status 不変）→ 施策2
4. 7-A-1（HIGH 本丸: 解析失敗後 save で analysis.job=null）→ 施策3+4
5. 7-A-2..7（判定行列残り）
6. 7-D-1（タイトルクリア）→ 施策6
7. 7-E（パネル job=null 非退行, 既存 green 維持）

## 施策5 [Warning] `strlen`(bytes) 判定 → 仕様として byte 基準を明記
`analysis_min_text_bytes`(=100) は `analysis_max_text_bytes` と対の **token budget 整合の byte 基準**
（`AnalysisTokenBudgetInvariantTest` が byte 算術を固定）。閾値見直し・`mb_strlen` 化はスコープ外。
本施策は**分岐先の例外種別と文言のみ**を変更し、ユーザー文言は byte 値を露出しない汎用表現とした。

## 施策7 [Warning] save 実経路との接続が薄い → 統合ケース追加
判定行列の最低 1 ケースを **`ScenarioService::save()` 実経路**（no-op 保存 → version++ → analysis.job=null）で
通し、「保存世代基準」契約と実装のズレを固定するケースを 7-A に追加した。

## 施策2 [Warning] preview 失敗時の manual lock 追加 → テストで status 不変を固定
7-B-2 に「preview 失敗では manual.status 不変（snapshot のみ記録）」の assertion を明記。lock 順は job→manual で不変。

## 質問
上記対応で施策3/5/7 の Critical/Warning は解消し、全体 APPROVED として差し支えないか。残があれば指摘してください。
