## アプリの使命（North Star）
AI-CUE は、現場の作業手順書(SOP)を起点に AI が撮るべきカットを設計した動画シナリオを生成し、
スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル
動画を作れるようにする（思考ゼロ・編集ゼロ）。

## 禁止事項（核）
1. PHPStan エラーを無視（ignore/baseline/widen）
2. テストなしの実装完了
3. 既存テストの削除（リグレッション検知不能）
4. `response()->json()` の直書き（DTO/JsonResource/Inertia）
- 後方互換の並走を残さない（旧実装は同一 PR で消す）

## 思考原則・ツール使用制限
先人の知恵（Laravel/Svelte 既存解）を使え。機能の名前に立ち返れ。
コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中（ファイル読み込みは可）。

---

あなたはコードレビュアーです。Laravel + Svelte の改善実装をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5(runes) + Inertia.js + TypeScript / PHPStan level 10 / Pest。

【レビュー観点】
1. 設計との一致性（下記詳細設計 S1〜S7 のとおり実装されているか）
2. コードの正確性（ロジック、エッジケース、null 安全）
3. PHPStan level 10 適合性
4. DTO/JsonResource パターン（本件はバックエンド変更が Feature テスト追加のみ）
5. テストの網羅性（認可負例・主要インタラクション・sidebar visibility contract）
6. セキュリティ（サイドバーから 403 導線を作らない = org-scoped は capability + currentOrganization!=null でゲート）
7. DESIGN.md 準拠（color/radius/typography を token 経由。hex 直書きを増やさない。ds-purity テスト通過済み）
8. Atomic Design 準拠（atoms/molecules/organisms/templates の責務・単方向 import。helper は templates/_helpers/。
   アイコンは Lucide、SVG 直書き新設なし）

【出力形式】
- ファイルごとに判定、指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書（APPROVED 版）

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


