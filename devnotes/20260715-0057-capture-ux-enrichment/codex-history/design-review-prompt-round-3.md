# 詳細設計レビュー Round 3: capture-ux-enrichment

Round 2 の Critical 2 + Warning 2 に対応しました。

## Round 2 指摘への対応

1. **[Critical] イベント受理判定が no-op と区別できない（重複 onpause/onresume で segmentStart 上書き）** → 対応。
   - `transition` の `onpause` を `pausing→paused` のみ、`onresume` を `resuming→recording` のみに厳格化（recording からの自然 onpause 経路を廃止）。
   - ハンドラは **source phase を直接ガード**（「戻り値が目的 phase」判定を廃止）:
     ```ts
     recorder.onpause = () => { if (phase !== "pausing") return; closeSegment(); dispatch("onpause"); stopTimer(); };
     recorder.onresume = () => { if (phase !== "resuming") return; segmentStart = performance.now(); dispatch("onresume"); startTimer(); };
     ```
   - 回帰テストに `transition("recording","onresume")==="recording"`（副作用なし）等の重複イベント防波堤を追加。

2. **[Critical] switching が既存の排他状態に含まれていない** → 対応。
   - `active = starting || previewResuming || switching || phase !== "idle"`。
   - `startRecording` / `releaseForPreview` / `resumeAfterPreview` の early-return に `switching` を追加。
   - `switching=true` 直後に `syncActive()`（active 通知）。切替完了の finally で `switching=false; syncActive()`。
   - Vitest で「切替中の startRecording/releaseForPreview が no-op・active 通知順」を固定。

3. **[Warning] cancel event を省略した直接代入** → 対応。
   - `CaptureEvent` に `pauseFailed` / `resumeFailed` を追加。`transition`: `pauseFailed: pausing→recording` / `resumeFailed: resuming→paused`。同期例外時の巻き戻しを `dispatch("pauseFailed"/"resumeFailed")` に統一（状態機械外の遷移経路を廃止）。

4. **[Warning] S3 段階2で切替成立を検証していない** → 対応。
   - 段階2でも `switchSucceeded()` を適用。不成立 stream は stop して段階3（previousMode 再取得）へ。

## 確認事項への回答（Round 2）
- 同期例外の直接代入 → pauseFailed/resumeFailed イベント経由に変更済み。
- 反転ボタンの `stream !== null` 条件 → 妥当と確認いただき維持。

残る懸念があれば Critical/Warning で。問題なければ APPROVED をお願いします。

---

## 修正後の該当セクション（全文抜粋）

### camera.ts: CaptureEvent / transition（修正後）
```ts
export type CaptureEvent =
    | "start" | "pause" | "onpause" | "pauseFailed"
    | "resume" | "onresume" | "resumeFailed" | "stop" | "onstop";

export function transition(phase: CapturePhase, event: CaptureEvent): CapturePhase {
    switch (event) {
        case "start":        return phase === "idle" ? "recording" : phase;
        case "pause":        return phase === "recording" ? "pausing" : phase;
        case "onpause":      return phase === "pausing" ? "paused" : phase;       // 過渡からのみ
        case "pauseFailed":  return phase === "pausing" ? "recording" : phase;    // 過渡巻き戻し
        case "resume":       return phase === "paused" ? "resuming" : phase;
        case "onresume":     return phase === "resuming" ? "recording" : phase;   // 過渡からのみ
        case "resumeFailed": return phase === "resuming" ? "paused" : phase;      // 過渡巻き戻し
        case "stop":         return phase === "recording" || phase === "paused" ? "stopping" : phase;
        case "onstop":       return "idle"; // 全 phase から idle へ収束
    }
}
```

### CameraRecorder.svelte: イベント配線・pause/resume・active（修正後）
```ts
function syncActive(): void {
    const active = starting || previewResuming || switching || phase !== "idle";
    if (active !== lastActive) { lastActive = active; onCaptureActiveChange?.(active); }
}
recorder.onpause = () => { if (phase !== "pausing") return; closeSegment(); dispatch("onpause"); stopTimer(); };
recorder.onresume = () => { if (phase !== "resuming") return; segmentStart = performance.now(); dispatch("onresume"); startTimer(); };
recorder.onstop = async () => {
    closeSegment(); stopTimer();
    try { const blob = new Blob(chunks, { type: mimeType });
          if (blob.size > 0) await onCaptured(blob, mimeType, Math.round(accumulatedMs)); }
    catch { error = "撮影データの処理に失敗しました。もう一度お試しください。"; }
    finally { dispatch("onstop"); }
};
function pauseRecording(): void {
    if (!canPause(phase) || recorder === null) return;
    dispatch("pause");
    try { recorder.pause(); } catch { dispatch("pauseFailed"); error = "一時停止できませんでした。もう一度お試しください。"; }
}
function resumeRecording(): void {
    if (!canResume(phase) || recorder === null) return;
    dispatch("resume");
    try { recorder.resume(); } catch { dispatch("resumeFailed"); error = "録画を再開できませんでした。もう一度お試しください。"; }
}
function safeStop(): void {
    if (!canStop(phase)) return;
    dispatch("stop");
    if (recorder === null) { fatalStopCleanup(); return; }
    try { recorder.stop(); } catch { fatalStopCleanup(); }
}
// startRecording / releaseForPreview / resumeAfterPreview の early-return に switching を追加。
```

### CameraRecorder.svelte: switchCamera（段階リカバリ・修正後）
```ts
let switching = false; // active に含める

async function switchCamera(): Promise<void> {
    if (!canSwitchCamera(phase) || switching || stream === null) return;
    switching = true; syncActive(); error = null;
    const previousMode = facingMode;            // ★復旧は必ずこれ
    const target = oppositeFacingMode(previousMode);
    const prevDeviceId = stream.getVideoTracks()[0]?.getSettings().deviceId ?? null;
    try { applySwitchOutcome(await runCameraSwitch(target, prevDeviceId, previousMode)); }
    finally { switching = false; syncActive(); }
}

async function runCameraSwitch(target, prevDeviceId, previousMode): Promise<CameraSwitchOutcome> {
    // 1) acquire-then-swap
    try {
        const next = await tryGetCamera(target, true);
        if (switchSucceeded(next, target, prevDeviceId)) return { kind:"switched", stream:next, facingMode:target };
        next.getTracks().forEach((t) => t.stop());
    } catch {}
    // 2) 旧 stop 後 target 取得 + 成立検証
    releaseCamera();
    try {
        const next = await tryGetCamera(target, true);
        if (switchSucceeded(next, target, prevDeviceId)) return { kind:"switched", stream:next, facingMode:target };
        next.getTracks().forEach((t) => t.stop());
    } catch {}
    // 3) previousMode で復旧
    try {
        stream = await tryGetCamera(previousMode, false);
        if (video) { video.srcObject = stream; await video.play().catch(() => undefined); }
        return { kind:"recovered" };
    } catch (recoverCause) {
        // 4) 旧カメラも喪失 → 恒久フォールバック (classify 1 回)
        releaseCamera();
        const classified = classifyGetUserMediaError(recoverCause);
        return { kind:"unavailable", reason: classified.kind === "unavailable" ? classified.reason : "unknown" };
    }
}
function applySwitchOutcome(o: CameraSwitchOutcome): void {
    switch (o.kind) {
        case "switched": swapStream(o.stream, o.facingMode); return;
        case "recovered": error = "カメラを切り替えできませんでした。現在のカメラで続行します。"; return;
        case "unavailable": onCameraUnavailable(o.reason); return;
    }
}
```
