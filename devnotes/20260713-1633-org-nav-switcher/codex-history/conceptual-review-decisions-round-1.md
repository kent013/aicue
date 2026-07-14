# 対応マトリクス: conceptual-review Round 1

## [Critical] 実現可能性: organizations.switch の post-switch redirect 不整合 (slug 画面から切替時)
- 判断: 反論する (ただし契約を設計へ明文化して補強)
- 根拠: 既存 `OrganizationSwitchController::store()` は `back()` ではなく
  `return redirect()->route('dashboard')->with('success', ...)` で **current-org スコープの
  中立ページ (dashboard) へ必ず遷移**している (実コード確認済み)。よって「slug 画面のまま
  ヘッダーだけ新 org」という不整合は構造的に発生しない。
- 対応内容: 概念設計「制約・前提」に post-switch redirect 契約 (dashboard 固定・back 不使用)
  を明記し、Feature テストで slug 画面 (settings) からの切替後に dashboard へ遷移することを
  固定する旨をスコープ内に追加。

## [Critical] 期待効果: billing.index をメンバー全員に表示 = manageBilling(owner/admin) と不整合
- 判断: 反論する
- 根拠: `BillingController::index()` は `Gate::authorize('view', $organization)` で認可しており
  **manageBilling ではない** (実コード確認済み)。view = 組織メンバー全員。checkout/portal のみ
  manageBilling。よって「請求 = メンバー全員に表示」は 403 導線にならず正しい。同様に
  `OrganizationController::settings()` も `Gate::authorize('view', ...)` = メンバー全員。
- 対応内容: 概念設計のリンク認可対応表を実 Gate 契約に沿って明記
  (settings/billing = view=メンバー全員, api-keys = manageApiKeys, members(/manage/users) =
  manageMembers)。権限フラグは canManageMembers/canManageApiKeys の 2 つで足り、
  billing/settings は view (メンバーなら常時) なのでフラグ不要。canManageBilling は
  「請求リンクは全員表示・その中の操作は画面側が既に canManageBilling で出し分け済み」の
  ため **shared prop に足さない** (肥大化回避。下記 Warning と整合)。

## [Warning] 禁止事項/型: PHP 側の返却 shape 固定 (array-shape or DTO)
- 判断: 対応する
- 根拠: TS 側だけでは型ドリフトを防げないという指摘は妥当。
- 対応内容: `currentOrganizationProp()` の `@return array{...}|null` array-shape に slug/権限フラグを
  加えて PHPStan L10 で固定。Feature テストで role 別に shape を検証。専用 DTO は
  過剰 (Inertia 共有 prop は array-shape で十分・オーバーエンジニアリング回避)。

## [Warning] Gate 評価文脈を current org に明示
- 判断: 対応する
- 対応内容: 権限フラグは `$user->can('manageMembers', $organization)` /
  `can('manageApiKeys', $organization)` を **currentOrganization ($organization) を対象に**評価し、
  OrganizationPolicy を唯一の真実源とする旨を設計に明記 (role 直見しない)。

## [Warning] shared prop 肥大化の境界
- 判断: 対応する
- 対応内容: 「ナビ表示に必要な最小権限のみ shared prop に載せる」を境界として明記。
  今回は canManageMembers / canManageApiKeys の 2 フラグに限定 (billing/settings は view=メンバーで
  判定不要、pricing は公開)。

## [Warning] organizations.settings の表示条件根拠
- 判断: 反論する (根拠を明記)
- 根拠: `OrganizationController::settings()` は `Gate::authorize('view', ...)` = メンバー全員
  (実コード確認済み)。canViewSettings フラグは不要。
- 対応内容: 設計に view 認可契約を明記。

## [Suggestion] a11y は MVP 最小で定義
- 判断: 対応する
- 対応内容: 詳細設計で a11y MVP (トリガー button に aria-expanded / aria-haspopup,
  パネル role=menu, Escape で閉じてトリガーへ focus 復帰, click-outside) を最小要件として定義。

## [Suggestion] organizations 一覧に slug を載せない
- 判断: 対応する (既定方針どおり)
- 対応内容: organizations は id/name/isPersonal のまま。切替は id のみで足り cross-org slug 露出なし。
