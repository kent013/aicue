**ファイル別レビュー**

- `app/Services/Manual/VideoManualService.php`
  - **判定**: OK
  - `failed && snapshot!==null && manual.scenario_version > snapshot` をそのまま `isStaleFailure()` で実装しており、設計要件と一致。
  - `snapshot === null` を not-stale 扱いにして表示維持する方針も要件どおり（legacy保守）。
  - `displayAnalysisJob` / `displayRenderJob` / `displayPreviewJob` の分離で責務が明確。`playbackJobId` 非対象方針とも整合。

- `app/Http/Controllers/Projects/VideoManualController.php`
  - **判定**: OK
  - show の job 解決を Service に委譲し、Inertia props だけを組み立てる薄い Controller を維持。
  - `response()->json()` 直書きなし。DTO (`AnalysisJobData`, `RenderJobData`) パターン維持。

- `app/Services/Manual/AnalysisJobService.php` / `app/Services/Manual/RenderJobService.php`
  - **判定**: OK
  - fail確定時 snapshot (`scenario_version_at_terminal`) 書込みをトランザクション内で実施しており、stale判定の時系列基準がDB権威で固定される。
  - terminal guard の no-op 維持で冪等性を崩していない。
  - lock 順序（job→manual）を明示しており、既存並行性契約への配慮がある。

- `database/migrations/2026_07_14_020000_add_scenario_version_at_terminal_to_job_tables.php`
  - **判定**: OK
  - nullable 追加 + down で drop の最小変更。既存データ互換（legacy null）前提と一致。

- `app/Services/Manual/SopTextExtractor.php` / `app/Exceptions/Manual/AnalysisFailedException.php`
  - **判定**: OK
  - 「抽出不能」と「抽出はできたが短すぎ」を分離できており F-1-2 に一致。
  - 既存 `unextractable()` 意味論を壊さず `tooShort()` 追加で後方安全。

- `resources/js/pages/Manuals/Create.svelte`
  - **判定**: OK
  - `oninput` で page 側 errors を即時クリアする変更は UX 改善として妥当。
  - atom 側状態追加なし、層逆流なし、DS逸脱（hex直書き等）なし。

- テスト群（Feature/Unit/Vitest/Architecture）
  - **判定**: OK
  - stale/not-stale/legacy/succeeded/render/preview独立/CTA保持/no-op save 経路まで行列が十分。
  - snapshot 書込みと terminal 冪等 no-op も固定できている。
  - テスト結果（PHPStan lv10含む）から widen/ignore/baseline 逃げなし。

**Critical**
- なし

**Warning**
- なし

**Suggestion**
- `VideoManualService::isStaleFailure()` は将来 Render/Analysis 以外でも再利用しうるため、仕様コメントに「`>` であり `>=` ではない理由（同世代失敗は表示維持）」を一文追加すると保守性がさらに上がります（任意）。

**全体判定**
- **APPROVED**