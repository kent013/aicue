# 対応マトリクス: design-review Round 1

全体判定: **APPROVED**(Round 1)。全 8 施策 APPROVE。以下 2 点を反映して設計を締めた。

## [Warning] 共有 DTO 非削除の検証を「手順」として固定化
- 判断: 対応する
- 根拠: 保持リスト記載だけでは実装者依存。巻き込み削除の最大リスクを手順で潰す。
- 対応内容: 施策 0 の監査コマンドに
  `rg "CaptureManualDetailData|CaptureTakeData|TakeObjectStorage|UploadTicketCodec" app tests` を
  必須チェックとして追記。PR テンプレ記録項目に「保持 DTO が CaptureManualController から参照され続けること」を明記。

## [Suggestion] ProhibitsProtectedKeys 系「動的走査」根拠の 1 行補強
- 判断: 対応する
- 根拠: 将来レビューの再検証コスト低減。
- 対応内容: 施策 1 波及変更に「当該 3 テストは Request クラス名の固定期待を持たずディレクトリ走査で全 FormRequest を
  収集する方式のため 1 クラス削除で inventory 不整合 fail は起きない」旨を 1 行追記。

## 確認依頼論点への Codex 回答(記録)
- 閉じた参照集合の漏れ・巻き込み: 妥当。共有 DTO 誤削除防止も設計上成立。
- ProhibitsProtectedKeys 系 fail しない主張: 妥当(動的収集前提)。
- route/inventory/operations 同時削除で drift 0: 妥当(同一 PR 不可分)。
- standalone 実装モード: 妥当(分割より一括が安全)。
