# 詳細設計レビュー Round 6: capture-ux-enrichment

Round 5 の Critical 2 点に対応しました（`RecordingSession` オブジェクトで新旧録画を物理分離）。

## Round 5 指摘への対応

1. **[Critical] onerror / track.onended が世代分離されていない** → 対応。
   `handleAbort(session: RecordingSession)` に変更し、生成時 `session` を渡す。`session !== activeSession || session.finalized` で旧 recorder の遅延 error/ended を no-op。処理対象も `session.recorder` に限定。

2. **[Critical] finalizeSession から mimeType を参照できない（コンパイルエラー）** → 対応。
   `RecordingSession` オブジェクト（id/recorder/mimeType/chunks/accumulatedMs/segmentStart/finalized）に状態を閉じ、finalizer は `session` から `mimeType`/`chunks`/`accumulatedMs` を参照。chunks と duration も新旧で物理分離。

## 修正後の該当コード（全文）

```ts
interface RecordingSession {
    id: number;
    recorder: MediaRecorder;
    mimeType: string;
    chunks: Blob[];
    accumulatedMs: number;
    segmentStart: number | null;
    finalized: boolean;
}
let sessionSeq = 0;
let activeSession = $state<RecordingSession | null>(null);
let finalizeWatchdog: ReturnType<typeof setTimeout> | null = null;
const FINALIZE_WATCHDOG_MS = 500;

function elapsedMs(s: RecordingSession): number {
    return s.accumulatedMs + (s.segmentStart !== null ? performance.now() - s.segmentStart : 0);
}
function closeSegment(s: RecordingSession): void {
    if (s.segmentStart !== null) { s.accumulatedMs += performance.now() - s.segmentStart; s.segmentStart = null; }
}
function clearFinalizeWatchdog(): void {
    if (finalizeWatchdog !== null) { clearTimeout(finalizeWatchdog); finalizeWatchdog = null; }
}

async function finalizeSession(s: RecordingSession): Promise<void> {
    if (s !== activeSession || s.finalized) return; // stale/dup → no-op
    s.finalized = true;
    clearFinalizeWatchdog();
    closeSegment(s); stopTimer();
    try {
        const blob = new Blob(s.chunks, { type: s.mimeType });
        if (blob.size > 0) await onCaptured(blob, s.mimeType, Math.round(s.accumulatedMs));
    } catch { error = "撮影データの処理に失敗しました。もう一度お試しください。"; }
    finally { dispatch("onstop"); }
}

// startRecording 内 (recorder 構築成功後):
//   const session: RecordingSession = { id: ++sessionSeq, recorder, mimeType, chunks: [],
//       accumulatedMs: 0, segmentStart: null, finalized: false };
//   activeSession = session;
recorder.ondataavailable = (event) => {
    if (session !== activeSession) return;
    if (event.data.size > 0) session.chunks.push(event.data);
};
recorder.onpause = () => {
    if (session !== activeSession || phase !== "pausing") return;
    closeSegment(session); dispatch("onpause"); stopTimer();
};
recorder.onresume = () => {
    if (session !== activeSession || phase !== "resuming") return;
    session.segmentStart = performance.now(); dispatch("onresume"); startTimer();
};
recorder.onstop = () => { void finalizeSession(session); };
recorder.onerror = () => handleAbort(session);
stream.getTracks().forEach((track) => { track.onended = () => handleAbort(session); });
// recorder.start() 成功後: session.segmentStart = performance.now(); dispatch("start"); startTimer();

function pauseRecording(): void {
    if (!canPause(phase) || activeSession === null) return;
    dispatch("pause");
    try { activeSession.recorder.pause(); } catch { dispatch("pauseFailed"); error = "一時停止できませんでした。もう一度お試しください。"; }
}
function resumeRecording(): void {
    if (!canResume(phase) || activeSession === null) return;
    dispatch("resume");
    try { activeSession.recorder.resume(); } catch { dispatch("resumeFailed"); error = "録画を再開できませんでした。もう一度お試しください。"; }
}
function safeStop(): void {
    if (!canStop(phase)) return;
    dispatch("stop");
    if (activeSession === null) { fatalStopCleanup(); return; }
    try { activeSession.recorder.stop(); } catch { fatalStopCleanup(); }
}

function handleAbort(session: RecordingSession): void {
    if (session !== activeSession || session.finalized) return; // 旧 session / 確定済みは無視
    if (phase === "idle") return;
    dispatch("abort");
    if (session.recorder.state !== "inactive") {
        try { session.recorder.stop(); return; } catch { /* → watchdog */ }
    }
    clearFinalizeWatchdog();
    finalizeWatchdog = setTimeout(() => { void finalizeSession(session); }, FINALIZE_WATCHDOG_MS);
}
```

タイマー表示は `displayMs = activeSession ? elapsedMs(activeSession) : 0` から派生。`onDestroy` は `releaseCamera` + `clearFinalizeWatchdog`。

S6 追加: 旧 `onerror`/`track.onended`/`onstop`/`ondataavailable` が新 session を停止・汚染しない。inactive abort→watchdog finalize→新録画→旧 onstop no-op。

これで新旧録画データ・異常イベントは `RecordingSession` 単位に閉じました。残る懸念があれば指摘を。問題なければ APPROVED をお願いします。
