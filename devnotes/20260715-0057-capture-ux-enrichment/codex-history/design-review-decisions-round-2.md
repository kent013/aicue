# 対応マトリクス: design-review Round 2

## [Critical] イベント受理判定が no-op と区別できない（重複 onpause/onresume で segmentStart 上書き）
- 判断: 対応する（重大バグ）
- 根拠: `transition("recording","onresume")==="recording"` のため recording 中の重複 onresume が受理判定を通り segmentStart を上書き→録画時間欠落。指摘は正当。
- 対応内容: (1) `transition` の `onpause` を `pausing→paused` のみ、`onresume` を `resuming→recording` のみに厳格化（recording からの自然 onpause 経路を廃止＝我々は必ず pause() 経由で pausing を通る）。(2) ハンドラは **source phase を直接ガード**: `recorder.onpause = () => { if (phase !== "pausing") return; ... }`、`recorder.onresume = () => { if (phase !== "resuming") return; ... }`。「戻り値が目的 phase」判定を廃止。

## [Critical] switching が既存の排他状態に含まれていない（切替中に録画開始・preview 解放が並行）
- 判断: 対応する
- 根拠: switchCamera 中も phase=idle/starting=false/previewResuming=false のため getUserMedia 待機中に startRecording/releaseForPreview/preview 開始が並行しうる。リスク記述と実コードが不一致。
- 対応内容: `active = starting || previewResuming || switching || phase !== "idle"` に変更。`startRecording` / `releaseForPreview` / `resumeAfterPreview` の early-return 条件に `switching` を追加。`switching = true/false` の直後に `syncActive()`。並行操作拒否と active 通知順を Vitest で固定。

## [Warning] cancel event を省略し直接代入した点（状態機械外の遷移経路が残る）
- 判断: 対応する
- 根拠: 「phase 遷移は transition() が単一真実源」との整合を優先。イベント数増より状態機械外経路のリスクが大きい。
- 対応内容: `CaptureEvent` に `pauseFailed` / `resumeFailed` を追加。`transition`: `pauseFailed: pausing→recording`、`resumeFailed: resuming→paused`。同期例外時の巻き戻しも `dispatch("pauseFailed"/"resumeFailed")` 経由に統一（直接代入を廃止）。

## [Warning] S3 段階2で切替成立を検証していない
- 判断: 対応する
- 対応内容: 段階2でも `switchSucceeded()` を適用。不成立 stream は停止して段階3（previousMode 再取得）へ進める。exact を無条件に信用しない。
