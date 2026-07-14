Round 2 の Critical（S2 の宣言的 src / S4 の録画状態遷移）に対応しました。再レビューをお願いします。各施策の判定と全体判定を明示してください。

## 対応サマリー

- **S2-Critical（宣言的 src と手動 DOM teardown の競合）**: 採用。`<video>` を `{#key take?.id}` で囲み take 変更時に要素ごと再生成、`src` は宣言的バインド。teardown は `$effect` の cleanup に集約し `pause()` のみ（要素破棄は `{#key}`/破棄が担う）。初回 mount での誤 teardown を回避。
- **S4-Critical（finally での setRecording(false)）**: 採用。`setRecording(false)` は finally から除去。`finally` は `starting` リセットのみ。`setRecording(true)` は `recorder.start()` 成功直後のみ。
- **S4-Critical（onerror/track ended の直接 false 化で録画データ破壊）**: 採用。録画終了通知は **`recorder.onstop` を唯一の setRecording(false) 点**に集約。`onerror`/track `onended` は安全停止 `recorder.stop()` を呼び、onstop 到達で false 化（停止不能時のみ明示失敗）。`recording` は MediaRecorder の active window と厳密一致 → releaseForPreview の録画中ガードが正確。
- **S4-Warning（再取得失敗で再試行不能）**: 採用。`wasActiveBeforePreview=false` は `acquirePreviewStream()` 成功後にのみ確定（失敗時は true のまま=再試行可能）。
- **S5**: 対応テスト追加 — (a) 初回 open 後 src 残存 (b) take 差し替え後 新 src 再生可 (c) error/track ended 中に camera 解放しない (d) 再取得失敗後に再試行可 (e) start 成功時 終了前に onRecordingChange(false) 非発火。

## 修正後コード（抜粋）

### S2 TakePreviewDialog
```svelte
{#key take?.id}
    <video bind:this={video} controls playsinline src={playbackUrl ?? undefined} class="w-full ..."></video>
{/key}
```
```ts
function teardownVideo(): void { video?.pause(); } // 要素は {#key}/破棄で消えるため pause のみ
$effect(() => {
    if (!open || take === null) return;
    return () => { teardownVideo(); }; // close / 採用成功 / take 差し替え / 破棄で発火
});
```

### S4 CameraRecorder（recording は MediaRecorder active と一致）
```ts
function setRecording(next: boolean): void { if (recording !== next) { recording = next; onRecordingChange?.(next); } }
// start 成功直後: setRecording(true)
recorder.onstop  = () => { /* blob 生成 → onCaptured */ setRecording(false); };  // 唯一の終了点
recorder.onerror = () => { safeStop(); };            // recorder.stop() → onstop 経由で false
// 各 track.onended = () => safeStop();
// getUserMedia/start 例外の catch: recording は元々 false。finally は starting リセットのみ (false 化しない)

export function releaseForPreview(): void { if (recording) return; wasActiveBeforePreview = stream !== null; releaseCamera(); }
export function resumeAfterPreview(): Promise<void> {
    if (resuming) return resumePromise ?? Promise.resolve();
    if (!wasActiveBeforePreview || recording) return Promise.resolve();
    resuming = true;
    resumePromise = acquirePreviewStream().then(() => { wasActiveBeforePreview = false; }).finally(() => { resuming = false; resumePromise = null; });
    return resumePromise;
}
```

判定をお願いします。
