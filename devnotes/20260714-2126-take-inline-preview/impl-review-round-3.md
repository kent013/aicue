開始押下〜録画確立の窓については修正されています。`syncActive()` により true の重複通知や失敗時の false 復帰も正しいです。

### `CameraRecorder.svelte`

**Critical**

ただし、S4 全体の排他契約には `resuming` 中の同型 race が残っています。

1. preview close → `resumeAfterPreview()` が `getUserMedia` 待ち
2. `resuming=true` だが公開 active は `false`
3. preview を再度開ける
4. `releaseForPreview()` は `stream === null` のため解放できない
5. pending の取得が完了し、preview 背後で camera stream が復帰する

さらに、この間に録画開始を押すと `resumeAfterPreview()` と `startRecording()` がそれぞれ `getUserMedia` を呼び、二重取得・片方の stream 漏洩が起こり得ます。

公開 active とガードを、少なくとも以下へ統一する必要があります。

```ts
starting || resuming || phase !== "idle"
```

- `resuming` 開始・終了時にも `syncActive()`
- `startRecording()` は `resuming` 中を拒否
- `releaseForPreview()` も `resuming` 中を拒否

### `CameraRecorder.test.ts`

**Warning**

以下の回帰テストが必要です。

- resume pending 中は active=true で preview 再オープン不可
- resume pending 中の録画開始で二重 `getUserMedia` が発生しない
- resume 成功・失敗後に active=falseへ戻る

Round 2 の starting raceへの対応自体は正当ですが、同じ排他境界に resume が含まれていません。

最終判定: **CHANGES_REQUESTED**