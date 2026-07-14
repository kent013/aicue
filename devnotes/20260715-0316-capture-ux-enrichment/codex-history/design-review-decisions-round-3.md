# 対応マトリクス: design-review Round 3

## [Warning] S4 stale イベントが別操作の pending を解除する（boolean pending の相関欠如）
- 判断: 対応する（実質 Critical 級の相関バグ）
- 根拠: boolean `pauseResumePending` では「どの操作が in-flight か」を判別できず、pause タイムアウト後の resume 要求中に遅延 onpause が来ると resume 側の pending/timeout を誤って解除する。
- 対応内容: pending を操作種別で保持:
  - `type PauseResumeOperation = "pause" | "resume"; let pendingOperation: PauseResumeOperation | null = null;`
  - 多重押下ガードは `pendingOperation !== null` で判定。
  - onpause は `pendingOperation === "pause"` のときのみ clearPauseResumePending()、onresume は `=== "resume"` のときのみ。
  - `armPauseResumeTimeout(op)` に操作種別を渡し、timeout 発火時 `if (pendingOperation !== op) return;` で古い timeout が後続操作の pending を奪わないようにする。
  - 同期 throw（InvalidStateError）時は clearPauseResumePending() してから recover。

## [Warning] S7 交差テストの追加
- 判断: 対応する
- 対応内容: テスト計画に 3 交差ケースを追加:
  1. pause の stale onpause が進行中の resume pending を解除しない
  2. resume の stale onresume が進行中の pause pending を解除しない
  3. 古い pause タイムアウト発火が後続 resume の pending を解除しない（`pendingOperation !== op` ガード）
