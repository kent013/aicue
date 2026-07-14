# 概念設計: org-nav-switcher

## 背景・課題

bug-hunt findings F-C1 (Critical) / F-C2 (Critical) が、組織まわりの UI 導線欠落による「詰み」を報告している。

- **F-C1**: 組織設定 / API keys / 請求 / 料金 / メンバー招待への恒常ナビ導線が UI 上に一切無い。
  組織作成直後の一度きりリダイレクトでしか各画面に到達できず、以後戻る手段がない。
  さらに共有 props `currentOrganization` に `slug` が欠落しているため、フロントで
  `organizations.settings` / `organizations.api-keys.index`(いずれも `{organization:slug}` バインド)への
  リンクを自力生成することもできない。結果として S2 招待フロー
  (`organizations.invitations.store/revoke`, `members.update/destroy` 等) が全て到達不能。
- **F-C2**: 組織スイッチャー UI が全く無い。新規組織作成で `current_organization_id` が自動的に
  切り替わる (H2) と、UI から元の組織に戻る手段がなく詰む。`organizations.switch`
  (`POST /organizations/{organization}/switch`) は実装済みだが、それを呼ぶ UI が存在しない。

### 根本原因

- `resources/js/components/templates/AppLayout.svelte` がサイドバー / 組織メニュー / 組織切替を
  「Phase 2」コメントのまま未実装 (L12-13, L88 付近)。ヘッダーは通知ベル・設定・ログアウトのみ。
- `app/Http/Middleware/HandleInertiaRequests.php` の `currentOrganizationProp()` が
  `{id, name, role}` のみを共有し `slug` を含めていない (L112-124)。

## 改善アイデア

AppLayout ヘッダーに、SaaS 標準の**組織スイッチャー兼組織メニュー**を 1 コンポーネントとして常設する。
トリガーは現在の組織名を表示するボタン、展開パネルに以下を含める:

1. **組織切替セクション** — 共有 prop `organizations` を列挙し、各項目を
   `POST /organizations/{id}/switch` (既存 `organizations.switch`) にポストする。現在の組織には
   チェック表示。所属組織が 1 個のときは切替セクションを出さない (料金・招待だけが残る)。
2. **組織管理リンクセクション** — 各画面の**実際の Gate 認可契約**に沿って出し分ける
   (押下時 403 を避ける先出しであり最終認可はエンドポイントが担保):

   | リンク | ルート | 実 Gate 契約 (確認済み) | 表示条件 |
   |--------|--------|------------------------|----------|
   | 組織設定 | `organizations.settings` (slug) | `Gate::authorize('view', org)` | メンバー全員 (currentOrganization!=null) |
   | メンバー管理/招待 | `manage.users.index` (`/manage/users`) | 画面は `manageMembers` 相当 | `canManageMembers` |
   | API キー | `organizations.api-keys.index` (slug) | `Gate::allows('manageApiKeys', org)` | `canManageApiKeys` |
   | 請求 | `billing.index` (`/billing`) | `Gate::authorize('view', org)` | メンバー全員 |
   | 料金 | `/pricing` (公開) | 認可なし | 常時 |

   → settings / billing はいずれも `view` 認可 = **メンバー全員**なので権限フラグ不要
   (currentOrganization が非 null なら常時表示)。追加フラグは
   **`canManageMembers` / `canManageApiKeys` の 2 つに限定**する (肥大化回避)。
3. **フォールバック** — `currentOrganization` が null のとき「組織を作成」(`organizations.create`) を出し、
   詰み状態からの脱出口を保証する。

これを支えるため `HandleInertiaRequests` の `currentOrganization` 共有に **`slug`** と、
リンク出し分け用の**権限フラグ** (`canManageMembers` / `canManageApiKeys`) を追加する。
権限フラグは `currentOrganization` ($organization) を対象に `$user->can('manageMembers', $org)` /
`can('manageApiKeys', $org)` で評価し、`OrganizationPolicy` を唯一の真実源とする
(role 直見しない。認可ロジックの二重管理を避ける。`laratrust_team_id` 明示判定は
`organizationRole` 内で担保済み)。**shared prop に載せる権限はナビ表示に必要な最小のみ**という
境界を設ける (billing/settings は view=メンバーで判定不要、pricing は公開)。

## 期待効果

- **使命への貢献**: 現場作業者が組織 (現場) をまたいでマニュアルを運用する導線を回復する。
  組織切替・メンバー招待・請求管理という運用の背骨が UI から到達可能になり、
  「思考ゼロ」で使える前提 (迷子にならない) を満たす。
- **詰みの解消**: F-C2 の H2 (組織自動切替後に戻れない) を恒常スイッチャーで解消。
  F-C1 の到達不能 (組織設定/API keys/請求/招待) を恒常メニューで解消し、S2 招待フローを開通させる。
- **回帰防止**: `slug` 欠落という共有 prop の穴を型 (`CurrentOrganization`) と Feature テストで塞ぐ。

## 実装方針（概要）

1. **backend**: `HandleInertiaRequests::currentOrganizationProp()` に `slug` と権限フラグ 2 種
   (`canManageMembers` / `canManageApiKeys`) を追加。`role` は既存。Gate で評価。
   戻り値 docblock の `@return array{...}|null` array-shape を拡張して PHPStan L10 で固定する
   (専用 DTO は Inertia 共有 prop には過剰。array-shape + Feature テストで型ドリフトを塞ぐ)。
