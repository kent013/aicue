### `CameraRecorder.svelte`

**Critical**

`starting` ガード単体では排他契約を満たしません。次の競合が残ります。

1. 録画開始押下 → `starting=true`、`phase="idle"`
2. `captureActive` はまだ `false`
3. 再生押下 → TakeStrip はプレビューを開く
4. `releaseForPreview()` は no-op
5. `getUserMedia` 完了後、背後で録画が開始される

結果としてプレビューと録画が同居し、S4 の排他契約に違反します。

`starting` を外部の active 状態にも含め、開始処理に入った時点で `onCaptureActiveChange(true)`、取得・開始失敗時に `false` を通知する必要があります。phase に `starting` を追加するか、公開 active を `starting || phase !== "idle"` として一元管理するのが妥当です。

### `CameraRecorder.test.ts`

**Warning**

追加テストは「stream を解放しない」ことだけを確認し、その後録画開始へ進むことを成功扱いしています。しかし実際にはプレビューが既に開いているため、問題の競合を固定してしまっています。

以下の検証が必要です。

- getUserMedia pending 中は `onCaptureActiveChange(true)`
- TakeStrip 側ではプレビューを開かない
- 開始失敗時は active が `false` に戻る
- 開始成功時は recording への遷移で `true` を重複通知しない

`adoptFromPreview()` の見送り理由は妥当で、追加変更は不要です。

最終判定: **CHANGES_REQUESTED**