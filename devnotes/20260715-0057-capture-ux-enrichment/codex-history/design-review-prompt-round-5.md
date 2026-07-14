# 詳細設計レビュー Round 5: capture-ux-enrichment

Round 4 の Critical 1（世代競合）に対応しました。

## Round 4 指摘への対応

**[Critical] inactive 即時収束と遅延 onstop/dataavailable が新録画セッションと競合** → 対応。

**録画セッション世代 + 一度限り finalizer** を導入:

```ts
let sessionId = 0;            // startRecording ごとに +1
let finalizedSession = -1;    // finalize 済み session
let finalizeWatchdog: ReturnType<typeof setTimeout> | null = null;
const FINALIZE_WATCHDOG_MS = 500;

// onstop と abort fallback を集約。stale/二重は no-op。active 解除はここでのみ。
async function finalizeSession(session: number): Promise<void> {
    if (session !== sessionId || finalizedSession === session) return; // stale/dup → no-op
    finalizedSession = session;
    clearFinalizeWatchdog();
    closeSegment(); stopTimer();
    try { const blob = new Blob(chunks, { type: mimeType });
          if (blob.size > 0) await onCaptured(blob, mimeType, Math.round(accumulatedMs)); }
    catch { error = "撮影データの処理に失敗しました。もう一度お試しください。"; }
    finally { dispatch("onstop"); }
}

// startRecording 内: sessionId += 1; const session = sessionId; chunks=[]; accumulatedMs=0; segmentStart=performance.now();
recorder.ondataavailable = (e) => { if (session !== sessionId) return; if (e.data.size>0) chunks.push(e.data); };
recorder.onpause = () => { if (session !== sessionId || phase !== "pausing") return; closeSegment(); dispatch("onpause"); stopTimer(); };
recorder.onresume = () => { if (session !== sessionId || phase !== "resuming") return; segmentStart = performance.now(); dispatch("onresume"); startTimer(); };
recorder.onstop = () => { void finalizeSession(session); };
recorder.onerror = () => handleAbort();
stream.getTracks().forEach((t) => { t.onended = () => handleAbort(); });

function handleAbort(): void {
    if (phase === "idle") return;
    dispatch("abort");
    const session = sessionId;
    if (recorder !== null && recorder.state !== "inactive") {
        try { recorder.stop(); return; } catch { /* → watchdog */ }
    }
    // inactive/stop 不能: 遅延 onstop を待ち watchdog で保険 finalize
    clearFinalizeWatchdog();
    finalizeWatchdog = setTimeout(() => { void finalizeSession(session); }, FINALIZE_WATCHDOG_MS);
}
```

要点:
- 全 recorder ハンドラを生成時 `session` closure で束ね、`session !== sessionId` の遅延イベントを **no-op**。
- `finalizeSession` は `session !== sessionId || finalizedSession === session` で **stale/二重を no-op**。active 解除（dispatch onstop）はここでのみ。
- inactive abort は即時 finalize せず **watchdog**（遅延 onstop を待ち、来なければ watchdog が finalize）。Codex R4 の「inactive は通常 onstop を待ち短い watchdog で fallback」に沿う。
- `onDestroy` に `clearFinalizeWatchdog()` を追加（リーク防止）。

S6 追加:
- 「inactive abort → watchdog finalize → 新録画開始（sessionId 更新）→ 旧 onstop 発火が no-op（新 chunks を汚さない・新 phase を idle に戻さない・onCaptured 二重発火なし）」
- 「遅延した旧 dataavailable が新 chunks を汚さない」
- 「watchdog（fake timers）で active 解除」

残る懸念があれば指摘を。問題なければ APPROVED をお願いします。