---

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/organizations/OrganizationSwitcher.svelte b/resources/js/components/features/organizations/OrganizationSwitcher.svelte
deleted file mode 100644
index 0e5c92a..0000000
--- a/resources/js/components/features/organizations/OrganizationSwitcher.svelte
+++ /dev/null
@@ -1,226 +0,0 @@
-<script lang="ts">
-    import { Link, router } from "@inertiajs/svelte";
-    import {
-        Check,
-        ChevronsUpDown,
-        CreditCard,
-        KeyRound,
-        Plus,
-        Settings,
-        Tag,
-        Users,
-    } from "@lucide/svelte";
-    import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";
-
-    /**
-     * 組織スイッチャー兼組織メニュー (disclosure パターン)。
-     * 現在組織を表示するトリガー + 展開パネルで「組織切替」と「組織設定/メンバー/API キー/
-     * 請求/料金」への恒常導線を提供する (North Star: 組織横断運用の到達導線を回復)。
-     *
-     * 純粋・テスト容易にするため shared prop は親 (AppLayout) が読んで props で渡す。
-     * cross-org 防御は backend (currentOrganizationProp の isMemberOf + Policy 評価) が担い、
-     * ここは受け取った権限フラグでリンクを出し分けるだけ (二重判定しない)。
-     *
-     * a11y: disclosure セマンティクス (aria-expanded/aria-controls。role=menu は付けない)。
-     * Escape / outside pointerdown / focusout の 3 経路で閉じる。禁止事項 8 に従い現在組織項目や
-     * リンクを disabled にしない (現在組織は非対話行、他は遷移)。
-     *
-     * 設計との差分 (意図的): 詳細設計 S3 は「内部は atoms(Button) を合成」と記したが、トリガー/
-     * 切替行は Button atom の variant スタイル (枠線・padding ramp) と噛み合わない menu-item 表現が
-     * 必要なため native <button> を採用する。Button atom は単機能ボタン用で、id/aria-expanded/
-     * aria-controls を要する disclosure トリガーには過剰。DS token は同一 (rounded-md/border-border/
-     * bg-surface/text-body)。Lucide のみ・SVG 直書きなしは維持する。
-     */
-    interface Props {
-        /** 現在の組織 (未所属/未設定時は null → 「組織を作成」フォールバック) */
-        currentOrganization: CurrentOrganization | null;
-        /** 所属組織一覧 (切替候補。id で switch) */
-        organizations: OrganizationSummary[];
-    }
-
-    let { currentOrganization, organizations }: Props = $props();
-
-    let open = $state(false);
-    let root = $state<HTMLDivElement | null>(null);
-    let trigger = $state<HTMLButtonElement | null>(null);
-
-    const triggerLabel = $derived(currentOrganization?.name ?? "組織を選択");
-    // 切替候補セクションは 2 組織以上のときのみ (1 組織なら切替不要)
-    const showSwitchSection = $derived(organizations.length > 1);
-
-    function close(): void {
-        open = false;
-    }
-
-    function toggle(): void {
-        open = !open;
-    }
-
-    function switchTo(id: number): void {
-        // Ziggy 未導入のため文字列パス直書きが既存標準 (cf. Admin/Users.svelte)。
-        router.post(`/organizations/${id}/switch`);
-        close();
-    }
-
-    // focusout: Tab 等でルート外へ focus が抜けたら閉じる (focus 系は静的要素でも a11y 上許容)
-    function onFocusOut(event: FocusEvent): void {
-        const next = event.relatedTarget;
-        if (next instanceof Node && root?.contains(next)) {
-            return;
-        }
-        close();
-    }
-
-    // open の間だけ document へ pointerdown / keydown を張り、outside クリックと Escape で閉じる。
-    // keydown を静的な wrapper div に載せると a11y_no_static_element_interactions になるため
-    // document スコープに寄せる (disclosure の open 中のみ有効化)。
-    $effect(() => {
-        if (!open) {
-            return;
-        }
-        function onPointerDown(event: PointerEvent): void {
-            const target = event.target;
-            if (target instanceof Node && root?.contains(target)) {
-                return;
-            }
-            close();
-        }
-        function onKeydown(event: KeyboardEvent): void {
-            if (event.key === "Escape") {
-                close();
-                trigger?.focus();
-            }
-        }
-        document.addEventListener("pointerdown", onPointerDown);
-        document.addEventListener("keydown", onKeydown);
-        return () => {
-            document.removeEventListener("pointerdown", onPointerDown);
-            document.removeEventListener("keydown", onKeydown);
-        };
-    });
-</script>
-
-<div class="relative shrink-0" bind:this={root} onfocusout={onFocusOut}>
-    <button
-        type="button"
-        id="org-switcher-trigger"
-        class="inline-flex shrink-0 items-center gap-2 rounded-md border border-border
-            bg-surface px-3 py-1.5 text-body text-text hover:bg-neutral"
-        aria-expanded={open}
-        aria-controls="org-switcher-panel"
-        onclick={toggle}
-        bind:this={trigger}
-        data-testid="org-switcher-trigger"
-    >
-        <span class="max-w-40 truncate">{triggerLabel}</span>
-        <ChevronsUpDown class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-    </button>
-
-    {#if open}
-        <div
-            id="org-switcher-panel"
-            class="absolute right-0 z-20 mt-1 w-64 rounded-md border border-border bg-surface py-1"
-            aria-labelledby="org-switcher-trigger"
-        >
-            {#if currentOrganization != null}
-                {#if showSwitchSection}
-                    <p class="px-3 py-1 text-caption text-text-secondary">組織を切り替え</p>
-                    {#each organizations as org (org.id)}
-                        {#if org.id === currentOrganization.id}
-                            <div
-                                class="flex items-center gap-2 px-3 py-2 text-body text-text"
-                                aria-current="true"
-                                data-testid="org-current-{org.id}"
-                            >
-                                <Check
-                                    class="size-4 shrink-0 text-primary"
-                                    aria-hidden="true"
-                                />
-                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
-                                {#if org.isPersonal}
-                                    <span class="text-caption text-text-secondary">個人</span>
-                                {/if}
-                                <span class="text-caption text-text-secondary">現在の組織</span>
-                            </div>
-                        {:else}
-                            <button
-                                type="button"
-                                class="flex w-full items-center gap-2 px-3 py-2 text-left
-                                    text-body text-text hover:bg-neutral"
-                                onclick={() => switchTo(org.id)}
-                                data-testid="org-switch-{org.id}"
-                            >
-                                <span class="size-4 shrink-0" aria-hidden="true"></span>
-                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
-                                {#if org.isPersonal}
-                                    <span class="text-caption text-text-secondary">個人</span>
-                                {/if}
-                            </button>
-                        {/if}
-                    {/each}
-                    <div class="my-1 border-t border-border" role="separator"></div>
-                {/if}
-
-                <Link
-                    href={`/organizations/${currentOrganization.slug}/settings`}
-                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                    onclick={close}
-                    data-testid="org-link-settings"
-                >
-                    <Settings class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                    組織設定
-                </Link>
-                {#if currentOrganization.canManageMembers}
-                    <Link
-                        href="/manage/users"
-                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                        onclick={close}
-                        data-testid="org-link-members"
-                    >
-                        <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                        メンバー管理
-                    </Link>
-                {/if}
-                {#if currentOrganization.canManageApiKeys}
-                    <Link
-                        href={`/organizations/${currentOrganization.slug}/api-keys`}
-                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                        onclick={close}
-                        data-testid="org-link-api-keys"
-                    >
-                        <KeyRound class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                        API キー
-                    </Link>
-                {/if}
-                <Link
-                    href="/billing"
-                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                    onclick={close}
-                    data-testid="org-link-billing"
-                >
-                    <CreditCard class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                    請求
-                </Link>
-                <Link
-                    href="/pricing"
-                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                    onclick={close}
-                    data-testid="org-link-pricing"
-                >
-                    <Tag class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                    料金
-                </Link>
-            {:else}
-                <Link
-                    href="/organizations/create"
-                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
-                    onclick={close}
-                    data-testid="org-link-create"
-                >
-                    <Plus class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
-                    組織を作成
-                </Link>
-            {/if}
-        </div>
-    {/if}
-</div>
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index 82f973d..df859f1 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -1,31 +1,46 @@
 <script lang="ts">
     import type { Snippet } from "svelte";
+    import { onMount } from "svelte";
     import { page, router } from "@inertiajs/svelte";
-    import Button from "@/components/atoms/Button.svelte";
-    import TextLink from "@/components/atoms/TextLink.svelte";
+    import {
+        Building2,
+        Check,
+        ChevronLeft,
+        ChevronRight,
+        ChevronUp,
+        CreditCard,
+        FolderKanban,
+        House,
+        KeyRound,
+        Menu,
+        Plus,
+        Settings,
+        UserPlus,
+        X,
+    } from "@lucide/svelte";
     import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
-    import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
     import NotificationBell from "@/components/molecules/NotificationBell.svelte";
     import ToastContainer from "@/components/organisms/ToastContainer.svelte";
+    import SidebarNavItems, {
+        type SidebarNavItem,
+    } from "@/components/templates/_helpers/SidebarNavItems.svelte";
+    import SidebarUserMenu from "@/components/templates/_helpers/SidebarUserMenu.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import { consumeFlash } from "@/lib/stores/flash-to-toast";
 
     /**
-     * 認証済み画面用レイアウト (最小骨格)。
-     * 組織スイッチャー/組織メニューを常設 (組織切替・組織設定/請求/招待/API キー導線)。
-     * サイドバー/Team/Project ナビは後続 Phase。
-     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
-     * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
-     * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
+     * 認証済み画面用レイアウト (左サイドバー型。参照アプリ aigenba 準拠)。
+     * desktop 固定サイドバー + 折りたたみ + モバイルドロワー + 下部に組織/ユーザーメニュー。
+     * ナビ可視条件はサーバ真実の shared prop (currentOrganization + capability) で出し分ける
+     * (サイドバーから 403 導線を作らない)。通知は NotificationBell を単一導線とし、Laravel flash は
+     * consumeFlash で toast に変換する。ログアウト POST はこのレイアウトの単一ハンドラに一本化する。
      */
     interface Props {
         appName: string;
         children: Snippet;
-        /** ヘッダー右側のページ固有の追加アクション (常設ナビの左に並ぶ) */
-        headerActions?: Snippet;
     }
 
-    let { appName, children, headerActions }: Props = $props();
+    let { appName, children }: Props = $props();
 
     // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
     const shared = $derived(page.props as unknown as SharedProps);
@@ -34,18 +49,108 @@
         consumeFlash(shared.flash);
     });
 
-    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
-    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
+    // ログイン時のみ nav / 下部メニュー / ベルを常設 (ゲスト到達ページでは children のみ)
+    const showAccountNav = $derived(shared.auth?.user != null);
+    const currentOrganization = $derived(shared.currentOrganization ?? null);
+    const organizations = $derived(shared.organizations ?? []);
+    const userName = $derived(shared.auth?.user?.name ?? "ユーザー");
+    const orgName = $derived(currentOrganization?.name ?? "組織未選択");
+    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);
+
+    // メール未認証のソフトゲート案内
     const showEmailBanner = $derived(
         shared.auth?.user != null && shared.auth.user.emailVerified === false,
     );
 
-    // ログイン時のみベル + アカウントナビ (設定/ログアウト) を常設する
-    // (invitations.accept 等、ゲスト到達がある AppLayout ページでは出さない)
-    const showAccountNav = $derived(shared.auth?.user != null);
+    const SIDEBAR_STORAGE_KEY = "aicue:layout:sidebarOpen";
 
+    let sidebarOpen = $state(true);
+    let mobileMenuOpen = $state(false);
+    let userMenuOpen = $state(false);
+    let detailsOpen = $state(false);
+    let orgSearchQuery = $state("");
     let loggingOut = $state(false);
 
+    onMount(() => {
+        const saved = localStorage.getItem(SIDEBAR_STORAGE_KEY);
+        if (saved !== null) {
+            sidebarOpen = saved === "true";
+        }
+    });
+
+    // active 判定: query/hash を除いた pathname で完全一致 + 明示 prefix 許可 (/projects のみ)。
+    const PREFIX_ACTIVE = new Set(["/projects"]);
+    const path = $derived(new URL(page.url, "http://localhost").pathname);
+    const isActive = (href: string): boolean =>
+        path === href || (PREFIX_ACTIVE.has(href) && path.startsWith(href + "/"));
+
+    // org-scoped href は currentOrganization.slug からのみ生成 (org なし時 null = 非表示)
+    const orgSettingsHref = $derived(
+        currentOrganization ? `/organizations/${currentOrganization.slug}/settings` : null,
+    );
+    const cliHref = $derived(
+        currentOrganization ? `/organizations/${currentOrganization.slug}/onboarding/cli` : null,
+    );
+    const mcpHref = $derived(
+        currentOrganization ? `/organizations/${currentOrganization.slug}/onboarding/mcp` : null,
+    );
+
+    // nav 項目 (認可マッピング: 詳細設計参照)。org-scoped は currentOrganization != null + capability でゲート。
+    const navItems = $derived.by((): SidebarNavItem[] => {
+        const org = currentOrganization;
+        const items: SidebarNavItem[] = [
+            { href: "/dashboard", label: "ダッシュボード", icon: House },
+        ];
+        if (org) items.push({ href: "/projects", label: "プロジェクト", icon: FolderKanban });
+        if (org?.canManageMembers)
+            items.push({ href: "/manage/users", label: "メンバー", icon: UserPlus });
+        if (org?.canManageApiKeys)
+            items.push({
+                href: `/organizations/${org.slug}/api-keys`,
+                label: "API キー",
+                icon: KeyRound,
+            });
+        if (org) items.push({ href: "/billing", label: "請求", icon: CreditCard });
+        items.push({ href: "/settings", label: "設定", icon: Settings });
+        return items;
+    });
+
+    // 組織検索 (5 件超で検索窓)
+    const filteredOrganizations = $derived.by(() => {
+        const q = orgSearchQuery.trim().toLowerCase();
+        if (!q) return organizations;
+        return organizations.filter((org) => org.name.toLowerCase().includes(q));
+    });
+
+    function toggleSidebar(): void {
+        sidebarOpen = !sidebarOpen;
+        localStorage.setItem(SIDEBAR_STORAGE_KEY, String(sidebarOpen));
+        if (!sidebarOpen) closeUserMenu();
+    }
+
+    function toggleMobileMenu(): void {
+        mobileMenuOpen = !mobileMenuOpen;
+    }
+    function closeMobileMenu(): void {
+        mobileMenuOpen = false;
+        closeUserMenu();
+    }
+    function toggleUserMenu(): void {
+        userMenuOpen = !userMenuOpen;
+        if (!userMenuOpen) {
+            detailsOpen = false;
+            orgSearchQuery = "";
+        }
+    }
+    function closeUserMenu(): void {
+        userMenuOpen = false;
+        detailsOpen = false;
+        orgSearchQuery = "";
+    }
+    function toggleDetails(): void {
+        detailsOpen = !detailsOpen;
+    }
+
     // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
     function logout(): void {
         if (loggingOut) return;
@@ -62,45 +167,310 @@
             },
         );
     }
+
+    // 組織切替は id で POST (既存 AI-CUE 仕様。slug ではない)
+    function selectOrganization(id: number): void {
+        router.post(`/organizations/${id}/switch`);
+        closeUserMenu();
+        closeMobileMenu();
+    }
+
+    // user menu 展開中のみ Escape で閉じる (outside click は overlay button が担う)
+    $effect(() => {
+        if (!userMenuOpen) return;
+        function onKeydown(event: KeyboardEvent): void {
+            if (event.key === "Escape") closeUserMenu();
+        }
+        document.addEventListener("keydown", onKeydown);
+        return () => document.removeEventListener("keydown", onKeydown);
+    });
 </script>
 
 <ToastContainer />
 
-<div class="flex min-h-screen flex-col bg-neutral text-text">
-    <header class="border-b border-border bg-surface">
-        <!-- 375px 方針: ロゴは shrink-0、右側アクション群は flex-wrap で行内折り返し (2 段化) -->
-        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-8 py-3">
-            <a href="/dashboard" class="shrink-0 text-h3 text-primary">{appName}</a>
-            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
-                {#if headerActions}
-                    {@render headerActions()}
+<div class="min-h-screen bg-neutral text-text">
+    {#if showAccountNav}
+        <!-- Mobile Top Bar -->
+        <div
+            class="sticky top-0 z-30 flex h-14 items-center justify-between gap-3 border-b border-border bg-surface px-4 lg:hidden"
+        >
+            <div class="flex items-center gap-3">
+                <button
+                    onclick={toggleMobileMenu}
+                    class="flex size-10 items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-neutral hover:text-text"
+                    type="button"
+                    aria-label="メニューを開く"
+                    data-testid="mobile-menu-button"
+                >
+                    <Menu class="size-6" aria-hidden="true" />
+                </button>
+                <a href="/dashboard" class="text-h3 text-primary">{appName}</a>
+            </div>
+            <NotificationBell {unreadCount} testId="notification-bell-mobile" />
+        </div>
+
+        <!-- Desktop Sidebar -->
+        <aside
+            class="fixed top-0 left-0 z-40 hidden h-screen flex-col border-r border-border bg-surface transition-all duration-300 lg:flex"
+            style="width: {sidebarOpen ? '256px' : '64px'}"
+            data-testid="app-sidebar"
+        >
+            <!-- Logo / Brand + bell -->
+            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-4">
+                <a
+                    href="/dashboard"
+                    class="truncate text-h3 text-primary {sidebarOpen ? '' : 'sr-only'}"
+                >
+                    {appName}
+                </a>
+                {#if sidebarOpen}
+                    <NotificationBell {unreadCount} testId="notification-bell" />
                 {/if}
-                {#if showAccountNav}
-                    <OrganizationSwitcher
-                        currentOrganization={shared.currentOrganization ?? null}
-                        organizations={shared.organizations ?? []}
-                    />
-                    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
-                    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
-                    <Button
-                        variant="ghost"
-                        size="sm"
-                        onclick={logout}
-                        loading={loggingOut}
-                        testId="nav-logout"
+            </div>
+
+            <!-- Collapse Toggle -->
+            <button
+                type="button"
+                onclick={toggleSidebar}
+                aria-label={sidebarOpen ? "サイドバーを折りたたむ" : "サイドバーを開く"}
+                class="absolute top-[73px] -right-3 z-50 hidden size-6 -translate-y-1/2 items-center justify-center rounded-lg border border-border bg-surface text-text-secondary transition-colors hover:bg-neutral hover:text-text lg:flex"
+                data-testid="sidebar-collapse-toggle"
+            >
+                {#if sidebarOpen}
+                    <ChevronLeft class="size-3.5" aria-hidden="true" />
+                {:else}
+                    <ChevronRight class="size-3.5" aria-hidden="true" />
+                {/if}
+            </button>
+
+            <!-- Nav -->
+            <SidebarNavItems items={navItems} {isActive} showLabel={sidebarOpen} />
+
+            <!-- User / Organization Menu (bottom) -->
+            <div class="relative border-t border-border">
+                {#if userMenuOpen}
+                    <button
+                        type="button"
+                        class="fixed inset-0 z-30 cursor-default"
+                        aria-label="メニューを閉じる"
+                        onclick={closeUserMenu}
+                    ></button>
+                {/if}
+
+                <button
+                    type="button"
+                    onclick={toggleUserMenu}
+                    data-testid="app-user-menu-toggle"
+                    class="relative z-40 flex w-full items-center gap-3 px-3 py-3 transition-colors hover:bg-neutral {sidebarOpen
+                        ? ''
+                        : 'justify-center'}"
+                    aria-haspopup="menu"
+                    aria-expanded={userMenuOpen}
+                    title={`${orgName} / ${userName}`}
+                >
+                    <div
+                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white"
+                    >
+                        <Building2 class="size-5" aria-hidden="true" />
+                    </div>
+                    {#if sidebarOpen}
+                        <div class="min-w-0 flex-1 text-left">
+                            <p class="truncate text-caption font-medium text-text">{orgName}</p>
+                            <p class="truncate text-caption text-text-secondary">{userName}</p>
+                        </div>
+                        <ChevronUp
+                            class="size-4 shrink-0 text-text-secondary transition-transform {userMenuOpen
+                                ? 'rotate-180'
+                                : ''}"
+                            aria-hidden="true"
+                        />
+                    {/if}
+                </button>
+
+                {#if userMenuOpen}
+                    <div
+                        class="absolute bottom-full left-2 z-40 mb-2 max-h-[70vh] w-64 overflow-y-auto rounded-lg border border-border bg-surface py-2"
+                        data-testid="app-user-menu"
+                        role="menu"
                     >
-                        ログアウト
-                    </Button>
+                        <!-- Organization Switcher -->
+                        {#if organizations.length > 0}
+                            <div class="px-2">
+                                <p
+                                    class="mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase"
+                                >
+                                    組織
+                                </p>
+                                {#if organizations.length > 5}
+                                    <div class="mb-1 px-1">
+                                        <input
+                                            type="text"
+                                            bind:value={orgSearchQuery}
+                                            placeholder="組織を検索…"
+                                            class="w-full rounded-lg border border-border-strong px-3 py-1.5 text-caption focus:ring-2 focus:ring-primary focus:outline-none"
+                                            onclick={(e) => e.stopPropagation()}
+                                        />
+                                    </div>
+                                {/if}
+                                <div class="max-h-48 space-y-0.5 overflow-y-auto">
+                                    {#each filteredOrganizations as org (org.id)}
+                                        {#if org.id === currentOrganization?.id}
+                                            <div
+                                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text"
+                                                data-testid="org-current-{org.id}"
+                                                aria-current="true"
+                                            >
+                                                <Check
+                                                    class="size-4 shrink-0 text-primary"
+                                                    aria-hidden="true"
+                                                />
+                                                <span class="flex-1 truncate">{org.name}</span>
+                                            </div>
+                                        {:else}
+                                            <button
+                                                type="button"
+                                                onclick={() => selectOrganization(org.id)}
+                                                data-testid="org-switch-{org.id}"
+                                                class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-caption text-text transition-colors hover:bg-neutral"
+                                            >
+                                                <span class="size-4 shrink-0" aria-hidden="true"></span>
+                                                <span class="flex-1 truncate">{org.name}</span>
+                                            </button>
+                                        {/if}
+                                    {/each}
+                                </div>
+                            </div>
+                            <div class="my-2 border-t border-border"></div>
+                        {/if}
+
+                        <SidebarUserMenu
+                            {orgSettingsHref}
+                            {cliHref}
+                            {mcpHref}
+                            {detailsOpen}
+                            onToggleDetails={toggleDetails}
+                            onNavigate={closeUserMenu}
+                            onLogout={logout}
+                            settingsTestId="nav-settings"
+                            logoutTestId="logout-button"
+                        />
+                    </div>
                 {/if}
             </div>
-        </div>
-    </header>
-    <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
-        {#if showEmailBanner}
-            <div class="mb-6">
-                <EmailVerificationBanner />
-            </div>
+        </aside>
+
+        <!-- Mobile Sidebar Overlay -->
+        {#if mobileMenuOpen}
+            <button
+                type="button"
+                class="fixed inset-0 z-40 cursor-default bg-text/50 lg:hidden"
+                aria-label="メニューを閉じる"
+                onclick={closeMobileMenu}
+            ></button>
         {/if}
-        {@render children()}
+
+        <!-- Mobile Sidebar Drawer -->
+        <aside
+            class="fixed top-0 left-0 z-50 flex h-full w-64 flex-col border-r border-border bg-surface transition-transform duration-300 lg:hidden {mobileMenuOpen
+                ? 'translate-x-0'
+                : '-translate-x-full'}"
+            data-testid="app-sidebar-mobile"
+        >
+            <div class="flex shrink-0 items-center justify-between border-b border-border px-4 py-4">
+                <a href="/dashboard" onclick={closeMobileMenu} class="text-h3 text-primary">
+                    {appName}
+                </a>
+                <button
+                    onclick={closeMobileMenu}
+                    class="rounded-lg p-2 text-text-secondary transition-colors hover:bg-neutral hover:text-text"
+                    type="button"
+                    aria-label="メニューを閉じる"
+                    data-testid="mobile-menu-close"
+                >
+                    <X class="size-5" aria-hidden="true" />
+                </button>
+            </div>
+
+            <SidebarNavItems items={navItems} {isActive} showLabel onNavigate={closeMobileMenu} />
+
+            <!-- Mobile: User / Organization Section (常時展開) -->
+            <div class="min-h-0 overflow-y-auto border-t border-border px-2 py-3">
+                <div class="mb-2 flex items-center gap-2 px-2">
+                    <div
+                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white"
+                    >
+                        <Building2 class="size-5" aria-hidden="true" />
+                    </div>
+                    <div class="min-w-0 flex-1">
+                        <p class="truncate text-caption font-medium text-text">{orgName}</p>
+                        <p class="truncate text-caption text-text-secondary">{userName}</p>
+                    </div>
+                </div>
+
+                {#if organizations.length > 0}
+                    <p
+                        class="mt-2 mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase"
+                    >
+                        組織
+                    </p>
+                    <div class="max-h-40 space-y-0.5 overflow-y-auto">
+                        {#each organizations as org (org.id)}
+                            {#if org.id === currentOrganization?.id}
+                                <div
+                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text"
+                                    data-testid="org-current-mobile-{org.id}"
+                                    aria-current="true"
+                                >
+                                    <Check class="size-4 shrink-0 text-primary" aria-hidden="true" />
+                                    <span class="flex-1 truncate">{org.name}</span>
+                                </div>
+                            {:else}
+                                <button
+                                    type="button"
+                                    onclick={() => selectOrganization(org.id)}
+                                    data-testid="org-switch-mobile-{org.id}"
+                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-caption text-text transition-colors hover:bg-neutral"
+                                >
+                                    <span class="size-4 shrink-0" aria-hidden="true"></span>
+                                    <span class="flex-1 truncate">{org.name}</span>
+                                </button>
+                            {/if}
+                        {/each}
+                    </div>
+                {/if}
+
+                <div class="mt-2 border-t border-border pt-2">
+                    <SidebarUserMenu
+                        {orgSettingsHref}
+                        {cliHref}
+                        {mcpHref}
+                        {detailsOpen}
+                        onToggleDetails={toggleDetails}
+                        onNavigate={closeMobileMenu}
+                        onLogout={logout}
+                        settingsTestId="nav-settings-mobile"
+                        logoutTestId="logout-button-mobile"
+                        detailsToggleTestId="details-toggle-mobile"
+                    />
+                </div>
+            </div>
+        </aside>
+    {/if}
+
+    <!-- Main Content -->
+    <main
+        class="w-full flex-1 transition-all duration-300"
+        style="--app-sidebar-w: {showAccountNav ? (sidebarOpen ? '256px' : '64px') : '0px'};"
+    >
+        <div class="transition-[margin-left] duration-300 lg:[margin-left:var(--app-sidebar-w)]">
+            {#if showEmailBanner}
+                <div class="px-4 pt-4 lg:px-8">
+                    <EmailVerificationBanner />
+                </div>
+            {/if}
+            <div class="px-4 py-6 lg:px-8" data-testid="app-main">
+                {@render children()}
+            </div>
+        </div>
     </main>
 </div>
diff --git a/resources/js/components/templates/_helpers/SidebarNavItems.svelte b/resources/js/components/templates/_helpers/SidebarNavItems.svelte
new file mode 100644
index 0000000..fa6ef05
--- /dev/null
+++ b/resources/js/components/templates/_helpers/SidebarNavItems.svelte
@@ -0,0 +1,49 @@
+<script lang="ts" module>
+    import type { Component } from "svelte";
+
+    /** サイドバー nav item の型 (AppLayout の navItems はこれに一致させる)。 */
+    export interface SidebarNavItem {
+        href: string;
+        label: string;
+        icon: Component;
+    }
+</script>
+
+<script lang="ts">
+    /**
+     * SidebarNavItems — AppLayout の desktop/mobile sidebar で重複する nav item list の
+     * stateless 表示 helper。state/handler/ref は AppLayout 残置、本 helper は props のみで描画する
+     * (aigenba の同名 helper を AI-CUE の型/DS へ移植)。
+     */
+    interface Props {
+        items: SidebarNavItem[];
+        /** active 判定 (AppLayout の isActive をそのまま渡す) */
+        isActive: (href: string) => boolean;
+        /** label 表示 (desktop collapsed 時 false、mobile 常時 true) */
+        showLabel?: boolean;
+        /** nav クリック時の追加処理 (mobile drawer close 等。desktop は未指定 = no-op) */
+        onNavigate?: () => void;
+    }
+
+    let { items, isActive, showLabel = true, onNavigate }: Props = $props();
+</script>
+
+<nav class="flex-1 space-y-1 overflow-y-auto px-2 py-4">
+    {#each items as item (item.href)}
+        {@const Icon = item.icon}
+        <a
+            href={item.href}
+            onclick={() => onNavigate?.()}
+            class="flex items-center gap-3 rounded-lg px-3 py-3 transition-colors {isActive(item.href)
+                ? 'bg-primary text-white'
+                : 'text-text hover:bg-neutral'}"
+            title={item.label}
+            data-testid="nav-item-{item.href}"
+        >
+            <Icon class="size-5 shrink-0" aria-hidden="true" />
+            {#if showLabel}
+                <span class="truncate text-caption font-medium">{item.label}</span>
+            {/if}
+        </a>
+    {/each}
+</nav>
diff --git a/resources/js/components/templates/_helpers/SidebarUserMenu.svelte b/resources/js/components/templates/_helpers/SidebarUserMenu.svelte
new file mode 100644
index 0000000..c1899d4
--- /dev/null
+++ b/resources/js/components/templates/_helpers/SidebarUserMenu.svelte
@@ -0,0 +1,132 @@
+<script lang="ts">
+    /**
+     * SidebarUserMenu — AppLayout の desktop popup / mobile section で重複する
+     * 「個人設定 / 組織設定 / CLI / MCP + 詳細(法務) + ログアウト」の stateless 表示 helper
+     * (aigenba の同名 helper を AI-CUE の実ルートへ翻訳)。
+     *
+     * state/handler は AppLayout 残置、本 helper は props のみで描画。org-scoped href は親が
+     * null を渡せば非表示 = サイドバーから 403 導線を作らない (helper 側 {#if href} ガードが契約)。
+     */
+    import {
+        Settings,
+        Terminal,
+        Plug,
+        ChevronRight,
+        FileText,
+        Shield,
+        Scale,
+        LogOut,
+    } from "@lucide/svelte";
+
+    interface Props {
+        /** 組織設定 href (currentOrganization なし時 null) */
+        orgSettingsHref: string | null;
+        /** CLI セットアップ href (currentOrganization なし時 null) */
+        cliHref: string | null;
+        /** MCP セットアップ href (currentOrganization なし時 null) */
+        mcpHref: string | null;
+        detailsOpen: boolean;
+        onToggleDetails: () => void;
+        /** link クリック時の close 処理 (desktop closeUserMenu / mobile closeMobileMenu) */
+        onNavigate: () => void;
+        onLogout: () => void;
+        /** 個人設定 link の testId (desktop 'nav-settings' / mobile 'nav-settings-mobile') */
+        settingsTestId: string;
+        /** ログアウト button の testId (desktop 'logout-button' / mobile 'logout-button-mobile') */
+        logoutTestId?: string;
+        /** 詳細トグル button の testId (mobile 'details-toggle-mobile' / desktop undefined) */
+        detailsToggleTestId?: string;
+    }
+
+    let {
+        orgSettingsHref,
+        cliHref,
+        mcpHref,
+        detailsOpen,
+        onToggleDetails,
+        onNavigate,
+        onLogout,
+        settingsTestId,
+        logoutTestId,
+        detailsToggleTestId,
+    }: Props = $props();
+
+    const linkClass =
+        "flex items-center gap-2 rounded-lg px-2 py-2 text-caption text-text transition-colors hover:bg-neutral";
+</script>
+
+<!-- Primary -->
+<div class="space-y-0.5 px-2">
+    <a href="/settings" onclick={onNavigate} data-testid={settingsTestId} class={linkClass}>
+        <Settings class="size-4 shrink-0" aria-hidden="true" />
+        <span>個人設定</span>
+    </a>
+    {#if orgSettingsHref}
+        <a href={orgSettingsHref} onclick={onNavigate} class={linkClass}>
+            <Settings class="size-4 shrink-0" aria-hidden="true" />
+            <span>組織設定</span>
+        </a>
+    {/if}
+    {#if cliHref}
+        <a href={cliHref} onclick={onNavigate} class={linkClass}>
+            <Terminal class="size-4 shrink-0" aria-hidden="true" />
+            <span>CLI セットアップ</span>
+        </a>
+    {/if}
+    {#if mcpHref}
+        <a href={mcpHref} onclick={onNavigate} class={linkClass}>
+            <Plug class="size-4 shrink-0" aria-hidden="true" />
+            <span>MCP セットアップ</span>
+        </a>
+    {/if}
+</div>
+
+<div class="my-2 border-t border-border"></div>
+
+<!-- Details (collapsible): 法務リンク (public, 認証済み user menu 内で常時表示) -->
+<div class="px-2">
+    <button
+        type="button"
+        onclick={onToggleDetails}
+        aria-expanded={detailsOpen}
+        data-testid={detailsToggleTestId}
+        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-text-secondary transition-colors hover:bg-neutral"
+    >
+        <ChevronRight
+            class="size-3.5 shrink-0 text-text-secondary transition-transform {detailsOpen ? 'rotate-90' : ''}"
+            aria-hidden="true"
+        />
+        <span>詳細</span>
+    </button>
+    {#if detailsOpen}
+        <div class="ml-4 space-y-0.5">
+            <a href="/terms" onclick={onNavigate} class={linkClass}>
+                <FileText class="size-4 shrink-0" aria-hidden="true" />
+                <span>利用規約</span>
+            </a>
+            <a href="/privacy" onclick={onNavigate} class={linkClass}>
+                <Shield class="size-4 shrink-0" aria-hidden="true" />
+                <span>プライバシーポリシー</span>
+            </a>
+            <a href="/commerce-disclosure" onclick={onNavigate} class={linkClass}>
+                <Scale class="size-4 shrink-0" aria-hidden="true" />
+                <span>特定商取引法に基づく表記</span>
+            </a>
+        </div>
+    {/if}
+</div>
+
+<div class="my-2 border-t border-border"></div>
+
+<!-- Logout -->
+<div class="px-2">
+    <button
+        type="button"
+        onclick={onLogout}
+        data-testid={logoutTestId}
+        class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-caption text-danger transition-colors hover:bg-danger/10"
+    >
+        <LogOut class="size-4 shrink-0" aria-hidden="true" />
+        <span>ログアウト</span>
+    </button>
+</div>
diff --git a/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php b/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php
index b11b6fa..c7e4296 100644
--- a/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php
+++ b/tests/Feature/Organizations/OrganizationNavSharedPropsTest.php
@@ -8,14 +8,27 @@
 use Inertia\Testing\AssertableInertia as Assert;
 
 /*
+ * sidebar visibility contract:
  * HandleInertiaRequests の共有 prop currentOrganization に slug + ナビ表示用の
  * 最小権限フラグ (canManageMembers / canManageApiKeys) が role 別に載ること、
  * および cross-org 分離 (別組織で付与された権限が現在組織へ漏れない) を固定する。
+ * 左サイドバー (templates/AppLayout) の org 導線可視条件はこの shared prop に依存するため、
+ * 本テストが UI 可視条件の回帰を検知する契約テストを兼ねる (将来の shape 破壊を止める)。
  *
  * 権限は OrganizationPolicy (organizationRole = laratrust_team_id 明示) を唯一の真実源とし、
  * defense-in-depth の isMemberOf フォールバックで dangling current を秘匿する。
  */
 
+test('未認証: currentOrganization / auth.user とも null を共有する (sidebar visibility contract)', function (): void {
+    // ゲスト到達 Inertia ページ (Fortify loginView = Auth/Login) で shared prop の未認証 shape を固定。
+    // サイドバーはこの null を見て nav / メニュー / ベルを一切描画しない。
+    $this->get('/login')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('currentOrganization', null)
+            ->where('auth.user', null));
+});
+
 test('owner: slug + 両権限フラグ true を共有する', function (): void {
     [$organization, $owner] = createOrganizationWithOwner('オーナー組織');
 
diff --git a/tests/js/components/features/organizations/OrganizationSwitcher.test.ts b/tests/js/components/features/organizations/OrganizationSwitcher.test.ts
deleted file mode 100644
index 0c86000..0000000
--- a/tests/js/components/features/organizations/OrganizationSwitcher.test.ts
+++ /dev/null
@@ -1,226 +0,0 @@
-import { afterEach, describe, expect, it, vi } from "vitest";
-import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
-import { tick } from "svelte";
-import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
-import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";
-
-// router.post をモックし Link は原物を使う (AppLayout.test.ts パターン準拠)
-const { routerMock } = vi.hoisted(() => ({
-    routerMock: { post: vi.fn() },
-}));
-
-vi.mock("@inertiajs/svelte", async (importOriginal) => ({
-    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: routerMock,
-}));
-
-function currentOrg(overrides: Partial<CurrentOrganization> = {}): CurrentOrganization {
-    return {
-        id: 1,
-        name: "現在組織",
-        slug: "current-org",
-        role: "organization_owner",
-        canManageMembers: true,
-        canManageApiKeys: true,
-        ...overrides,
-    };
-}
-
-function org(id: number, name: string, isPersonal = false): OrganizationSummary {
-    return { id, name, isPersonal };
-}
-
-/** トリガーを押してパネルを開く ($effect の click-outside 登録まで待つ) */
-async function openPanel(): Promise<void> {
-    await fireEvent.click(screen.getByTestId("org-switcher-trigger"));
-    await tick();
-}
-
-afterEach(() => {
-    cleanup();
-    routerMock.post.mockReset();
-});
-
-describe("features/organizations/OrganizationSwitcher", () => {
-    it("トリガーに現在組織名を表示する", () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg({ name: "アクメ社" }), organizations: [] },
-        });
-
-        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
-    });
-
-    it("トリガー押下でパネルが開き aria-expanded が false↔true する", async () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg(), organizations: [] },
-        });
-        const trigger = screen.getByTestId("org-switcher-trigger");
-        expect(trigger).toHaveAttribute("aria-expanded", "false");
-
-        await openPanel();
-
-        expect(trigger).toHaveAttribute("aria-expanded", "true");
-        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
-    });
-
-    it("2 組織以上なら他組織の切替ボタンを描画し、押下で /organizations/{id}/switch を POST する", async () => {
-        render(OrganizationSwitcher, {
-            props: {
-                currentOrganization: currentOrg({ id: 1 }),
-                organizations: [org(1, "現在組織"), org(2, "別組織")],
-            },
-        });
-        await openPanel();
-
-        await fireEvent.click(screen.getByTestId("org-switch-2"));
-
-        expect(routerMock.post).toHaveBeenCalledTimes(1);
-        expect(routerMock.post.mock.calls[0][0]).toBe("/organizations/2/switch");
-    });
-
-    it("現在組織行は切替ボタンにならない (aria-current + ラベル、押下で POST しない)", async () => {
-        render(OrganizationSwitcher, {
-            props: {
-                currentOrganization: currentOrg({ id: 1 }),
-                organizations: [org(1, "現在組織"), org(2, "別組織")],
-            },
-        });
-        await openPanel();
-
-        const currentRow = screen.getByTestId("org-current-1");
-        expect(currentRow).toHaveAttribute("aria-current", "true");
-        expect(currentRow).toHaveTextContent("現在の組織");
-        // 現在組織は切替ボタンとして描画されない
-        expect(screen.queryByTestId("org-switch-1")).toBeNull();
-
-        await fireEvent.click(currentRow);
-        expect(routerMock.post).not.toHaveBeenCalled();
-    });
-
-    it("1 組織のみなら切替セクションを描画しない", async () => {
-        render(OrganizationSwitcher, {
-            props: {
-                currentOrganization: currentOrg({ id: 1 }),
-                organizations: [org(1, "現在組織")],
-            },
-        });
-        await openPanel();
-
-        expect(screen.queryByTestId("org-current-1")).toBeNull();
-        expect(screen.queryByTestId("org-switch-1")).toBeNull();
-        // 管理リンクは出る
-        expect(screen.getByTestId("org-link-settings")).toBeInTheDocument();
-    });
-
-    it("権限フラグでメンバー管理 / API キーリンクを出し分ける", async () => {
-        render(OrganizationSwitcher, {
-            props: {
-                currentOrganization: currentOrg({
-                    slug: "acme",
-                    canManageMembers: false,
-                    canManageApiKeys: false,
-                }),
-                organizations: [],
-            },
-        });
-        await openPanel();
-
-        expect(screen.queryByTestId("org-link-members")).toBeNull();
-        expect(screen.queryByTestId("org-link-api-keys")).toBeNull();
-        // 常時表示のリンク
-        expect(screen.getByTestId("org-link-billing")).toBeInTheDocument();
-        expect(screen.getByTestId("org-link-pricing")).toBeInTheDocument();
-        const settingsHref = screen.getByTestId("org-link-settings").getAttribute("href") ?? "";
-        expect(new URL(settingsHref, "http://localhost").pathname).toBe(
-            "/organizations/acme/settings",
-        );
-    });
-
-    it("権限フラグ true でメンバー管理 / API キーリンクを表示する", async () => {
-        render(OrganizationSwitcher, {
-            props: {
-                currentOrganization: currentOrg({
-                    slug: "acme",
-                    canManageMembers: true,
-                    canManageApiKeys: true,
-                }),
-                organizations: [],
-            },
-        });
-        await openPanel();
-
-        expect(screen.getByTestId("org-link-members")).toBeInTheDocument();
-        const apiHref = screen.getByTestId("org-link-api-keys").getAttribute("href") ?? "";
-        expect(new URL(apiHref, "http://localhost").pathname).toBe("/organizations/acme/api-keys");
-    });
-
-    it("currentOrganization=null なら組織を作成のみ表示し切替/管理リンクは出さない", async () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: null, organizations: [] },
-        });
-        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("組織を選択");
-
-        await openPanel();
-
-        const createHref = screen.getByTestId("org-link-create").getAttribute("href") ?? "";
-        expect(new URL(createHref, "http://localhost").pathname).toBe("/organizations/create");
-        expect(screen.queryByTestId("org-link-settings")).toBeNull();
-        expect(screen.queryByTestId("org-link-billing")).toBeNull();
-    });
-
-    it("Escape でパネルを閉じ、トリガーへ focus を復帰する", async () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg(), organizations: [] },
-        });
-        await openPanel();
-        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
-
-        // 実装は open 中のみ document に keydown を張るため、発火対象も document に合わせる
-        await fireEvent.keyDown(document, { key: "Escape" });
-
-        expect(document.getElementById("org-switcher-panel")).toBeNull();
-        // S3 a11y 要件: Escape 後はトリガーへ focus 復帰する
-        expect(screen.getByTestId("org-switcher-trigger")).toHaveFocus();
-    });
-
-    it("ルート外の pointerdown でパネルを閉じる", async () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg(), organizations: [] },
-        });
-        await openPanel();
-        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
-
-        await fireEvent.pointerDown(document.body);
-        await tick();
-
-        expect(document.getElementById("org-switcher-panel")).toBeNull();
-    });
-
-    it("focusout でルート外へ抜けたらパネルを閉じる", async () => {
-        const { container } = render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg(), organizations: [] },
-        });
-        await openPanel();
-        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
-
-        await fireEvent.focusOut(container.firstElementChild as Element, {
-            relatedTarget: document.body,
-        });
-
-        expect(document.getElementById("org-switcher-panel")).toBeNull();
-    });
-
-    it("トリガーは disabled 属性を持たず、パネルは aria-labelledby でトリガーに紐づく (a11y)", async () => {
-        render(OrganizationSwitcher, {
-            props: { currentOrganization: currentOrg(), organizations: [] },
-        });
-        expect(screen.getByTestId("org-switcher-trigger")).not.toBeDisabled();
-
-        await openPanel();
-
-        expect(document.getElementById("org-switcher-panel")).toHaveAttribute(
-            "aria-labelledby",
-            "org-switcher-trigger",
-        );
-    });
-});
diff --git a/tests/js/components/templates/AppLayout.test.ts b/tests/js/components/templates/AppLayout.test.ts
index 5cd1fab..2eddeb4 100644
--- a/tests/js/components/templates/AppLayout.test.ts
+++ b/tests/js/components/templates/AppLayout.test.ts
@@ -1,9 +1,9 @@
 import { afterEach, describe, expect, it, vi } from "vitest";
-import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import { cleanup, fireEvent, render, screen, within } from "@testing-library/svelte";
 import { createRawSnippet } from "svelte";
 import { page } from "@inertiajs/svelte";
 import AppLayout from "@/components/templates/AppLayout.svelte";
-import type { AuthUser } from "@/lib/shared-props";
+import type { AuthUser, CurrentOrganization } from "@/lib/shared-props";
 
 // router をモックし page state は実物を使う (テスト毎に props を差し替える)
 const { routerMock } = vi.hoisted(() => ({
@@ -16,9 +16,10 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
 }));
 
 /*
- * AppLayout の常設アカウントナビ (F-08: ナビ統一) の単一の真実。
+ * AppLayout (左サイドバー型) のナビ表示・認可連動・主要インタラクションの単一の真実。
  * 全 AppLayout 利用ページのナビ表示はこの template テストで代表する
- * (ページ個別のナビテストは追加しない)。
+ * (ページ個別のナビテストは追加しない)。sidebar visibility contract のサーバ側 shape は
+ * tests/Feature/SidebarVisibilityContractTest.php が固定する。
  */
 
 const children = createRawSnippet(() => ({
@@ -35,119 +36,244 @@ function authUser(): AuthUser {
     };
 }
 
+function org(overrides: Partial<CurrentOrganization> = {}): CurrentOrganization {
+    return {
+        id: 1,
+        name: "アクメ社",
+        slug: "acme",
+        role: "organization_member",
+        canManageMembers: false,
+        canManageApiKeys: false,
+        ...overrides,
+    };
+}
+
 function setPageProps(props: Record<string, unknown>): void {
     page.props = props as typeof page.props;
+    page.url = "/dashboard";
+}
+
+function renderApp(): void {
+    render(AppLayout, { props: { appName: "AI-CUE", children } });
+}
+
+/** desktop シェル (app-sidebar) にスコープした query。nav/menu は desktop/mobile 二枚に出るため。 */
+function desktop() {
+    return within(screen.getByTestId("app-sidebar"));
 }
 
 afterEach(() => {
     cleanup();
     routerMock.post.mockReset();
+    localStorage.clear();
     setPageProps({});
 });
 
 describe("templates/AppLayout", () => {
-    it("ログイン中は設定リンク (/settings) とログアウトボタン・通知ベルを常設する", () => {
+    it("ログイン時: 通知ベル (desktop) を単一描画し、page-content を描画する", () => {
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
+            currentOrganization: org(),
         });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
 
-        // Inertia Link は href を絶対 URL に正規化するため pathname で比較する
-        const settingsHref = screen.getByTestId("nav-settings").getAttribute("href") ?? "";
-        expect(new URL(settingsHref, "http://localhost").pathname).toBe("/settings");
-        expect(screen.getByTestId("nav-logout")).toBeInTheDocument();
-        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
+        // desktop notification-bell は 1 個 (mobile は notification-bell-mobile で別 testId)
+        expect(screen.getAllByTestId("notification-bell")).toHaveLength(1);
+        expect(screen.getByTestId("notification-bell-mobile")).toBeInTheDocument();
         expect(screen.getByTestId("page-content")).toBeInTheDocument();
     });
 
-    it("ログアウトボタン押下で POST /logout が呼ばれる", async () => {
+    it("通知ベルは描画のみで副作用 (fetch / router) を発火しない (回帰: 二重マウント安全性)", () => {
+        const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(new Response(null));
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 3 },
+            currentOrganization: org(),
+        });
+        renderApp();
+
+        expect(fetchSpy).not.toHaveBeenCalled();
+        expect(routerMock.post).not.toHaveBeenCalled();
+        fetchSpy.mockRestore();
+    });
+
+    it("ユーザーメニューを開くと個人設定リンク (/settings) とログアウトが出る", async () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: org(),
+        });
+        renderApp();
+
+        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
+
+        const settings = desktop().getByTestId("nav-settings");
+        expect(new URL(settings.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
+            "/settings",
+        );
+        expect(desktop().getByTestId("logout-button")).toBeInTheDocument();
+    });
+
+    it("ログアウトボタン押下で POST /logout が呼ばれ、ボタンは disabled でない", async () => {
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
+            currentOrganization: org(),
         });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
 
-        await fireEvent.click(screen.getByTestId("nav-logout"));
+        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
+        const logout = desktop().getByTestId("logout-button");
+        expect(logout).not.toBeDisabled();
 
+        await fireEvent.click(logout);
         expect(routerMock.post).toHaveBeenCalledTimes(1);
         expect(routerMock.post.mock.calls[0][0]).toBe("/logout");
     });
 
-    it("auth.user が null なら設定/ログアウト/ベルを描画しない (ゲスト到達ページの回帰)", () => {
+    it("ゲスト到達 (auth.user=null): nav / メニュー / ベルを描画せず page-content のみ", () => {
         setPageProps({ auth: { user: null } });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
 
-        expect(screen.queryByTestId("nav-settings")).toBeNull();
-        expect(screen.queryByTestId("nav-logout")).toBeNull();
+        expect(screen.queryByTestId("app-sidebar")).toBeNull();
+        expect(screen.queryByTestId("app-sidebar-mobile")).toBeNull();
         expect(screen.queryByTestId("notification-bell")).toBeNull();
+        expect(screen.queryByTestId("notification-bell-mobile")).toBeNull();
+        expect(screen.queryByTestId("app-user-menu-toggle")).toBeNull();
         expect(screen.getByTestId("page-content")).toBeInTheDocument();
     });
 
-    it("ログアウトボタンは disabled でない (禁止事項 8 の系)", () => {
+    it("notifications undefined でもクラッシュせず unread バッジ非表示", () => {
+        setPageProps({ auth: { user: authUser() }, currentOrganization: org() });
+        renderApp();
+
+        expect(screen.getAllByTestId("notification-bell")).toHaveLength(1);
+        expect(screen.queryByTestId("unread-badge")).toBeNull();
+    });
+
+    // --- 認可連動 (負例) ---
+
+    it("currentOrganization=null: org-scoped 導線 (プロジェクト/請求/メンバー/API キー) を出さない", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: null,
+        });
+        renderApp();
+
+        const d = desktop();
+        expect(d.getByTestId("nav-item-/dashboard")).toBeInTheDocument();
+        expect(d.getByTestId("nav-item-/settings")).toBeInTheDocument();
+        expect(d.queryByTestId("nav-item-/projects")).toBeNull();
+        expect(d.queryByTestId("nav-item-/billing")).toBeNull();
+        expect(d.queryByTestId("nav-item-/manage/users")).toBeNull();
+        expect(d.queryByTestId("nav-item-/organizations/acme/api-keys")).toBeNull();
+    });
+
+    it("メンバー (manage 権限なし): メンバー/API キー導線は出さず、プロジェクト/請求は出す", () => {
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
+            currentOrganization: org(),
         });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
 
-        expect(screen.getByTestId("nav-logout")).not.toBeDisabled();
+        const d = desktop();
+        expect(d.getByTestId("nav-item-/projects")).toBeInTheDocument();
+        expect(d.getByTestId("nav-item-/billing")).toBeInTheDocument();
+        expect(d.queryByTestId("nav-item-/manage/users")).toBeNull();
+        expect(d.queryByTestId("nav-item-/organizations/acme/api-keys")).toBeNull();
     });
 