2. **型**: `resources/js/lib/shared-props.ts` の `CurrentOrganization` に `slug: string` と
   `canManageMembers/canManageApiKeys: boolean` を追加 (PHP array-shape と 1:1 対応)。
3. **frontend コンポーネント**: `resources/js/components/features/organizations/OrganizationSwitcher.svelte`
   を新設 (状態を持つ organism 級 = features/{domain} 配置)。開閉状態・click-outside・Escape・
   フォーカス管理を内包。アイコンは `@lucide/svelte` のみ (Building2, Check, Settings, Users,
   KeyRound, CreditCard, Tag, ChevronsUpDown 等)。色・radius・typography は DESIGN.md の DS token のみ。
4. **AppLayout**: `showAccountNav` 時にヘッダー左側 (通知ベルの前) へ `OrganizationSwitcher` を配置。
   「Phase 2」コメントを実装済みに置換。
5. **テスト**: (a) PHP Feature — 共有 prop に slug + 権限フラグが正しく載ることを検証。
   canManageApiKeys は **cross-org 分離まで**含めた 5 ケース: owner=true / admin=true /
   権限なし member=false / 現在組織で manage-api-keys 直接付与 member=true /
   **別組織でのみ直接付与された member → 現在組織では false**。canManageMembers は owner/admin=true・
   member=false。(b) PHP Feature — settings 画面 (slug) からの `organizations.switch` POST 後に
   dashboard へ 302 + `current_organization_id` 更新を固定 (post-switch redirect 契約)。
   (c) JS component — 現在組織表示 / 複数所属時の切替リスト / switch POST 呼び出し /
   権限によるリンク出し分け / null 組織時の作成導線 / a11y (aria-expanded, Escape で閉じ focus 復帰)。

新規ルート・新規コントローラは不要 (`organizations.switch`・各画面ルートは既存)。
組織一覧専用の GET 画面は今回作らない (スイッチャーのドロップダウンで一覧・切替が完結するため。
brief の「必要なら」に照らし不要と判断)。

## 制約・前提

- `organizations.switch` は `{organization}` (slug 無し) バインド = id 解決
  (`MembershipScopedOrganizationBinder`, BINDABLE_FIELDS で id/slug のみ許可、
  無指定は routeKeyName=id)。切替は共有 prop `organizations[].id` を使って
  `/organizations/{id}/switch` に POST すれば足りる (slug 不要)。
- **post-switch redirect 契約 (F-C2 の要)**: `OrganizationSwitchController::store()` は
  `back()` を使わず `redirect()->route('dashboard')->with('success', ...)` で
  **current-org スコープの中立ページ (dashboard) へ必ず遷移**する (実コード確認済み)。
  よって slug 依存画面 (settings/api-keys) からの切替でも「URL は旧 org のまま・ヘッダーだけ
  新 org」という不整合は構造的に発生しない。この契約は Feature テストで固定する
  (settings 画面からの switch 後に dashboard へ 302 + current_organization_id 更新)。
- `organizations.settings` / `organizations.api-keys.index` は `{organization:slug}` バインド =
  **slug 必須**。ここが currentOrganization に slug を足す動機。
- 非メンバー org は binder が 404 (存在秘匿) するため、共有 prop に他組織の slug は載せない
  (切替リストは id のみで足り、cross-org の slug 露出を作らない)。
- リンクは権限フラグで出し分けるが、最終的な認可は各エンドポイント側 Policy が担保
  (フラグは UX のための先出しであり認可の代替ではない)。押下時 403 を避けるための非表示で
  あって「必須未充足でボタンを disabled」ではない (AGENTS 禁止事項 8 に非抵触)。
- Atomic Design 単方向 import: `templates(AppLayout) ← features/organizations` は許可方向。
  スイッチャーは atoms (Button 等) と `@inertiajs/svelte` の Link/router を合成する。
- **a11y MVP (disclosure/popover パターン)**: トリガーは `<button>` で
  `aria-expanded` + `aria-controls` のみ (**`aria-haspopup` は付けない** = 通常コンテナを開く
  disclosure セマンティクスと一致させる)。展開パネルは通常コンテナ
  (**`role="menu"` は付けない** = 矢印キー等の menu keyboard contract を負わない)。
  中の項目は Link / button として **Tab で順次移動**、`Escape` で閉じてトリガーへ focus 復帰、
  click-outside で閉じる。過剰なフォーカストラップ・矢印キーナビはスコープ外 (到達不能解消が主目的)。
- **権限判定の laratrust_team_id 明示**: 権限フラグは currentOrganization を対象に
  `$user->can('manageMembers'|'manageApiKeys', $org)` で評価し、`OrganizationPolicy` は
  `organizationRole($organization)` (laratrust_team_id=$organization->id を明示・strict_check) 経由で
  判定する。別組織で付与された権限が現在組織へ漏れないことを Feature テストで固定する。

## スコープ外

- サイドバー全面刷新 / Team・Project 階層ナビ (別 Phase)。
- 組織一覧専用ページ (`GET /organizations`) の新設。
- 組織作成フロー・招待フロー本体の改修 (到達導線のみを回復する)。
- 通知ベルのドロップダウン化など他ヘッダー要素の変更。
- 権限フラグに基づく各画面内 UI のガード (既存のまま)。
