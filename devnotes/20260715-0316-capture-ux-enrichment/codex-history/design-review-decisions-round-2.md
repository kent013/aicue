# 対応マトリクス: design-review Round 2

## [Critical] S4 stale onpause/onresume が phase 確認より先に timer を操作
- 判断: 対応する
- 根拠: 遅延 onresume が stopping/idle で到着すると、現ハンドラは phase 確認前に startTimer() を実行 → 停止処理中時間が durationMs に混入 / onstop 未達時 interval 残置。
- 対応内容: onpause/onresume のハンドラで **timer 操作を phase ガードの内側へ移動**:
  ```ts
  recorder.onpause = () => { clearPauseResumePending(); if (phase !== "recording") return; stopTimer(); setPhase("paused"); };
  recorder.onresume = () => { clearPauseResumePending(); if (phase !== "paused") return; startTimer(); setPhase("recording"); };
  ```
  clearPauseResumePending() のみ stale でも実行可（in-flight/timeout 解放は無害）。timer と phase は対応遷移元でのみ変更。

## [Warning] S7 stale イベント競合テストの追加
- 判断: 対応する
- 対応内容: テスト計画に 4 競合ケースを追加:
  1. pause 要求直後に stop → stale onpause 到着で phase/timer 不変
  2. resume 要求直後に stop → stale onresume 到着で timer 再起動なし
  3. idle 到達後の stale onresume で timer 更新なし（interval 復活なし）
  4. 上記で onCaptured.durationMs に停止処理中の待ち時間が混入しない