-    it("notifications が undefined でもクラッシュせず unreadCount 0 相当で描画する", () => {
-        // partial reload で shared props の閉包が省略されるケース・テスト環境での
-        // 未定義ケースの両方をカバー (shared.notifications?.unreadCount ?? 0 の回帰固定)
-        setPageProps({ auth: { user: authUser() } });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+    it("canManageMembers=true: メンバー導線 (/manage/users) を出す", () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: org({ canManageMembers: true }),
+        });
+        renderApp();
 
-        expect(screen.getByTestId("notification-bell")).toBeInTheDocument();
-        expect(screen.queryByTestId("unread-badge")).toBeNull();
+        expect(desktop().getByTestId("nav-item-/manage/users")).toBeInTheDocument();
     });
 
-    it("ログイン中は組織スイッチャートリガーを常設描画する", () => {
+    it("canManageApiKeys=true: API キー導線 (/organizations/{slug}/api-keys) を出す", () => {
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
-            currentOrganization: {
-                id: 1,
-                name: "アクメ社",
-                slug: "acme",
-                role: "organization_owner",
-                canManageMembers: true,
-                canManageApiKeys: true,
-            },
-            organizations: [{ id: 1, name: "アクメ社", isPersonal: false }],
+            currentOrganization: org({ canManageApiKeys: true }),
         });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
 
-        expect(screen.getByTestId("org-switcher-trigger")).toBeInTheDocument();
-        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
+        expect(desktop().getByTestId("nav-item-/organizations/acme/api-keys")).toBeInTheDocument();
     });
 
