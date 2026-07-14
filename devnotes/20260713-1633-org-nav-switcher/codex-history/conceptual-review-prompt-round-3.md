Round 2 の 2 点に対応しました。

## [Critical] manageApiKeys の直接付与 + cross-org 漏れ検証 → 対応
権限フラグ Feature テストのケースを拡張しました:
1. owner → canManageApiKeys=true
2. admin → true
3. 権限なし member → false
4. 現在組織で `manage-api-keys` を直接付与された member → true
5. **別組織でのみ直接付与された member → 現在組織では false** (cross-org 分離を実効検証)
canManageMembers は owner/admin=true・member=false を検証。

## [Warning] laratrust_team_id 明示 → 対応
権限フラグは currentOrganization ($organization) を対象に
`$user->can('manageMembers'|'manageApiKeys', $org)` で評価し、OrganizationPolicy が
`organizationRole($organization)` (laratrust_team_id=$organization->id 明示・strict_check) 経由で
判定する契約を設計に明記。上記ケース 5 が漏れ防止を固定します。

## [Warning] role="menu" の keyboard pattern → 対応 (menu セマンティクスを外す)
a11y を disclosure / popover パターンへ変更しました。トリガー button は
`aria-haspopup="true"` + `aria-expanded` + `aria-controls`、パネルは通常コンテナ
(**role="menu" を付けない**)。項目は Link/button として Tab で順次移動、Escape で閉じて
トリガーへ focus 復帰、click-outside で閉じる。矢印キーナビは実装しません。

---

以上で Round 1・Round 2 の全 Critical/Warning に対応済みです。残課題がなければ APPROVED を、
残る懸念があれば具体的にご指摘ください。
