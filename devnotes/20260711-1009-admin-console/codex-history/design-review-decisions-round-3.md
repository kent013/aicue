# 対応マトリクス: design-review Round 3

全体判定: **APPROVED**（Round 3。全施策 APPROVE）

## [Suggestion] ロック下再検証に isExpired() も含めて期限境界の TOCTOU を閉じる
- 判断: 対応する
- 根拠: 事前検証（acceptInvitation / acceptInvitationIfValid）は期限切れも扱うため、ロック下の再検証も同じ完全性を持つべき。コスト極小。
- 対応内容: `joinOrganization` のロック下再検証を
  `if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) { return; }` に更新（detailed-design.md 反映済み）。
