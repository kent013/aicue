# 対応マトリクス: design-review Round 3

## [Critical] 過渡 phase 中の recorder error / track 終了で復旧不能（safeStop が no-op）
- 判断: 対応する（詰み回避の要件に直結）
- 根拠: `recorder.onerror`/`track.onended` → `safeStop()` は `canStop(phase)` ガードで pausing/resuming では no-op。過渡中に異常終了すると active が永久に残り操作不能。
- 対応内容: 異常終了経路をユーザー操作 stop と分離。`CaptureEvent` に `abort` を追加し `transition`: `case "abort": return phase === "idle" ? "idle" : "stopping";`（idle 以外の全 phase から stopping へ）。`handleAbort()` を新設し `recorder.onerror`/`track.onended` に配線:
  - phase が idle なら no-op。
  - `dispatch("abort")` → stopping。
  - recorder が active（state !== "inactive"）なら `recorder.stop()` で onstop 経路（部分テイクを救出）。
  - recorder が既に inactive / stop 不能なら `closeSegment(); stopTimer(); releaseCamera(); dispatch("onstop")` で idle へ収束し **active を必ず解除**（再撮影可能）。
- S6 追加: `pausing → onerror → idle`、`resuming → track.onended → idle`、異常終了後 active=false で再撮影可能、遅延した onpause/onresume が異常終了を巻き戻さない（source phase ガード + stopping 終端優先で保証）。
