# 対応マトリクス: design-review Round 5

全体判定 **APPROVED** (施策 1〜5 すべて APPROVE)。

## [Suggestion] 自己検査表の負例 4 に旧 API 名 `throwTokens()` が残っている
- 判断: 対応する (非ブロッキングだがその場で直す)
- 対応内容: `controlFlowTokens(…, T_THROW)` へ表記を更新した。
