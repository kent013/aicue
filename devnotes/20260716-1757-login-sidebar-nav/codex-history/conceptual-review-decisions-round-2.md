# 対応マトリクス: conceptual-review Round 2（APPROVED）

Round 2 で全体判定 APPROVED。残る [Warning] は詳細設計（Phase 2）で確定する。

## [Warning] 5. 組織設定/CLI/MCP の表示条件が「管理ロール」で曖昧
- 判断: 対応する（詳細設計で Policy ability と 1:1 確定）
- 対応内容: 詳細設計で各下部メニュー項目を対応 Policy ability・capability と 1:1 に確定する。
  - 組織設定 `/organizations/{slug}/settings` → OrganizationPolicy の該当 ability を確認し、
    既存 capability（canManageMembers/canManageApiKeys）で不足するなら専用 boolean を追加。
  - CLI/MCP `/organizations/{slug}/onboarding/cli|mcp` → OnboardingController の認可
    （ApiKeyPolicy::viewAny 相当）を確認し `canManageApiKeys` で出し分け（aigenba と同じ）。
  - 「管理ロール」の包括表現は使わない。

## [Warning] capability Feature テストが「未認証」「権限なし」のみ
- 判断: 対応する
- 対応内容: 各 capability について「権限ありで true」「権限なしで false」「未認証で
  currentOrganization=null」を検証。さらに別組織で付与した権限が current org に漏れない
  （cross-org 分離）ケースを追加。

## [Suggestion] 6. OrganizationSwitcher 退役の移行先明示
- 判断: 対応する（確認済み）
- 対応内容: grep 確認の結果 `OrganizationSwitcher` の利用は `templates/AppLayout.svelte` のみ
  （他ページからの参照なし）。単純削除ではなく、org 切替→下部メニュー、org リンク→サイドバー nav /
  SidebarUserMenu への移行先を詳細設計に明示する。

## 型安全性・その他 Suggestion
- capability boolean は非 nullable、未認証時は currentOrganization ごと null。mixed/optional/
  広い union への緩和はしない。JsonResource は新設しない（shared prop は API 応答ではない）。

## Phase 1 での確定事項（詳細設計へ引き継ぐ設計上の精緻化）
- **請求(billing)はメンバー全員が閲覧可**: `BillingController@index` は `Gate::authorize('view',
  $organization)` で、既存 `currentOrganizationProp` のコメントも「settings/billing は view=メンバー
  全員のためフラグ不要」と明記。よって **`canViewBilling` 共有プロップは新規追加しない**。請求 nav /
  リンクは `currentOrganization != null`（＝メンバー）で出す。結果、**本施策のバックエンド変更は
  原則ゼロ**（既存 canManageMembers/canManageApiKeys のみ使用）。組織設定の可視性のみ Phase 2 で
  Policy を確認し、必要時だけ最小の boolean を追加する。
