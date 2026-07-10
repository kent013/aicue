## Critical(マージ阻止。修正必須)
- [C1] `app/Services/Manual/AnalysisPipeline.php`: `runExtractStep()` で `SourceDocument` をロックせずに `extracted_json` を上書きしているため、同一 `SourceDocument` を参照する複数 `AnalysisJob` 並走時に「後勝ち」で監査スナップショットが破壊される可能性があります / 根拠: `trigger()` は in-flight を `video_manual_id` 単位でしか制限しておらず、同一 SOP を複数 manual が共有し得る設計(immutable append, latest勝ち)の中で `runExtractStep()` は単純 `save()` / 修正案: `SourceDocument` を per-job にコピーするか、`analysis_jobs` 側に抽出スナップショット列を持たせてジョブ単位保存に変更（少なくとも `source_document_id` 単位 in-flight 制御 or 行ロック + 楽観バージョンで衝突検知）。

## Warning(マージ可だが対応推奨)
- [W1] `app/Services/Manual/AnalysisJobService.php`: `recoverStale()` は `failJob()` が terminal/no-op でも `$recovered++` するため、実際に回復していない件数を「recovered」と表示しうる / 根拠: `failJob()` 戻り値なし、`recoverStale()` は常にカウント加算 / 修正案: `failJob()` を `bool` 戻り値(状態遷移有無)にして加算条件化。
- [W2] `resources/js/components/features/manual/AnalysisPanel.svelte`: `poll` の `!res.ok` を完全黙殺しており、401/419 時にユーザーへ復帰導線が出ない / 根拠: `if (!res.ok) return;` のみ / 修正案: 401/419 は `errorMessage` 設定＋ポーリング停止（再ログイン/再読込案内）を追加。
- [W3] `app/Services/Manual/SopTextExtractor.php`: `file_put_contents($path, $contents)` の戻り値未検証で、書き込み失敗時に `IOFactory::load()` 側例外へ依存 / 根拠: 返り値未使用 / 修正案: `Assert::integer(file_put_contents(...))` 等で明示検証。

## Suggestion(任意)
- [S1] `tests/Feature/Projects/AnalysisPipelineTest.php`: 競合系はかなり厚いので、`同一 source_document_id を別 manual で同時解析` ケースを1本追加すると C1 の再発防止に効きます。
- [S2] `app/Services/Manual/AnalysisPipeline.php`: `withBoundedRetry()` はバックオフなし固定再試行なので、将来 provider 側過負荷時に短時間バーストしやすいです。軽いジッター付き sleep を検討余地あり。

## 総評
マージ判断は**現時点では見送り（Critical 1件）**です。  
並行制御・2フェーズ課金・terminal tx原子化・nested route 404・UserInput経由・テスト網羅は全体として非常に高品質で、直前修正2件（`AnalysisPanel` のポーリング依存縮小と `template-divergence` 更新）も妥当です。  
ただし `SourceDocument.extracted_json` のジョブ間競合は監査整合性の根幹に触れるため、ここだけは main マージ前に塞ぐべきです。