# 詳細設計レビュー Round 4（対応報告）

Round 3 の 2 Warning に対応しました。

## 対応サマリー

### [Warning] S4 stale イベントが別操作の pending を解除する
対応: pending を操作種別で保持し、一致するイベント/タイムアウトのみが解除する:
```ts
type PauseResumeOperation = "pause" | "resume";
let pendingOperation: PauseResumeOperation | null = null;
let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;

function requestPause(): void {
    if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
    if (!supportsPauseResume()) return;
    pendingOperation = "pause";
    armPauseResumeTimeout("pause");
    try { recorder.pause(); } catch { clearPauseResumePending(); recoverPhaseFromRecorderState(); }
}
function requestResume(): void {
    if (phase !== "paused" || pendingOperation !== null || recorder === null) return;
    pendingOperation = "resume";
    armPauseResumeTimeout("resume");
    try { recorder.resume(); } catch { clearPauseResumePending(); recoverPhaseFromRecorderState(); }
}
function armPauseResumeTimeout(op: PauseResumeOperation): void {
    clearPauseResumeTimeout();
    pauseResumeTimeout = setTimeout(() => {
        pauseResumeTimeout = null;
        if (pendingOperation !== op) return; // 古い timeout が後続操作の pending を奪わない
        pendingOperation = null;
        recoverPhaseFromRecorderState();
    }, 2000);
}
function clearPauseResumePending(): void { pendingOperation = null; clearPauseResumeTimeout(); }

recorder.onpause = () => {
    if (pendingOperation === "pause") clearPauseResumePending(); // 一致操作のみ解除
    if (phase !== "recording") return;
    stopTimer(); setPhase("paused");
};
recorder.onresume = () => {
    if (pendingOperation === "resume") clearPauseResumePending();
    if (phase !== "paused") return;
    startTimer(); setPhase("recording");
};
```
多重押下ガードは `pendingOperation !== null`。

### [Warning] S7 交差テスト
対応: 3 交差ケースを追加:
1. pause の stale onpause が進行中の resume pending を解除しない
2. resume の stale onresume が進行中の pause pending を解除しない
3. 古い pause タイムアウト発火が後続 resume の pending を解除しない（`pendingOperation !== op` ガード）

---

残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
