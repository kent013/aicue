# 対応マトリクス: impl-review Round 1

Codex 判定: **APPROVED** (Critical 0 / Warning 0 / Suggestion 2)

## [Suggestion] `trustsEmail()` の provider-specific policy テスト追加

- 判断: 見送る
- 根拠: Codex 自身が「今回の施策 1 では true/false 固定なので不要」と付記している。
  現状 policy は claim 内容を一切参照しない (常に true / 常に false) ため、
  provider-specific なテストを今書いても実装をなぞるだけになる。
  AGENTS.md 思考原則 2「今必要なものだけ作る」に従い、claim 参照型 policy を
  実際に追加するときに同時にテストを入れる。
- 対応内容: 変更なし。

## [Suggestion] unconfirmed 登録ユーザーの確認メール送信 / 再送導線の到達性を回帰テスト化

- 判断: 見送る (T106 以降 / Microsoft 追加時へ申し送り)
- 根拠: Codex 自身が「施策 1 の範囲では必須ではない。Microsoft 等を実際に unconfirmed で
  追加する前には回帰テスト化した方が安全」と条件付けている。
  Microsoft 追加はプロダクト判断・c2c 未裁定であり本 TODO のスコープ外
  (実装指示で明示的に禁止されている)。また `/email/verify` 導線自体は
  Fortify の既存機能であり本差分で挙動を変えていない (既存の verified gate テストが carry)。
  本差分では「unconfirmed なら verified gate に落ちる」ところまでを Feature テストで固定済み。
- 対応内容: 変更なし。unconfirmed provider を実際に追加する TODO の受入条件として申し送る。

## 結論

Critical / Warning が 0 のため修正なしで Round 1 クローズ。Phase B (コミット) へ進む。
