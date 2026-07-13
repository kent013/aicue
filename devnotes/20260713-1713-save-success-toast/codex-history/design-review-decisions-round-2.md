# 対応マトリクス: design-review Round 2

全体判定: **APPROVED**（全施策 APPROVE。Round 1 Warning 2 件は解消確認済み）。

## [Suggestion] 施策5: 「無効 token」と「期限切れ token」を別ケース化
- 判断: **対応する**（承認は妨げないが低コストで堅くなるため反映）
- 根拠: 無効 token（不正文字列）と期限切れ token（`config('auth.passwords.users.expire')` 経過）は
  生成条件が異なる。両方の非回帰を保証するなら分ける方が意図が明確。
- 対応内容: 施策5 テスト計画の失敗系を 2 ケースに分割
  （(a) 不正 token 文字列、(b) `Password::createToken` 後に token 期限切れをシミュレート）。
  いずれも `assertSessionHasErrors` + `assertSessionMissing('success')`。
