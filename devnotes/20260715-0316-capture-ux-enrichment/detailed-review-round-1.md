以下、提示された**詳細設計書**に対するレビュー結果です（実装コード未提示のため、設計妥当性レビューとして判定）。

## S1 `camera.ts` ヘルパ拡充
**判定: APPROVE**

- [Suggestion] `supportsPauseResume()` は `window` 参照のため、将来SSR文脈でimportされる可能性があるなら「クライアント専用」注記を追加すると意図が明確です。
- [Suggestion] `formatElapsed()` の「60分以上を `mm:ss` で継続表示」は要件適合。将来 `hh:mm:ss` 変更可能性だけ TODO で残すと拡張しやすいです。

## S2 `GridOverlay.svelte` 新規
**判定: APPROVE**

- [Suggestion] z順は DOM 順依存のため、`CameraRecorder` 側で overlay 順を崩さない前提をテスト名に明示すると保守性が上がります。
- [Suggestion] 線色 `bg-surface/40` は DESIGN.md 準拠で妥当。コントラストは実機（屋外）で目視確認推奨。

## S3 録画タイマー
**判定: APPROVE**

- [Warning] `setInterval(200ms)` はバックグラウンド時の間引きが起こり得ます。  
  **修正案**: 現設計どおり真値を `performance.now()` 差分で計算しているため精度は担保済み。テストに「タイマーtick遅延でも累積値が破綻しない」ケースを1本追加してください。
- [Suggestion] `recordedDurationMs()` を `Math.max(0, …)` で明示クランプしておくと防御性が増します。

## S4 一時停止/再開（phase=paused）
**判定: REQUEST_CHANGES**

- [Critical] `recorder.onerror = () => safeStop();` のままだと、`phase === "paused"` 中の `onerror` で `safeStop` が `paused` 非対応だと停止不能になります（設計後段で対応予定だが、**実装反映漏れ時の事故が大きい**）。  
  **修正案**: `safeStop` 条件を必ず `phase === "recording" || phase === "paused"` に統一し、同時にテストで「paused 中 onerror → 停止完了」を必須化。
- [Warning] `recoverPhaseFromRecorderState()` で `inactive` を検出した際「idleへ倒さない」方針は慎重で良い一方、`onstop` が永遠に来ないUAバグで `stopping` 以外から復帰不能化の余地があります。  
  **修正案**: `inactive` かつ一定時間 `onstop` 未達時のフェイルセーフ（`fatalStopCleanup` 相当）を設ける、または方針上そうしない理由をコメントで明文化してください。
- [Suggestion] `pauseResumePending` と timeout 解放点（onpause/onresume/onstop/onerror/onDestroy）の網羅をテスト名で対応付けると回帰に強くなります。

## S5 グリッドトグル配線
**判定: APPROVE**

- [Suggestion] `aria-pressed` の更新は良いです。`gridToggleLabel` と実状態の同期を1ケース（連打）追加すると安心です。

## S6 カメラ反転（guarded・段階的縮退）
**判定: REQUEST_CHANGES**

- [Critical] `releaseCamera()` 後の再取得失敗時、段階3で旧復旧も失敗した場合に「完全喪失」を `acquirePreviewStream` に委譲する設計は妥当ですが、**ユーザーへの即時フィードバックが曖昧**になり得ます（flip操作起点なのか恒久不可なのか）。  
  **修正案**: 段階4到達時に flip起点失敗である旨の短い補助メッセージ（既存分類を上書きしない）を検討、またはテストで `onCameraUnavailable` 呼出し優先を明示して文言競合を防止。
- [Warning] `tryApplyFacing()` で `getSettings().facingMode` が `undefined` の端末では常に失敗扱い→再取得に進む設計。安全側だが再取得コスト増。  
  **修正案**: コメントに「`undefined` は未検証扱いで再取得へ倒す」理由を明記し、期待挙動としてテスト化。
- [Suggestion] `flipCamera()` は `starting/resuming/flipping/phase!=idle` ガードで十分。加えて「連続タップ時に最後の1回だけ有効」要件がなければ現状でOKです。

## S7 テスト計画
**判定: REQUEST_CHANGES**

- [Critical] 要件「既存20ケース回帰なし」に対して、設計内で `stopping` 表示方針を途中変更しており、既存テスト前提と衝突リスクが高いです。  
  **修正案**: 既存ケースを無改変で通すだけでなく、**`stopping` 中 stopボタン可視 + `safeStop` no-op** を明示追加し、方針固定してください。
- [Warning] pause/resume のイベント基準遷移で「遅延 onpause/onresume が timeout 後に到着しても二重遷移しない」回帰テストが必要です。  
  **修正案**: fake timer で timeout 後に遅延イベントを発火させ、phase不変を検証するケースを追加。
- [Warning] durationMs 意味是正（wall-clock→実録画尺）は本件の主要副作用点です。  
  **修正案**: `pause` 区間を含まない durationMs を `onCaptured` 引数で厳密検証するテストを追加。

## 全体判定
**CHANGES_REQUESTED**

主に S4/S6/S7 で、**異常系の収束保証**と**テスト固定化**をもう一段明確化すれば、全体として非常に良い設計です。  
特に「イベント基準遷移」「facingMode 段階的縮退」「durationMs 意味是正」の方向性は適切で、North Star と禁止事項8にも整合しています。