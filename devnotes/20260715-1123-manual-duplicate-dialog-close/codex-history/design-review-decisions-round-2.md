# 対応マトリクス: design-review Round 2

判定: CHANGES_REQUESTED（Critical 0 / Warning 2）。施策1/2/5 は APPROVE。

## [Warning] 施策3: prevOpen の $state 化で effect が自己依存
- 判断: 対応する（Round1 の提案を Codex 自身が撤回）
- 根拠: `$state` の prevOpen を effect 内で読み書きすると自己依存し余分に再実行される。
- 対応内容: 非 reactive ローカル `let prevOpen = open;` に戻し、依存を reactive な `open` のみに限定。
  `const isOpen = open; if (isOpen && !prevOpen) seedFromDefaults(); prevOpen = isOpen;` の形。初回 open:true でも
  seed が走らない要件は同形で満たす。

## [Warning] 施策4: エラー消滅テストが偽陽性になり得る
- 判断: 対応する
- 根拠: open:false 中にエラー注入すると一度も表示されずに clear され、queryByText=null が偽陽性化。
- 対応内容: テスト3を「(1) open:true でエラー注入し getByText で表示確認 → (2) open:false へ rerender →
  (3) defaults 変更で open:true へ rerender → (4) 新値・clearErrors・エラー文言消滅を確認」の遷移観測に変更。

## その他
- 施策1（不変条件記述）・施策2（ガード分離）・施策5（processing 反応化 + 型ガード）は APPROVE。追加対応なし。
