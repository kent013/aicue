## S4 一時停止・再開

**判定: REQUEST_CHANGES**

- [Warning] staleイベントが、後続の別操作のpending状態を解除できます。例: pauseタイムアウト後にresume要求 → 遅延`onpause`到着 → `clearPauseResumePending()`がresume側のガードとtimeoutを消去します。

  **修正案**: booleanではなく操作種別を保持し、一致するイベントだけがpendingを解除します。

```ts
type PauseResumeOperation = "pause" | "resume";
let pendingOperation: PauseResumeOperation | null = null;

recorder.onpause = () => {
    if (pendingOperation === "pause") clearPauseResumePending();
    if (phase !== "recording") return;
    stopTimer();
    setPhase("paused");
};

recorder.onresume = () => {
    if (pendingOperation === "resume") clearPauseResumePending();
    if (phase !== "paused") return;
    startTimer();
    setPhase("recording");
};
```

timeoutにも操作種別を渡し、古いtimeoutが新しい操作を解除しないよう、一致確認してから復旧してください。

## S7 テスト

**判定: REQUEST_CHANGES**

- [Warning] 次の交差ケースを追加してください。

  - pauseのstaleイベントが、進行中のresume pendingを解除しない
  - resumeのstaleイベントが、進行中のpause pendingを解除しない
  - 古いtimeoutが後続操作のpending状態を解除しない

その他のRound 2指摘は解消されています。

## 全体判定

**CHANGES_REQUESTED**

残件はpause/resumeイベントとpending操作の相関管理のみです。操作種別による一致確認と交差テストを追加すれば承認可能です。