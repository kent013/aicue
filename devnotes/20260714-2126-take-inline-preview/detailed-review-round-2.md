## 施策別判定

### S1: per-take プレビュー再生エンドポイント — APPROVE

前回指摘は解消されています。

- 302 を DTO/JsonResource 対象外とする判断は既存 playback と整合。
- nested scope binding → org 整合 → 認可、の順序も妥当。
- ready 限定、署名対象 path の固定、team 文脈テストも十分です。

### S2: `TakePreviewDialog.svelte` — REQUEST_CHANGES

- [Critical] `take?.id` の `$effect` は初回実行時にも `teardownVideo()` を呼びます。また、DOM上の `src` 属性を直接削除しても、Svelte側の `playbackUrl` が変化しなければ再設定されない可能性があります。結果として初回表示または差し替え後に動画が空になる設計です。

  修正案: `<video>` を `{#key take?.id}` で囲み、take変更時は要素そのものを再生成してください。teardown は effect cleanup に寄せると安全です。

```svelte
{#key take?.id}
    <video
        bind:this={video}
        controls
        playsinline
        src={playbackUrl ?? undefined}
    />
{/key}
```

```ts
$effect(() => {
    if (!open || take === null) return;

    return () => {
        teardownVideo();
    };
});
```

これにより close、採用成功、take差し替え、component破棄を同じ cleanup で扱えます。

### S3: `TakeStrip.svelte` — APPROVE

前回指摘は解消されています。

- ready のみボタン表示と非ready理由の表示は、disabled禁止と両立。
- 録画中の押下時エラーも適切。
- previewとdownloadの責務分離も明確です。

### S4: 録画排他・資源解放 — REQUEST_CHANGES

- [Critical] 「start例外 catch/finally で `setRecording(false)`」は危険です。成功時にも同じ `finally` が実行される構造なら、開始直後に false へ戻ります。

  修正案: `setRecording(false)` は `catch` のみに置き、`finally` はUI busy状態などの解除だけに限定してください。

- [Critical] `recorder.onerror` や任意trackの `ended` で状態だけを false にすると、`MediaRecorder.state === "recording"` のままプレビュー解放が可能になり、録画データを破壊する恐れがあります。

  修正案: recordingの終了通知は原則 `recorder.onstop` に集約してください。`error` / `ended` は安全な停止処理を開始し、`onstop` 到達後に `setRecording(false)` とします。停止不能時だけ明示的な失敗状態へ遷移させます。

- [Warning] `acquirePreviewStream()` 失敗時に `wasActiveBeforePreview=false` が先に確定するため、再試行不能になります。

  修正案: 成功後に false にするか、失敗時に true へ戻してください。

### S5: テスト — REQUEST_CHANGES

既存追加分は妥当ですが、S2/S4修正に伴い以下を追加してください。

- [Critical] 初回open後にvideoの`src`が残ること。
- [Critical] take差し替え後、新takeの`src`で再生可能なこと。
- [Critical] MediaRecorder error/track ended中にcamera解放が実行されないこと。
- [Warning] stream再取得失敗後に再試行できること。
- [Warning] start成功時、終了前に`onRecordingChange(false)`が発火しないこと。

## 全体判定

**CHANGES_REQUESTED**

S1・S3は承認可能です。残課題は、S2の宣言的`src`と手動DOM teardownの競合、およびS4で「録画中」という安全状態を実際のMediaRecorder停止より先に解除してしまう可能性です。