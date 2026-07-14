# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED。施策1/2/3 APPROVE、施策4 に Warning 1 件。

## [Warning] 施策4: span の min-w-0 / flex-1 も検証する
- 判断: 対応する
- 根拠: `truncate` のみだと min-w-0/flex-1 が削除されても通り、回帰を取りこぼす。
- 対応内容: `expect(sp).toHaveClass("min-w-0", "flex-1", "truncate")`、
  `expect(row).toHaveClass("flex", "min-w-0")`、`expect(icon).toHaveClass("shrink-0")` に強化。
