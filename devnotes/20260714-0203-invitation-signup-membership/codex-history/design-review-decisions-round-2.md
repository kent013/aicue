# 対応マトリクス: design-review Round 2

Round 2 で全体判定 **APPROVED**（施策1・施策2 とも APPROVE）。残 Critical/Warning なし。
非ブロッキングの Suggestion 2 件を設計へ反映した。

## 施策1

### [Suggestion] POST 招待受諾で current_organization_id が切り替わらない既存テストを維持/明示追加
- 判断: 対応する（設計へ反映）
- 根拠: `acceptInvitationIfValid` の register 専用前提を守るガードとして有益。将来 joinOrganization へ
  現在組織確定を誤昇格させた場合に検知できる。
- 対応内容: 施策2 に「2-6. register 専用前提の保護: POST 受諾は現在組織を切り替えない」を追加。
  既存「token 受諾でメンバーシップ + 招待ロールが付与される」テストに受諾前後で current 不変のアサーションを追加。

## 施策2

### [Suggestion] 2-2 で organizations 一覧にも招待先が含まれる整合を維持
- 判断: 対応する（設計へ反映）
- 根拠: 共有プロップ間（currentOrganization / organizations）の整合を強化できる。
- 対応内容: 2-2 の assertInertia に `->where('organizations.0.id', $organization->id)` を追加。

## 結論
- 全体判定 APPROVED。Critical/Warning ゼロ。Suggestion 2 件を反映済み。設計フロー完了。
