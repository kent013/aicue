# 詳細設計: login-sidebar-nav（ログイン後レイアウトの左サイドバー統一）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする（思考ゼロ・編集ゼロ）。

### 禁止事項（本施策に関係する核）
- テストなしの実装完了報告（不変条件はテスト登録まで含めて「実装済み」）
- PHPStan エラーの widen・baseline 化
- `response()->json()` の直書き（DTO / JsonResource / Inertia）
- 必須条件未充足を理由にボタンを disabled にする UI（押下時エラー表示）
- 後方互換の並走を残さない（旧実装は同一 PR で消す）

### コーディングルール
- PHPStan level 10（`composer phpstan`）/ Pest（`composer test`, `--parallel`, RefreshDatabase グローバル）
- フロント: Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical, ds-purity テスト）。
  アイコンは `@lucide/svelte` のみ。component 階層は atoms→…→templates→pages の単方向 import
  （`tests/js/architecture/atomic-import-graph.test.ts` が強制）。helper は `templates/_helpers/`。
- 検証: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` + `composer test` / `composer phpstan`
  / `vendor/bin/pint --test`。

## 概念設計リファレンス
`devnotes/20260716-1757-login-sidebar-nav/conceptual-design.md`（Round 2 APPROVED）

## 認可マッピング（Codex R2 Warning 5 対応 — Policy と 1:1 確定）

サーバの実 authorize を確認し、各 nav / user-menu 項目の可視条件を確定した:

| 項目 | route / authorize | 可視条件（クライアント） |
|---|---|---|
| ダッシュボード `/dashboard` | 認証のみ | ログイン時 |
| プロジェクト `/projects` | `viewAny [Project, org]`（org 必須） | `currentOrganization != null` |
| メンバー `/manage/users` | `manageMembers`（owner/admin） | `currentOrganization.canManageMembers`（**既存**） |
| API キー `/organizations/{slug}/api-keys` | `manageApiKeys` | `currentOrganization.canManageApiKeys`（**既存**） |
| 請求 `/billing` | `view`（**メンバー全員**） | `currentOrganization != null` |
| 設定 `/settings` | 認証のみ（個人設定） | ログイン時 |
| 組織設定 `/organizations/{slug}/settings` | `view`（**メンバー全員**） | `currentOrganization != null` |
| CLI/MCP `/organizations/{slug}/onboarding/cli|mcp` | `view`（**メンバー全員**） | `currentOrganization != null` |
| 法務 `/terms` `/privacy` `/commerce-disclosure` | public | 常時 |
| ログアウト `POST /logout` | 認証 | ログイン時 |

**結論: 新規 shared prop は不要**。既存 `canManageMembers` / `canManageApiKeys` のみ使用し、
それ以外の org 導線は `currentOrganization != null`（＝メンバー）でゲートする。org-scoped href は
`currentOrganization.slug` から組み、`currentOrganization` が null のときは項目ごと非表示のため
null 連結・403/404 導線は生じない。→ バックエンド変更ゼロ（`HandleInertiaRequests` は現状のまま）。

（注: aigenba は CLI/MCP を `canManageApiKeys` で絞るが、AI-CUE の実 authorize は `view`=メンバー
全員のため、サーバ真実に合わせメンバー可視とする。これは「リンク先 Policy と一致」の要件を満たす。）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | AppLayout をサイドバー型に全面刷新 | `resources/js/components/templates/AppLayout.svelte` | 高 |
| S2 | SidebarNavItems helper 新規移植 | `resources/js/components/templates/_helpers/SidebarNavItems.svelte`（新規） | 高 |
| S3 | SidebarUserMenu helper 新規移植（AI-CUE ルート翻訳） | `resources/js/components/templates/_helpers/SidebarUserMenu.svelte`（新規） | 高 |
| S4 | 独自 OrganizationSwitcher 退役（AppLayout へインライン移行） | `resources/js/components/features/organizations/OrganizationSwitcher.svelte`（削除） | 高 |
| S5 | 未使用 headerActions prop 廃止 | S1 に内包 | 中 |
| S6 | AppLayout.test.ts を新構造へ更新 | `tests/js/components/templates/AppLayout.test.ts` | 高 |
| S7 | capability shared prop の回帰テスト確認/補強 | `tests/Feature/**`（既存確認、不足時追加） | 中 |

---

## S1: AppLayout をサイドバー型に全面刷新

### 変更箇所
- ファイル: `resources/js/components/templates/AppLayout.svelte`（現行 106 行・上部ヘッダー型を全置換）

### 波及変更
- TypeScript 型定義: `Props` から `headerActions?` を削除。`SharedProps` / `CurrentOrganization` の
  既存型をそのまま利用（新規フィールド追加なし）。
- API Resource/DTO: なし（shared prop 追加なし）。
- テストファイル: `tests/js/components/templates/AppLayout.test.ts`（S6）。
- 他ページ: 24 ページが `AppLayout` を使用するが、いずれも `children` のみ渡し `headerActions` 未使用
  （grep 済み）。`<main>` に `children` を描画する契約は不変のため、ページ側変更は不要。

### 現行コード（要点）
上部ヘッダー（`<header>` に `<a>appName` + OrganizationSwitcher + NotificationBell + 設定 + ログアウト）、
`<main class="mx-auto max-w-6xl">{@render children()}`。`headerActions?` snippet を受けるが全ページ未使用。

### 変更後コード（構造。aigenba を AI-CUE 依存へ翻訳）
aigenba `AppLayout.svelte` の構造を移植し、以下を AI-CUE 化する:

- **依存の差し替え**:
  - `BrandLogo` → テキスト `<a href="/dashboard">{appName}</a>`
  - inline toast（`createToastTimer`/`TOAST_*`/`presentFlash`/`router.on('success')`）→ 削除し、
    AI-CUE 既存の `<ToastContainer />` + `$effect(() => consumeFlash(shared.flash))` に置換
  - `QuotaExceededModal` / `QuotaWarningBanner` / `billingFlash` → 削除（未導入機能）
  - cookie フォールバック（`LAST_ORGANIZATION_SLUG_COOKIE`/`escapeCookieNameForRegExp`/
    `fallbackOrgSlug`/URL からの slug 抽出）→ 削除。AI-CUE は `currentOrganization` shared prop が
    サーバ解決済みの現在組織を持つため不要
  - `present-flash` / `types/Flash` / `types/Billing` / `types/api` → 削除。型は `@/lib/shared-props`
    の `SharedProps` / `CurrentOrganization` / `OrganizationSummary` / `AuthUser` を使用
- **state**（aigenba 準拠）: `sidebarOpen`（localStorage 永続, キー `aicue:layout:sidebarOpen`, 既定 true）,
  `mobileMenuOpen`, `userMenuOpen`, `detailsOpen`, `orgSearchQuery`（組織 > 5 件時の検索）
- **org データ / id・slug の責務境界**（R1 Critical 対応）: `currentOrganization`（shared prop, slug 保有）を
  現在組織として直接使用（aigenba の `currentOrganization()` 導出関数・cookie fallback は不要）。切替候補は
  `organizations`（id/name/isPersonal, **slug なし**）。
  - **id** = 切替 (`router.post('/organizations/{id}/switch')`) と現在判定 (`org.id === currentOrganization.id`) に使う
  - **slug** = org-scoped URL 生成（api-keys / 組織設定 / CLI / MCP）に `currentOrganization.slug` からのみ参照
  - `OrganizationSummary` は slug を持たないため、切替候補行では slug を一切参照しない（比較キー崩れ防止）
- **isActive 仕様**（R1 Critical 対応）: 過判定を避け「完全一致 + 明示 prefix 許可集合」に限定。
  ```
  const PREFIX_ACTIVE = new Set(['/projects']); // プロジェクト詳細 /projects/{id} を親でアクティブ
  const isActive = (href: string) => path === href || (PREFIX_ACTIVE.has(href) && path.startsWith(href + '/'));
  ```
  他（/dashboard, /billing, /settings, /manage/users, /organizations/{slug}/api-keys）は完全一致のみ。
  `path` は query/hash を除いた pathname を使う（R2 対応）:
  `const path = $derived(new URL(page.url, 'http://localhost').pathname)`（Inertia の `page.url` は相対の
  ため origin はダミー。`/settings?tab=security` でも `/settings` として active 判定される）。
- **nav items**（`$derived`, 上記認可マッピング）:
  ```
  const navItems = $derived.by(() => {
    const org = shared.currentOrganization;
    const items = [
      { href: '/dashboard', label: 'ダッシュボード', icon: House },
    ];
    if (org) items.push({ href: '/projects', label: 'プロジェクト', icon: FolderKanban });
    if (org?.canManageMembers) items.push({ href: '/manage/users', label: 'メンバー', icon: UserPlus });
    if (org?.canManageApiKeys) items.push({ href: `/organizations/${org.slug}/api-keys`, label: 'API キー', icon: KeyRound });
    if (org) items.push({ href: '/billing', label: '請求', icon: CreditCard });
    items.push({ href: '/settings', label: '設定', icon: Settings });
    return items;
  });
  ```
- **NotificationBell**（R1/R2 対応）: 通知導線はベルのみ（nav 項目にしない）。desktop シェルと mobile
  シェルに各 1 個配置し testId を分ける（desktop `notification-bell` / mobile `notification-bell-mobile`）。
  - **二重マウントの根拠**（R2 Warning 対応）: `NotificationBell.svelte` は**完全な純表示**
    （props `unreadCount`/`testId` + `$derived` バッジ + `<Link>` のみ。`onMount`/`$effect`/fetch/store 購読
    などの副作用が一切ない）。よって desktop/mobile 両シェルへのマウントは副作用の二重実行を起こさない。
    これは aigenba が `SidebarNavItems` を desktop/mobile 両 aside に二重マウントしているのと同型で、
    レスポンシブ二枚シェル構成では自然な形。Codex R2 が提示した「純表示なら二重マウント許容（根拠 +
    副作用テスト）」の選択肢を採用する。S6 で「副作用がない（unread 取得等を発火しない）」ことを固定する。
  - `unreadCount = shared.notifications?.unreadCount ?? 0`
- **ゲスト時のバイパス**（R1/R2 対応）: `showAccountNav = shared.auth?.user != null`。false のとき
  **アカウント系 UI のみ非描画**（nav / 下部メニュー / ベル / mobile drawer の DOM・イベント登録
  ＝ outside-click / Escape / focus を `{#if showAccountNav}` で完全バイパス）。
  `ToastContainer` / 外側レイアウト（`min-h-screen` ラッパ）/ `{@render children()}` は描画し続ける
  （＝ゲスト到達ページも通常表示され、余計なフォーカストラップだけ走らせない）。
- **下部メニュー**（aigenba の user/org menu を移植）: トリガー（現在組織名 + ユーザー名）→ popup に
  (1) 組織切替リスト（`organizations`, 5 件超で検索, id で switch, 現在組織に Check）
  (2) `SidebarUserMenu`（個人設定/組織設定/CLI/MCP/法務/ログアウト）
  - **desktop/mobile の testId 分離**（R3 Warning 対応）: `SidebarUserMenu` は desktop popup（menu 展開時）
    と mobile drawer（常時）の 2 箇所にマウントされる。同一 testId の DOM 重複を避けるため、desktop へは
    `settingsTestId="nav-settings"` / `logoutTestId="logout-button"`、mobile へは
    `settingsTestId="nav-settings-mobile"` / `logoutTestId="logout-button-mobile"` /
    `detailsToggleTestId="details-toggle-mobile"` を渡す（aigenba と同パターン）。テストは各シェルを明示検証。
- **main**（R3 Warning 対応）: `lg:[margin-left:var(--app-sidebar-w)]` の
  `--app-sidebar-w` は **`showAccountNav ? (sidebarOpen ? 256px : 64px) : 0px`**。ゲスト時（サイドバー
  非描画）は 0px にして左空白を残さない。内側に `EmailVerificationBanner`（未認証ユーザー時）+
  `{@render children()}`
- **logout**: `router.post('/logout')`（二重送信ガードは既存 AI-CUE 実装の loggingOut を踏襲）
- **未認証（ゲスト到達）**: `shared.auth?.user == null` のとき nav / 下部メニュー / ベルを描画せず、
  `{@render children()}` のみ（現行 AI-CUE の showAccountNav ガードを踏襲）

### data-testid（S6 テストが参照）
`app-sidebar`（desktop aside）, `app-user-menu-toggle`, `app-user-menu`, `notification-bell`（desktop）/
`notification-bell-mobile`（mobile）, `nav-settings`（SidebarUserMenu の個人設定 = `settingsTestId` の値）,
`logout-button`, `org-switch-{id}` / `org-current-{id}`, `page-content`（children）。testid は極力現行と
aigenba を踏襲。

### PHPStan 適合チェック
- 該当なし（フロントのみ。バックエンド変更なし）

### テスト計画
- S6 で `AppLayout.test.ts` を新構造へ更新（下記）。

### リスク
- 全ログイン後ページへ横断影響。回帰観点（下記「回帰観点」）を S6 のテストで代表担保。
- DS: aigenba の `bg-primary-soft` `text-danger` `bg-danger/10` `text-white` `bg-text/50` `text-h2/h3`
  `text-caption` は AI-CUE tokens.css / 既存使用実績あり（検証済: primary-soft/danger/success/warning/
  neutral/surface/text/border 系は AI-CUE に存在）。移植時に非 token 色・hex 直書きを持ち込まない。

## S2: SidebarNavItems helper 新規移植

### 変更箇所
- 新規: `resources/js/components/templates/_helpers/SidebarNavItems.svelte`

### 変更後コード
aigenba 版を移植（依存は `@lucide/svelte` / `svelte` の型のみ）。
- **型 export**（R1 Warning 対応）: module script で
  `import type { Component } from 'svelte'; export interface SidebarNavItem { href: string; label: string; icon: Component }`
  を export（Svelte 5 のため deprecated な `ComponentType` は使わない）。AppLayout の navItems はこの型に一致。
- props: `items: SidebarNavItem[]`, `isActive`, `showLabel?`, `onNavigate?`。
- **onNavigate 既定 no-op**（R1 Suggestion）: `onclick={() => onNavigate?.()}` で未指定を吸収。
- active 時 `bg-primary text-white`、非 active `text-text hover:bg-neutral`。DS token は AI-CUE と共通。

### テスト計画
- 単体テストは新設しない（stateless 表示 helper。S6 の AppLayout テストが nav 描画を代表検証。
  aigenba も helper 単体テストは持たず AppLayout テストで代表）。

### リスク
- 低（純表示・無状態）。

## S3: SidebarUserMenu helper 新規移植（AI-CUE ルート翻訳）

### 変更箇所
- 新規: `resources/js/components/templates/_helpers/SidebarUserMenu.svelte`

### 変更後コード
aigenba 版を移植し、リンクを AI-CUE 実ルートへ翻訳:
- 個人設定: `/profile` → **`/settings`**（AI-CUE の個人設定, testId `nav-settings`）
- 組織設定: `/organizations/{slug}/settings`（`orgSettingsHref` prop, `currentOrganization != null` 時に渡す）
- CLI/MCP: `/organizations/{slug}/onboarding/cli|mcp`（`cliHref`/`mcpHref` prop,
  `currentOrganization != null` 時に渡す — **メンバー全員可のため canManageApiKeys ゲートにしない**）
- 詳細（折りたたみ）: 利用規約 `/terms`・プライバシー `/privacy`・特定商取引法 `/commerce-disclosure`
  （AI-CUE に存在）。**トップページ `/`・ヘルプ `/help`・運営会社外部リンクは削除**（AI-CUE のログイン後は
  `/dashboard` が起点。`/help`・運営会社外部リンクは AI-CUE に無い）
- ログアウト: `onLogout` prop（AppLayout が `router.post('/logout')`）

- **helper 側 null ガードの契約化**（R1 Critical 対応）: org-scoped href props（`orgSettingsHref` /
  `cliHref` / `mcpHref`）は型を `string | null` とし、helper は **`{#if href}` のときのみリンク描画**する
  （親が null を渡せば非表示 = サイドバーから 403 導線を作らない）。法務リンクは public のため常時表示。
- **testId 命名**（R1 Warning 対応）: `profileTestId` → **`settingsTestId`** に改名（個人設定 = `/settings`
  のため。後方互換の旧名は残さない）。値は `nav-settings`（AppLayout.test 互換）。

props: `orgSettingsHref: string | null`, `cliHref: string | null`, `mcpHref: string | null`, `detailsOpen`,
`onToggleDetails`, `onNavigate`, `onLogout`, `settingsTestId`, `logoutTestId?`, `detailsToggleTestId?`。

### テスト計画
- 単体テストは新設せず S6 で代表（aigenba 準拠）。

### リスク
- 低。リンク先の存在は確認済み（`/settings` `/terms` `/privacy` `/commerce-disclosure`
  `/organizations/{slug}/settings|onboarding/*`）。

## S4: 独自 OrganizationSwitcher 退役

### 変更箇所
- 削除: `resources/js/components/features/organizations/OrganizationSwitcher.svelte`

### 波及変更
- 利用箇所: grep 結果 **`templates/AppLayout.svelte` のみ**（他ページ参照なし）。よって S1 で
  下部メニューへ機能移行（org 切替はインライン、org 設定/メンバー/API キー/請求はサイドバー nav /
  SidebarUserMenu）した上で本ファイルを削除する（後方互換の並走を残さない）。
- テスト削除（R1 Warning 対応）: 専用テスト
  `tests/js/components/features/organizations/OrganizationSwitcher.test.ts`（**存在確認済み**）を同 PR 削除。
- 残骸掃除: barrel / index export・story・fixture の残存を grep（`OrganizationSwitcher`）で確認し全撤去。
  削除後 `pnpm typecheck` で dangling import が無いことを担保。

### リスク
- 低（単一利用箇所）。機能は S1 の下部メニューへ移行済みであることを S6 で担保。

## S5: 未使用 headerActions prop 廃止（S1 内包）
- `AppLayout` の `Props` から `headerActions?: Snippet` を削除。全 24 ページ未使用（grep 済み）。
- 既存テストの「headerActions と常設ナビ共存」ケースは S6 で削除する（snippet 廃止に伴う）。

## S6: AppLayout.test.ts を新構造へ更新

### 変更箇所
- `tests/js/components/templates/AppLayout.test.ts`

### 変更後コード（検証項目 — 回帰観点を代表担保）
既存の「常設ナビ = 単一の真実」方針を維持しつつ、新構造 testid へ更新:

**正例（表示）**
- ログイン時: `nav-settings`（href pathname `/settings`）を下部メニュー経由で描画, `logout-button`,
  `notification-bell`, `page-content` を描画
- desktop シェルで `notification-bell` が 1 個、mobile シェルで `notification-bell-mobile` が 1 個
  （同一シェル内で単一。合計 2 は viewport 別シェルの意図的配置で、NotificationBell が純表示のため安全）
- **NotificationBell 副作用なし**（R2/R3 対応）: 描画しても unread 取得等の副作用（fetch / router 呼び出し）を
  発火しないことを固定（render 後に副作用系 mock が未呼び出しであることを assert）。位置づけは
  **現在の fetch/router 非発火を固定する回帰テスト**（将来のあらゆる副作用を禁止する architecture テスト
  ではない）。
- **user menu の testId は各シェルで別値**（R3）: desktop は `nav-settings` / `logout-button`、mobile は
  `nav-settings-mobile` / `logout-button-mobile`。テストは対象シェルを明示して検証（DOM 重複回避）。
- ログアウトボタン押下 → `router.post('/logout')` 1 回
- ログアウトボタンは `disabled` でない（禁止事項 8 の系）
- 下部メニュートグル（`app-user-menu-toggle`）で組織切替 / `SidebarUserMenu` を開く。
  `currentOrganization` 設定時に現在組織名を表示、`organizations` 2 件以上で `org-switch-{id}` を描画
- **相互作用（R2 Warning 対応 — 全面置換で壊れやすい箇所）**:
  - `org-switch-{id}` 押下 → `router.post('/organizations/{id}/switch')` が正しい id で 1 回
  - mobile drawer: メニューボタン押下で開き、閉じるボタン / オーバーレイ押下で閉じる
  - Escape 押下で開いている user menu が閉じる
- desktop サイドバー折りたたみトグルで幅クラスが 256↔64 相当に切替
- 法務リンク（利用規約/プライバシー/特商法）は**認証済み user menu 内で常時表示**
  （ゲスト時は user menu 自体を描画しないため非表示）

**負例（認可連動の非表示 — R1 Critical 対応）**
- `canManageMembers=false` → メンバー導線（`/manage/users`）非表示
- `canManageApiKeys=false` → API キー導線（`/organizations/{slug}/api-keys`）非表示
- `currentOrganization=null` → org-scoped 導線（プロジェクト / 請求 / 組織設定 / CLI / MCP）非表示
  （ダッシュボード・設定・法務・ログアウトは表示のまま）
- `auth.user == null`（ゲスト到達）→ nav / 下部メニュー / 設定 / ログアウト / ベルを描画しない,
  `page-content` は描画
- `notifications` undefined でもクラッシュせず unread バッジ非表示

**永続（R1 Suggestion）**
- localStorage `aicue:layout:sidebarOpen=false` を置いた状態で再マウント → 折りたたみ状態が復元される

- **削除**: 旧「headerActions snippet と常設ナビ共存」ケース（prop 廃止）、`org-switcher-trigger`
  （旧 OrganizationSwitcher）依存ケースは新 `app-user-menu` 構造へ置換

### テスト計画
- 上記を Vitest + @testing-library/svelte で。router は現行同様 `vi.mock` で `post` を差し替え、
  page.props をケースごとに設定。

### リスク
- テスト自体の更新のため低。回帰観点（下記）を漏れなく反映することが要件。

## S7: capability shared prop の回帰テスト確認/補強

### 変更箇所
- 既存 `tests/Feature/**`（`HandleInertiaRequests` / shared props の既存テストを確認）

### 内容（R1 Critical 対応 — 存在確認に留めず shape を固定）
nav の可視条件は既存 `currentOrganization.canManageMembers` / `canManageApiKeys` と
`currentOrganization != null` に依存する。UI 可視条件の根幹変更に対する回帰防止として、Feature テスト
**「sidebar visibility contract」**を追加し、`currentOrganizationProp` の shape 期待値を固定する:
- (a) owner/admin メンバーで `canManageMembers` / `canManageApiKeys` = true
- (b) 一般メンバー（manage 権限なし）で両 flag = false かつ `currentOrganization != null`（＝ view 可）
- (c) 未認証で `currentOrganization = null`
- (d) 別組織で付与した manage 権限が current org に漏れない（cross-org 分離）1 ケース

既存の cross-org 固定テスト・shared prop テストがあれば流用・拡張する。テスト名/docblock に
「sidebar visibility contract」を含め、UI 可視条件がこの shared prop に依存することを明示（将来破壊の検知）。
新規 prop は追加しないが、確認のみに留めず上記 shape を明示 assert する。

### PHPStan 適合チェック
- 変更が入る場合も array shape docblock（`currentOrganizationProp` の `@return array{...}|null`）は
  現状維持（フィールド不変）。widen しない。

## 回帰観点（S6 で代表担保 + 実装時手動確認）
1. desktop サイドバー展開/折りたたみ（localStorage 永続）
2. mobile drawer 開閉 + オーバーレイ
3. `EmailVerificationBanner` / flash→toast の積み上がり（重複描画なし = children 単一ラッパー）
4. org 切替（下部メニュー, id で POST /organizations/{id}/switch）
5. logout POST `/logout`
6. 未認証で nav / 設定 / ログアウト / ベル非表示
7. popover / drawer の focus 管理・キーボード（Escape / outside click）

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | AppLayout 全面置換 + helper 新規 + OrganizationSwitcher 削除は密結合の一括変更で、部分適用だと壊れる（旧 header と新 sidebar の混在不可）。バックエンド変更が無く他施策と独立。 |
| 競合リスク | 低（他 TODO と非干渉。ただし AppLayout は全ページ共通のため、同時期の他 UI 変更とはマージ順に注意） |
