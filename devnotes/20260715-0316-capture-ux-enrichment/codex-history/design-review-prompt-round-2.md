# 詳細設計レビュー Round 2（対応報告）

Round 1 の 3 Critical + Warning に対応し、詳細設計を改訂しました。

## 対応サマリー

### [Critical] S4 onerror + paused で停止不能
対応: `safeStop` の条件を `phase !== "recording" && phase !== "paused"` に統一。`recorder.onerror = () => safeStop()` は paused でも停止へ倒れる。`fatalStopCleanup` も `resetTimer()` + `clearPauseResumePending()` を追加。テストに「paused 中 onerror → 停止完了（onstop→onCaptured→idle）」を必須追加。

### [Critical] S6 flip 完全喪失で onCameraUnavailable が段階2 早期発火するバグ
対応（設計欠陥の修正）: 取得を副作用なしの低レベル関数に分離。
- `acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }>` — getUserMedia + srcObject 設定のみ。**onCameraUnavailable/error を呼ばず classify を返す**。
- `acquirePreviewStream()`（既存契約維持）は acquireStream をラップし従来の副作用（transient→error / unavailable→onCameraUnavailable）を適用。startRecording/resumeAfterPreview は無改変で呼べる。
- `applyAcquireFailure(result)` に副作用ポリシーを集約（transient→error / unavailable→onCameraUnavailable。排他なので文言競合なし）。
- `reacquireWithFacing` は acquireStream を直接使い、副作用を段階4 まで遅延:
  - 段階2: releaseCamera→facingMode=target→acquireStream()。ok なら終了。
  - 段階3: facingMode=previous→acquireStream()。ok なら flip 断念（error「切り替えられませんでした」）。
  - 段階4: 両失敗。段階3 の classify(back) にのみ applyAcquireFailure を適用（unavailable→onCameraUnavailable=F-03、transient→error）。
- これで「新 facing のみ不可（OverconstrainedError, 旧カメラ生存）」は段階3 で復旧し F-03 に倒さず、「両カメラ喪失」でのみ F-03 委譲。テストで OverconstrainedError→旧復旧→onCameraUnavailable 呼ばれない、両喪失→onCameraUnavailable 呼出、を必須化。

### [Critical] S7 stopping 表示方針の途中変更で既存テスト衝突
対応（方針固定）: 停止ボタンは recording/paused/**stopping** で常時可視（`phase !== "idle"`）。stopping では safeStop が phase ガードで no-op。既存「safeStop 多重クリック」テストが green。操作行分岐を「else（idle 以外）で停止ボタン常時 + recording に pause / paused に resume」に固定。

### [Warning] S4 inactive without onstop の収束保証
対応: `recoverPhaseFromRecorderState` が `state==="inactive"`（recording/paused 中）を検出したら `fatalStopCleanup()` でフェイルセーフ idle 復帰 + 資源解放（recorder 死亡の異常系のため F-03 委譲は妥当）。onstop 正規終了とは競合しない（正規終了時は stopping/idle）。テスト追加。

### [Warning] S6 getSettings().facingMode が undefined
対応: `tryApplyFacing` は undefined を「未検証扱い→再取得へ倒す（安全側）」とコメント明記 + テスト化。

### [Warning] S7 遅延イベントの二重遷移なし / durationMs pause 除外 / timer tick 遅延
対応: 3 ケースをテスト計画に追加（fake timers）。durationMs は record(A)→pause→resume→record(B)→stop で A+B のみ（pause 中壁時計除外）を厳密検証。

### [Suggestion] 群
supportsPauseResume クライアント専用注記 / formatElapsed hh:mm:ss 将来 TODO / z 順テスト名明示 / grid 連打 label 同期 / 実機コントラスト目視: コメント・テスト名で反映。

## 改訂後の主要コード（抜粋）

```ts
async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
    try {
        stream ??= await navigator.mediaDevices.getUserMedia({ video: videoConstraints(), audio: true });
    } catch (cause) {
        return classifyGetUserMediaError(cause);
    }
    if (video) { video.srcObject = stream; await video.play().catch(() => undefined); }
    return { kind: "ok" };
}
function applyAcquireFailure(result: CameraErrorClassification): void {
    if (result.kind === "transient") { error = "カメラを起動できませんでした。…"; return; }
    onCameraUnavailable(result.reason);
}
async function acquirePreviewStream(): Promise<boolean> {
    const result = await acquireStream();
    if (result.kind === "ok") return true;
    applyAcquireFailure(result);
    return false;
}
async function reacquireWithFacing(target: FacingMode): Promise<void> {
    const previous = facingMode;
    releaseCamera(); facingMode = target;
    const forward = await acquireStream();
    if (forward.kind === "ok") return;
    facingMode = previous;
    const back = await acquireStream();
    if (back.kind === "ok") { error = "カメラを切り替えられませんでした。"; return; }
    applyAcquireFailure(back); // 両喪失時のみ F-03/transient
}
function recoverPhaseFromRecorderState(): void {
    if (recorder === null || phase === "stopping") return;
    const state = recorder.state;
    if (state === "inactive") { fatalStopCleanup(); return; } // フェイルセーフ
    const nextPhase: Phase = state === "paused" ? "paused" : "recording";
    if (state === "paused") stopTimer(); else startTimer();
    if (phase !== nextPhase) setPhase(nextPhase);
}
```

---

残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
