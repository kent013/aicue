### `CameraRecorder.svelte`

**判定: OK**

- 公開 active が `starting || resuming || phase !== "idle"` に一元化されています。
- starting/resuming 開始時に同期的に true を通知し、grant 待ち中の preview 開放を防止します。
- `startRecording()` と `releaseForPreview()` の双方が resuming を拒否し、二重 `getUserMedia` と取得中 stream の横取りを防止します。
- 成功・失敗の両経路で active=false に復帰し、再試行可能性も維持されています。
- `lastActive` により starting→recording の true 重複通知もありません。

**Suggestion**

`onCaptureActiveChange` のコメントが「phase !== idle」となっているため、実際の定義である starting/resuming を含む表現へ更新すると契約が明確です。動作上の問題ではありません。

### `CameraRecorder.test.ts`

**判定: OK**

starting/resuming の両 grant 窓について、active 通知、解放拒否、録画開始拒否、二重取得防止、成功・失敗後の解除、再試行まで網羅されています。既存の TakeStrip active テストと合わせ、結合契約も十分固定されています。

Critical / Warning はありません。

最終判定: **APPROVED**