# 対応マトリクス: conceptual-review Round 2

## [Critical] manageApiKeys の role 別テストだけでは不十分 (直接付与 + cross-org 漏れ)
- 判断: 対応する
- 根拠: `OrganizationPolicy::manageApiKeys` は owner/admin に加え `manage-api-keys` 直接付与
  メンバーも true。別組織での直接付与が現在組織へ漏れないことはセキュリティ不変条件
  (cross-org 不可)。role のみのテストでは検出できない。
- 対応内容: 権限フラグ Feature テストのケースを拡張:
  (1) owner=true (2) admin=true (3) 権限なし member=false
  (4) 現在組織で manage-api-keys 直接付与 member=canManageApiKeys true
  (5) **別組織でのみ直接付与された member → 現在組織では canManageApiKeys=false** (cross-org 分離)。
  canManageMembers も (owner/admin=true, member=false) を検証。

## [Warning] laratrust_team_id の明示保証
- 判断: 対応する
- 根拠: 使命規約「権限判定は常に laratrust_team_id を明示 (strict_check=true)」。
- 対応内容: 権限フラグは `$user->can('manageMembers'|'manageApiKeys', $organization)` で
  **currentOrganization を対象**に評価し、OrganizationPolicy が `organizationRole($organization)`
  (laratrust_team_id=$organization->id を明示・strict_check) 経由で判定する契約を設計に明記。
  上記 (5) の cross-org 分離テストが実効的な検証を担う。

## [Warning] role="menu" の keyboard pattern (矢印キー等)
- 判断: 対応する (menu セマンティクスを外す方針へ変更)
- 根拠: role="menu" は矢印キー移動等の menu keyboard contract を要求し MVP には過剰。
- 対応内容: a11y を **disclosure / popover パターン**に変更。トリガー button に
  `aria-haspopup="true"` + `aria-expanded` + `aria-controls`、パネルは通常コンテナ
  (role=menu を付けない)。中の項目は Link / button として **Tab で順次移動**、
  Escape で閉じてトリガーへ focus 復帰、click-outside で閉じる。矢印キー実装は不要。

## [Suggestion] その他 (型安全性・スコープ・使命) → 追加対応不要 (現状で妥当と評価)
