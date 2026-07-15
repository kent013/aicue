# 対応マトリクス: design-review Round 2

## [Critical] 二重送信ガードが onStart 依存(競合窓)
- 判断: 対応
- 対応内容: ガード通過直後・`router.post` 前に `reading = true` を同期設定。`onStart` を削除。

## [Critical] 二重送信テストが onStart 同期 mock では競合窓を検出できない
- 判断: 対応
- 対応内容: `router.post` mock がコールバックを呼ばない状態で同一ターン 2 回クリック → 1 回のみを検証。

## [Warning] opening と reading の相互排他不足(open/read visit 競合)
- 判断: 対応
- 対応内容: `open()` は `if (opening || reading) return;` + 同期 `opening=true`。
  `markRead()` は `if (reading || opening || !unread) return;`。disabled は使わず押下時ガード(禁止事項#8 遵守)。

## [Warning] open/read 競合防止テスト追加
- 判断: 対応
- 対応内容: 片方 in-flight(mock コールバック未発火)中にもう片方を押しても追加 router.post なしを検証。
