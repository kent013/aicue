# 詳細設計レビュー Round 4: capture-ux-enrichment

Round 3 の Critical 1 に対応しました。

## Round 3 指摘への対応

**[Critical] 過渡 phase 中の recorder error / track 終了で復旧不能（safeStop が no-op）** → 対応。

- ユーザー操作 stop と異常終了を分離。`CaptureEvent` に `abort` を追加。
  ```ts
  case "abort": return phase === "idle" ? "idle" : "stopping";
  ```
- `handleAbort()` を新設し `recorder.onerror` / `track.onended` を `safeStop` から置換:
  ```ts
  function handleAbort(): void {
      if (phase === "idle") return;
      dispatch("abort"); // → stopping (全 phase から)
      if (recorder !== null && recorder.state !== "inactive") {
          try { recorder.stop(); return; } catch { /* 下の収束へ */ }
      }
      // recorder 既に inactive / stop 不能: 資源解放し idle へ収束 (active を必ず解除、再撮影可能)
      closeSegment(); stopTimer(); releaseCamera(); dispatch("onstop");
  }
  // recorder.onerror = () => handleAbort();  track.onended = () => handleAbort();
  ```
- 正常 recording 中の異常終了は従来同様 `recorder.stop()`→`onstop` で部分テイクを救出（`state !== "inactive"` 経路）。
- S6 追加: `pausing → onerror → idle`（active=false・再撮影可能）、`resuming → track.onended → idle`、recorder inactive でも releaseCamera + idle 収束、遅延 onpause/onresume が異常終了を巻き戻さない（source phase ガード）。`transition` の abort 遷移表も網羅。

Round 2 の 4 点は Round 3 で「適切に解消」と確認いただきました。

残る懸念があれば指摘を。問題なければ APPROVED をお願いします。

## 補足: FakeMediaRecorder stub 拡張

`handleAbort` は `recorder.state` を参照するため、テストの `FakeMediaRecorder` に `state`（"recording"|"paused"|"inactive"）を持たせ、start/pause/resume/stop で更新する。onerror/track.onended を手動発火できる API と併せ、過渡 phase 中の異常終了を再現する。
