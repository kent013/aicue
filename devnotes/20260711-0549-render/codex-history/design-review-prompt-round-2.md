Round 1 の全指摘（Critical 3 + Warning 3 + Suggestion 3）に対応し、詳細設計を改訂しました。
対応マトリクスと改訂後の該当箇所を示します。再レビューをお願いします。

## 対応マトリクス（Round 1 指摘 → 対応）

1. [Critical] ロック順の不整合 → **対応**。グローバル順を
   `render_jobs → video_manuals → ticket_reservations → organizations` に固定し、
   全 7 経路（trigger / triggerPreview / startJob / buildManifest / finalize / failJob /
   DeleteRenderOutputsJob）を「グローバル順の部分列のみ」の表に再構成。
   **正本は docs/architecture.md のロック順序節**（施策 16）とし、RenderPipeline docblock は
   同一文面を転記（単一真実源）。analysis_jobs と render_jobs は同一 tx で両ロックする経路が
   存在しないため相対順は定義不要と明記

2. [Critical] failed() の error_code Timeout 固定 → **対応**。既定を
   `RenderErrorCode::Internal` に変更し、`Illuminate\Queue\TimeoutExceededException` と
   判別できる場合のみ Timeout。recoverStale（閾値超過）は Timeout のまま

3. [Critical] ASS エスケープ仕様不足 → **対応**。AssSubtitleWriter に追加:
   不可視制御文字の包括除去（C0/C1 + U+200B-200F / U+202A-202E / U+2060-2064 / U+FEFF）、
   長さ上限（1 行 100 文字・総 500 文字。超過は切り詰め + 構造化ログ）、BOM なし UTF-8 固定。
   Unit テストに zero-width・極端長文を追加

4. [Warning] download filename の責務曖昧 → **対応**。temporaryDownloadUrl の契約を明文化
   （CR/LF・制御文字除去、RFC 5987 filename* + ASCII fallback 両建て、ヘッダ注入不能の Unit テスト）

5. [Warning] RenderPanel の 2 系統ポーリング競合 → **対応**。1 コンポーネント内で
   scheduler 1 本化（単一 $effect + setInterval が追跡中 job id 集合を管理、終端条件のみ
   kind 別分岐、router.reload() は render 終端のみ発火）

6. [Warning] reconcileOutputs の可観測性 → **対応**。command 出力に dispatched/skipped 件数、
   prefix 不一致は構造化ログ（context: render_job_id / output_path）

7. [Suggestion] StatusNotRenderable の文脈差 → **対応**。`StatusNotPreviewable` case を追加
   （render/preview で message をサーバ確定。TS union は 4 値:
   in_flight | status_not_renderable | status_not_previewable | org_preview_limit）

8. [Suggestion] 尺上限と時間 budget の連動注記 → **対応**。RenderTimeBudgetInvariantTest
   docblock に「render_max_total_source_ms 引き上げ時は timeout 1500s との整合を実測で
   再確認」を明記

9. [Suggestion] JobStatus 共用の drift 検知 → **対応**。ManualEnumTsSyncInvariantTest の
   対象に AnalysisJobStatus（JobStatus 共用 union）を追加

## 改訂後の該当箇所（抜粋）

### ロック順（概念設計リファレンス節）
```
グローバルロック順（単一真実源）: render_jobs → video_manuals → ticket_reservations → organizations
- 正本は docs/architecture.md のロック順序節。RenderPipeline docblock は同一文面を転記
- 全経路はグローバル順の部分列のみ:
  trigger: video_manuals のみ / triggerPreview: video_manuals → organizations /
  startJob: render_jobs → (reserve 内部: organizations) / buildManifest: video_manuals /
  finalize: render_jobs → video_manuals → (commit 内部: ticket_reservations → organizations) /
  failJob: render_jobs → video_manuals → (release 内部: 同上) /
  DeleteRenderOutputsJob: render_jobs のみ
```

### RunManualRender::failed()
```php
$code = $exception instanceof \Illuminate\Queue\TimeoutExceededException
    ? RenderErrorCode::Timeout
    : RenderErrorCode::Internal;
app(RenderJobService::class)->failJob($job, $code, '書き出しが中断されました。再実行してください。');
```

### AssSubtitleWriter 正規化（4-6 追加）
4. 不可視制御文字の包括除去: C0/C1 + zero-width 系 (U+200B-200F, U+202A-202E, U+2060-2064, U+FEFF)
5. 長さ上限: 1 行最大 100 文字・総 500 文字。超過は切り詰め + 構造化ログ
6. 出力は BOM なし UTF-8 固定

### RenderObjectStorage::temporaryDownloadUrl 契約
filename は CR/LF・制御文字を除去し、Content-Disposition は RFC 5987 (filename*=UTF-8''...) +
ASCII fallback (filename="...") の両建てで署名に含める。ヘッダ注入不能を Unit テストで固定

### RenderConflictType（4 値）
in_flight / status_not_renderable (render: ready 以外) / status_not_previewable
(preview: analyzing・rendering 中) / org_preview_limit

### RenderPanel ポーリング
単一 $effect + setInterval が追跡中 job id 集合（render / preview）を保持し周期ごとに順に
fetch。終端条件のみ kind 別分岐（render: succeeded → router.reload() / preview: succeeded →
video 表示）。reload は render 終端でのみ発火

【出力形式】（Round 1 と同じ）
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