-    it("組織スイッチャートリガーは shrink-0 で 375px ヘッダー折返しを維持する", () => {
+    // --- 組織切替 / drawer / Escape ---
+
+    it("組織切替: org-switch-{id} 押下で正しい id の POST /organizations/{id}/switch が 1 回", async () => {
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
-            currentOrganization: null,
-            organizations: [],
+            currentOrganization: org({ id: 1 }),
+            organizations: [
+                { id: 1, name: "アクメ社", isPersonal: false },
+                { id: 2, name: "別組織", isPersonal: false },
+            ],
+        });
+        renderApp();
+
+        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
+        await fireEvent.click(desktop().getByTestId("org-switch-2"));
+
+        expect(routerMock.post).toHaveBeenCalledTimes(1);
+        expect(routerMock.post.mock.calls[0][0]).toBe("/organizations/2/switch");
+    });
+
+    it("mobile drawer: メニューボタンで開き、閉じるボタンで閉じる", async () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: org(),
+        });
+        renderApp();
+
+        const drawer = screen.getByTestId("app-sidebar-mobile");
+        expect(drawer.className).toContain("-translate-x-full");
+
+        await fireEvent.click(screen.getByTestId("mobile-menu-button"));
+        expect(drawer.className).toContain("translate-x-0");
+
+        await fireEvent.click(screen.getByTestId("mobile-menu-close"));
+        expect(drawer.className).toContain("-translate-x-full");
+    });
+
+    it("Escape で開いている user menu が閉じる", async () => {
+        setPageProps({
+            auth: { user: authUser() },
+            notifications: { unreadCount: 0 },
+            currentOrganization: org(),
         });
-        render(AppLayout, { props: { appName: "AI-CUE", children } });
+        renderApp();
+
+        await fireEvent.click(desktop().getByTestId("app-user-menu-toggle"));
+        expect(screen.getByTestId("app-user-menu")).toBeInTheDocument();
 
-        expect(screen.getByTestId("org-switcher-trigger")).toHaveClass("shrink-0");
+        await fireEvent.keyDown(document, { key: "Escape" });
+        expect(screen.queryByTestId("app-user-menu")).toBeNull();
     });
 
