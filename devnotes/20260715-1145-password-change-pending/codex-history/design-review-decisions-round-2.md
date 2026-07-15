# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED（Warning 1）。施策1&2 / 施策3 は APPROVE。残 Warning に対応した。

## [Warning] 施策4: submitPasswordForm が fireEvent.submit の Promise を破棄している
- 判断: 対応する
- 根拠: 妥当。イベント処理完了を待てず、後続の同期 assertion（特に onSuccess→reset 配線）が競合し得る。
- 対応内容: `submitPasswordForm` を `async` 化し `await fireEvent.submit(...)` に変更。全呼び出し側を `await submitPasswordForm()` に更新。
