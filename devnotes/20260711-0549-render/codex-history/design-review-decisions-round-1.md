# 対応マトリクス: design-review Round 1

## [Critical] ロック順の記述と実装想定が不整合（施策 4/8）
- 判断: 対応する
- 対応内容: グローバル順を `render_jobs → video_manuals → ticket_reservations → organizations`
  に固定し「全経路はこの部分列のみ」の表へ再構成（DeleteRenderOutputsJob 含む 7 経路）。
  正本は docs/architecture.md のロック順序節とし、RenderPipeline docblock は同一文面を転記
  （単一真実源化）。analysis_jobs と render_jobs は同一 tx で両ロックする経路が存在しない
  ため相対順は定義不要と明記

## [Critical] RunManualRender::failed() の error_code が Timeout 固定（施策 8）
- 判断: 対応する
- 対応内容: failed() の既定を `RenderErrorCode::Internal` に変更し、
  `Illuminate\Queue\TimeoutExceededException` と判別できる場合のみ Timeout。
  recoverStale（閾値超過 = 実際のタイムアウト）は Timeout のまま

## [Critical] ASS エスケープ仕様の不足（施策 6/7）
- 判断: 対応する
- 対応内容: AssSubtitleWriter の正規化仕様に追加: (4) 不可視制御文字の包括除去
  （C0/C1 + U+200B-200F / U+202A-202E / U+2060-2064 / U+FEFF）、(5) 長さ上限
  （1 行 100 文字・総 500 文字。超過は切り詰め + 構造化ログ）、(6) BOM なし UTF-8 固定。
  Unit テストに zero-width・極端長文・BOM 検証を追加

## [Warning] download filename の安全化責務が曖昧（施策 10）
- 判断: 対応する
- 対応内容: `RenderObjectStorage::temporaryDownloadUrl()` の契約として明文化:
  CR/LF・制御文字除去、RFC 5987（filename*）+ ASCII fallback（filename）の両建て、
  ヘッダ注入不能の Unit テスト追加

## [Warning] RenderPanel のポーリング 2 系統の重複更新競合（施策 14）
- 判断: 対応する
- 対応内容: 1 コンポーネント内で scheduler を 1 本化（単一 $effect + setInterval が追跡中
  job id 集合を保持）。終端条件のみ kind 別分岐、router.reload() は render 終端のみ

## [Warning] reconcileOutputs の可観測性不足（施策 9）
- 判断: 対応する
- 対応内容: command 出力に dispatched/skipped 件数、DeleteRenderOutputsJob の prefix 不一致は
  構造化ログ（context: render_job_id / output_path）

## [Suggestion] StatusNotRenderable の文脈差
- 判断: 対応する。`StatusNotPreviewable` case を追加し message をサーバで文脈別に確定
  （TS union も 4 値へ更新）

## [Suggestion] 尺上限と時間 budget の連動注記
- 判断: 対応する。RenderTimeBudgetInvariantTest の docblock に注記

## [Suggestion] JobStatus 共用部分の drift 検知
- 判断: 対応する。ManualEnumTsSyncInvariantTest の対象に AnalysisJobStatus を追加
