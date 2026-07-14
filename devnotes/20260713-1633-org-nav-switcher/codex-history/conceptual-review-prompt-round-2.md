Round 1 の指摘への対応です。概念設計を更新しました。各指摘への対応 (対応 / 反論) を示します。

## [Critical] post-switch redirect 不整合 → 反論 (契約を明文化して補強)
既存 `OrganizationSwitchController::store()` の実コードを確認したところ、`back()` ではなく
`return redirect()->route('dashboard')->with('success', ...)` でした。切替後は必ず
**current-org スコープの中立ページ (dashboard)** へ遷移するため、「slug 画面のまま
ヘッダーだけ新 org」という不整合は構造的に発生しません。
→ 概念設計「制約・前提」に post-switch redirect 契約 (dashboard 固定・back 不使用) を明記し、
「settings 画面 (slug) からの switch 後に dashboard へ 302 + current_organization_id 更新」を
固定する Feature テストをスコープ内に追加しました。

## [Critical] billing.index をメンバー全員に表示は manageBilling と不整合 → 反論
実コードを確認: `BillingController::index()` は `Gate::authorize('view', $organization)` で認可
(manageBilling ではない)。checkout/portal のみ manageBilling。`OrganizationController::settings()` も
`Gate::authorize('view', ...)`。よって settings/billing を「メンバー全員」に表示するのは 403 導線に
なりません。
→ リンク認可対応表を実 Gate 契約に沿って明記。settings/billing は view=メンバーのためフラグ不要。
追加権限フラグは **canManageMembers / canManageApiKeys の 2 つに限定** (canManageBilling は
shared prop に足さない = 肥大化回避。billing 画面内の操作出し分けは既存 canManageBilling prop が担う)。

## [Warning] organizations.settings の表示条件根拠 → 反論
`OrganizationController::settings()` は `Gate::authorize('view', ...)` = メンバー全員 (確認済み)。
canViewSettings フラグは不要。設計に view 契約を明記。

## [Warning] PHP 側の返却 shape 固定 → 対応
`currentOrganizationProp()` の `@return array{...}|null` array-shape に slug と 2 権限フラグを加えて
PHPStan L10 で固定。Feature テストで role 別に検証。専用 DTO は Inertia 共有 prop には過剰と判断し
array-shape で固定 (オーバーエンジニアリング回避)。

## [Warning] Gate 評価文脈を current org に明示 → 対応
権限フラグは currentOrganization ($organization) を対象に `$user->can('manageMembers', $org)` /
`can('manageApiKeys', $org)` で評価し OrganizationPolicy を唯一の真実源とする旨を明記。role 直見しない。

## [Warning] shared prop 肥大化の境界 → 対応
「ナビ表示に必要な最小権限のみ shared prop に載せる」を境界として明記。今回は 2 フラグに限定。

## [Suggestion] a11y MVP 最小 → 対応
トリガー button に aria-haspopup=menu + aria-expanded、パネル role=menu、Escape で閉じてトリガーへ
focus 復帰、click-outside で閉じる、を最小要件として明記。過剰なフォーカストラップはスコープ外。

## [Suggestion] organizations に slug を載せない → 対応 (既定どおり)
organizations は id/name/isPersonal のまま。切替は id のみで足り cross-org slug 露出なし。

---

以上を反映した更新後の概念設計の要点は次のとおりです。整合性・残課題を判定してください。

- backend: `currentOrganizationProp()` に slug + canManageMembers + canManageApiKeys を追加
  (array-shape で PHPStan 固定、Gate は currentOrganization を対象に評価)。
- 型: `CurrentOrganization` に slug/canManageMembers/canManageApiKeys を追加 (PHP と 1:1)。
- frontend: `features/organizations/OrganizationSwitcher.svelte` 新設 (a11y MVP 内包、Lucide のみ、DS token のみ)。
- AppLayout: showAccountNav 時にヘッダーへ配置。
- リンク認可: settings/billing = view (メンバー全員), members(/manage/users) = canManageMembers,
  api-keys = canManageApiKeys, pricing = 常時。currentOrganization=null 時は「組織を作成」フォールバック。
- post-switch は既存の dashboard 遷移契約に依拠 (Feature テストで固定)。
- テスト: PHP Feature (共有 prop shape / post-switch redirect) + JS component (表示/切替/リンク出し分け/a11y)。

全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。
