提示 diff とテスト結果のみでレビューしました。コマンド実行・ファイル書き込みはしていません。

**ファイル別判定**

`app/Models/VideoManual.php`  
指摘なし。`coverCut()` は設計どおり、候補選択だけを relation に閉じており、`TakeStatus::Ready` 判定を持ち込んでいません。`sort_order` → `id` の順序、`thumbnail_path` 非 null 条件、T148 との分離も妥当です。

`app/Support/Security/AdoptedTakeReferenceInventory.php`  
指摘なし。`Models/VideoManual.php` の新規参照登録と controller 側 rationale 更新は妥当です。`adoptedTake` と `TakeStatus::Ready` の同居も避けられています。

`app/DataTransferObjects/Capture/CaptureManualCoverData.php`  
指摘なし。URL を props に載せず `cut_id` / `take_id` だけにする設計と一致しています。

`app/DataTransferObjects/Capture/CaptureManualSummaryData.php`  
指摘なし。`canViewCover=false` で `coverCut` に触れない早期 return は重要な実装で、権限なし利用者の N+1 と 403 画像の両方を避けています。`AdoptedReadyTakeCoverage` への委譲も設計どおりです。

`app/Http/Controllers/Capture/CaptureManualController.php`  
指摘なし。層順序は `404 テナント境界 → view 認可 → capture 可視性判定` のままで、`Gate::allows('capture', $project)` を project 単位 1 回にしている点も妥当です。

`resources/js/types/capture.ts`  
指摘なし。`cover` 追加は DTO と整合しています。`status` を復活させていない既知差異も、T197 後の現行仕様優先として妥当です。

`resources/js/components/features/capture/ManualCoverThumbnail.svelte`  
指摘なし。DS token / lucide / placeholder fallback / `loading="lazy"` は設計に合っています。失敗 URL 単位で記憶する実装も、差し替え再試行テストで固定されています。

`resources/js/pages/Capture/Index.svelte`  
指摘なし。URL 組み立てを `takeUrl()` に寄せ、UI 側の判断を `cover === null` だけにしている点は設計どおりです。grid 化もサムネイル追加後のレイアウトとして妥当です。

`tests/Architecture/CurrentRenderArtifactInventoryTest.php`  
[Suggestion] `ofMany` / `hasOne` の file-level count を 2 にする変更は、今回の `coverCut()` 追加に対して妥当で、ただちに widen とは見ません。`succeeded` 条件を 1 のまま固定し、`latestSucceededRender` と `coverCut` の名前 pin を追加しているため、T154 の主要な検出力は維持されています。さらに強めるなら将来、関数 body 単位で `latestSucceededRender()` 側の `RenderJob` / `Succeeded` / `ofMany` を検査するとより堅いですが、今回の完了条件では必須ではありません。

`tests/Feature/Capture/CaptureCoverThumbnailTest.php`  
指摘なし。選択規則、タイブレーク、候補なし、ready 不一致、配信可能性、権限差、cross-org / cross-project 境界、props 形まで押さえられています。fail-first の判別力も十分です。

`tests/Feature/Capture/CaptureManualListQueryCountTest.php`  
指摘なし。権限あり・候補混在・権限なしの 3 系統で N+1 を固定しており、設計リスクに対応しています。

`tests/Feature/Capture/CaptureManualBrowsingTest.php`  
指摘なし。summary shape の `cover` 追加は妥当です。

`tests/js/components/features/capture/ManualCoverThumbnail.test.ts`  
指摘なし。画像表示、placeholder、error fallback、src 差し替え再試行が揃っています。

`tests/js/pages/CaptureIndex.test.ts`  
指摘なし。`takeUrl()` 由来の URL 規則と null placeholder の両方を固定できています。

`docs/architecture.md`  
指摘なし。代表選択、配信 route 再利用、props に URL を載せないこと、保証しない範囲まで明記されています。

全体として、詳細設計の施策 1〜10 は実装されています。既知差異 2 件も妥当です。DTO / Inertia props パターン、T148、認可層順序、DESIGN.md / Atomic Design についても問題は見当たりません。

APPROVED