-    it("ページ固有の headerActions snippet と常設ナビが共存する (常設ナビは各 1 個)", () => {
+    it("サイドバー折りたたみ状態を localStorage から復元する", () => {
+        localStorage.setItem("aicue:layout:sidebarOpen", "false");
         setPageProps({
             auth: { user: authUser() },
             notifications: { unreadCount: 0 },
+            currentOrganization: org(),
         });
-        const headerActions = createRawSnippet(() => ({
-            render: () => `<button type="button" data-testid="page-action">ページ操作</button>`,
-        }));
-        render(AppLayout, { props: { appName: "AI-CUE", children, headerActions } });
-
-        expect(screen.getByTestId("page-action")).toBeInTheDocument();
-        expect(screen.getAllByTestId("nav-settings")).toHaveLength(1);
-        expect(screen.getAllByTestId("nav-logout")).toHaveLength(1);
+        renderApp();
+
+        // 折りたたみ時は desktop の notification-bell (sidebarOpen 時のみ描画) が出ない
+        expect(screen.queryByTestId("notification-bell")).toBeNull();
+        // mobile 側は viewport 非依存で常時ある
+        expect(screen.getByTestId("notification-bell-mobile")).toBeInTheDocument();
     });
 });

```

---

## design system 参照（DESIGN token）

tokens.css（抜粋）:
```
--color-primary #2563eb / --color-primary-hover #1d4ed8 / --color-primary-soft rgba(37,99,235,.12)
--color-neutral #f4f4f5 (page bg) / --color-surface #fff (card) / --color-border #e4e4e7 / --color-border-strong #a1a1aa
--color-text #18181b / --color-text-secondary #52525b / --color-success/#warning/#danger
--radius-sm 4px / --radius-md 6px / --radius-lg 8px
@utility text-h2 / text-h3 / text-body / text-caption
```
atomic 構成: `resources/js/components/{atoms,molecules,organisms,templates,features}`。
本件の新規 helper は `templates/_helpers/`（SidebarNavItems / SidebarUserMenu）。アイコンは `@lucide/svelte` のみ。
ds-purity / atomic-import-graph / svg-inline-allowlist テストは全て通過済み。

---

## テスト結果

- TypeScript typecheck: OK / ESLint: OK / Pint(PHP整形): passed
- アーキテクチャテスト (ds-purity / atomic-import-graph / svg-inline-allowlist): 26/26 passed
- JS 全体 (vitest): 80 files / 774 tests passed（AppLayout 新テスト 14 件含む）
- Feature: OrganizationNavSharedPropsTest (sidebar visibility contract) 7/7 passed (90 assertions)
- PHP 全体 (Pest --parallel): 1797 tests, 1795 passed, 2 skipped, 0 failed (7534 assertions)
- PHPStan level 10: No errors / 本番ビルド (pnpm build): 成功
