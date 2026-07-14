# 詳細設計レビュー Round 3（対応報告）

Round 2 の 1 Critical + 1 Warning に対応しました。

## 対応サマリー

### [Critical] S4 stale onpause/onresume が phase 確認前に timer を操作
対応: timer 操作を phase ガードの内側へ移動。stale イベントが stopping/idle で到着しても timer/phase を触らない:
```ts
recorder.onpause = () => {
    clearPauseResumePending();
    if (phase !== "recording") return; // stale なら timer/phase を触らない
    stopTimer();
    setPhase("paused");
};
recorder.onresume = () => {
    clearPauseResumePending();
    if (phase !== "paused") return;    // stale なら timer/phase を触らない
    startTimer();
    setPhase("recording");
};
```
clearPauseResumePending() のみ stale でも実行可（in-flight/timeout 解放は無害）。これで durationMs への停止処理中時間の混入・interval リークを防ぐ。

### [Warning] S7 stale イベント競合テスト
対応: テスト計画に 4 競合ケースを追加:
1. pause 要求直後に stop → stale onpause 到着で phase/timer 不変
2. resume 要求直後に stop → stale onresume 到着で timer 再起動なし
3. idle 到達後の stale onresume で timer 更新なし（interval 復活なし）
4. 上記で onCaptured.durationMs に停止処理中の待ち時間が混入しない

---

残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
