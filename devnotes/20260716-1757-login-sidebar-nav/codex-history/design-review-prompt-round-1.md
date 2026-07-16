## アプリの使命（North Star）

AI-CUE は、現場の作業手順書(SOP)を起点に AI が撮るべきカットを設計した動画シナリオを生成し、
スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル
動画を作れるようにする（思考ゼロ・編集ゼロ）。

## 禁止事項（核）
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時エラー表示）
- 後方互換の並走を残さない（旧実装は同一 PR で消す）

## 思考原則・ツール使用制限
まず仮説を立てろ。先人の知恵（Laravel/Svelte 既存解）を使え。機能の名前に立ち返れ。
コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中（ファイル読み込みは可）。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の
詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5(runes) + Inertia.js + TypeScript / PHPStan level 10 /
Pest / DTO + JsonResource / Laratrust RBAC（Organization → Team → Project 階層）。

【レビュー観点】
1. コードの正確性（ロジック、エッジケース、null 安全）
2. 既存コードとの整合性（命名、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（Pest, RefreshDatabase グローバル）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、テストが変更対象に含まれるか）
9. セキュリティ（認可チェック、AGENTS.md のセキュリティ不変条件、サイドバーから 403 導線を作らない）
10. DESIGN.md 準拠（design token 経由か、hex 直書きを増やさないか）
11. Atomic Design 準拠（atoms/molecules/organisms/templates の責務・単方向 import、helper 配置、
    Lucide 前提で SVG 直書き新設なし）

【本設計の背景】
- 参照アプリ aigenba（同 template・同 DS）の左サイドバー AppLayout を AI-CUE へ移植し、AI-CUE 独自の
  上部ヘッダー型 AppLayout と独自 OrganizationSwitcher を退役させる。ユーザー方針は「UI は参照側に
  合わせる、独自版は削除」。
- 概念設計は Codex Round 2 で APPROVED 済み。
- サーバ側の実 authorize を確認済み: 組織設定/CLI/MCP/請求はいずれも `Gate::view`（＝メンバー全員）、
  メンバー管理=`manageMembers`、API キー=`manageApiKeys`。よって新規 shared prop は不要で、
  既存 `canManageMembers`/`canManageApiKeys` と `currentOrganization != null` でゲートする。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
- **state**（aigenba 準拠）: `sidebarOpen`（localStorage 永続, 既定 true）, `mobileMenuOpen`,
  `userMenuOpen`, `detailsOpen`, `orgSearchQuery`（組織 > 5 件時の検索）
- **org データ**: `currentOrganization`（shared prop, slug 保有）を現在組織として直接使用（aigenba の
  `currentOrganization()` 導出関数・cookie fallback は不要）。切替候補は `organizations`
  （id/name/isPersonal, slug なし）。org 切替は `router.post('/organizations/{id}/switch')`（**id** 指定。
  既存 OrganizationSwitcher と同一。aigenba の slug 指定とは異なる AI-CUE 仕様）
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
- **isActive**: `page.url` の pathname と href の一致 or `startsWith(href + '/')`
- **NotificationBell**: サイドバー内（desktop はロゴ隣 or nav 上部, mobile top bar）に配置。
  `unreadCount = shared.notifications?.unreadCount ?? 0`。通知は**この 1 箇所のみ**（nav 項目にしない）
- **下部メニュー**（aigenba の user/org menu を移植）: トリガー（現在組織名 + ユーザー名）→ popup に
  (1) 組織切替リスト（`organizations`, 5 件超で検索, id で switch, 現在組織に Check）
  (2) `SidebarUserMenu`（個人設定/組織設定/CLI/MCP/法務/ログアウト）
- **main**: `lg:[margin-left:var(--app-sidebar-w)]`（sidebarOpen で 256/64px）内に
  `EmailVerificationBanner`（未認証時）+ `{@render children()}`
- **logout**: `router.post('/logout')`（二重送信ガードは既存 AI-CUE 実装の loggingOut を踏襲）
- **未認証（ゲスト到達）**: `shared.auth?.user == null` のとき nav / 下部メニュー / ベルを描画せず、
  `{@render children()}` のみ（現行 AI-CUE の showAccountNav ガードを踏襲）

### data-testid（S6 テストが参照）
`app-sidebar`（desktop aside）, `app-user-menu-toggle`, `app-user-menu`, `notification-bell`
（NotificationBell 既定）, `nav-settings`（設定 nav link）, `nav-logout` / `logout-button`,
`org-switch-{id}` / `org-current-{id}`, `page-content`（children）。testid は極力現行と aigenba を踏襲。

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
aigenba 版をほぼ verbatim 移植（依存は `@lucide/svelte` の型のみ）。props: `items`（{href,label,icon}[]）,
`isActive`, `showLabel?`, `onNavigate?`。active 時 `bg-primary text-white`、非 active `text-text hover:bg-neutral`。
DS token は AI-CUE と共通のため無改変で適合。

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
- 組織設定: `/organizations/{slug}/settings`（`settingsHref` prop, `currentOrganization != null` 時に渡す）
- CLI/MCP: `/organizations/{slug}/onboarding/cli|mcp`（`cliHref`/`mcpHref` prop,
  `currentOrganization != null` 時に渡す — **メンバー全員可のため canManageApiKeys ゲートにしない**）
- トップページ `/`（aigenba 同様）
- 詳細（折りたたみ）: 利用規約 `/terms`・プライバシー `/privacy`・特定商取引法 `/commerce-disclosure`
  （AI-CUE に存在）。**ヘルプ `/help`・運営会社外部リンクは削除**（AI-CUE に無い）
- ログアウト: `onLogout` prop（AppLayout が `router.post('/logout')`）

props: `settingsHref`, `cliHref`, `mcpHref`, `detailsOpen`, `onToggleDetails`, `onNavigate`,
`onLogout`, `profileTestId`, `logoutTestId?`, `detailsToggleTestId?`（aigenba と同じ形）。

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
- テスト: `OrganizationSwitcher` 専用テストの有無を確認し、あれば削除または S6 へ統合。

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
- ログイン時: `nav-settings`（href pathname `/settings`）, `nav-logout`（or `logout-button`）,
  `notification-bell`, `page-content` を描画（現行踏襲）
- ログアウトボタン押下 → `router.post('/logout')` 1 回（現行踏襲）
- ログアウトボタンは `disabled` でない（禁止事項 8 の系, 現行踏襲）
- `auth.user == null`（ゲスト到達）→ nav / 設定 / ログアウト / ベルを描画しない, `page-content` は描画
- `notifications` undefined でもクラッシュせず unread バッジ非表示
- 下部メニュートグル（`app-user-menu-toggle`）で組織切替トリガー/`SidebarUserMenu` を開く。
  `currentOrganization` 設定時に現在組織名を表示、`organizations` 2 件以上で `org-switch-{id}` を描画
- desktop サイドバー折りたたみトグルで幅クラスが 256↔64 相当に切替（localStorage 反映）
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

### 内容
- nav は既存 `currentOrganization.canManageMembers` / `canManageApiKeys` に依存する。これらの
  shared prop 出し分けについて「権限ありで true」「権限なしで false」「未認証で currentOrganization=null」
  「別組織の権限が current org に漏れない（cross-org）」を検証するテストの**存在を確認**し、
  不足があれば追加する（Codex R2 Warning 対応）。既存の cross-org 固定テストがあれば流用。
- 新規 prop は追加しないため、原則は既存テストの確認に留まる（不足時のみ最小追加）。

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

## 関連する現行コード

### AI-CUE 現行 AppLayout.svelte（置換対象）

```
<script lang="ts">
    import type { Snippet } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
    import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
    import NotificationBell from "@/components/molecules/NotificationBell.svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { consumeFlash } from "@/lib/stores/flash-to-toast";

    /**
     * 認証済み画面用レイアウト (最小骨格)。
     * 組織スイッチャー/組織メニューを常設 (組織切替・組織設定/請求/招待/API キー導線)。
     * サイドバー/Team/Project ナビは後続 Phase。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     * ログイン中は通知ベル・設定・ログアウトを全ページ常設する (F-08: ナビ統一)。
     * ログアウト POST はこのレイアウトの単一ハンドラに一本化する (ページ側に実装を残さない)。
     */
    interface Props {
        appName: string;
        children: Snippet;
        /** ヘッダー右側のページ固有の追加アクション (常設ナビの左に並ぶ) */
        headerActions?: Snippet;
    }

    let { appName, children, headerActions }: Props = $props();

    // shared props は backend (HandleInertiaRequests) が真実。lib/shared-props.ts の型で読む
    const shared = $derived(page.props as unknown as SharedProps);

    $effect(() => {
        consumeFlash(shared.flash);
    });

    // メール未認証のソフトゲート案内 (organizations.store / invitations.store は
    // verified.or-back で back + error flash になるため、常設バナーで導線を先出しする)。
    const showEmailBanner = $derived(
        shared.auth?.user != null && shared.auth.user.emailVerified === false,
    );

    // ログイン時のみベル + アカウントナビ (設定/ログアウト) を常設する
    // (invitations.accept 等、ゲスト到達がある AppLayout ページでは出さない)
    const showAccountNav = $derived(shared.auth?.user != null);

    let loggingOut = $state(false);

    // ログアウト (二重送信ガード。失敗時も onFinish で解除され再試行できる)
    function logout(): void {
        if (loggingOut) return;
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col bg-neutral text-text">
    <header class="border-b border-border bg-surface">
        <!-- 375px 方針: ロゴは shrink-0、右側アクション群は flex-wrap で行内折り返し (2 段化) -->
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-8 py-3">
            <a href="/dashboard" class="shrink-0 text-h3 text-primary">{appName}</a>
            <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
                {#if headerActions}
                    {@render headerActions()}
                {/if}
                {#if showAccountNav}
                    <OrganizationSwitcher
                        currentOrganization={shared.currentOrganization ?? null}
                        organizations={shared.organizations ?? []}
                    />
                    <NotificationBell unreadCount={shared.notifications?.unreadCount ?? 0} />
                    <TextLink href="/settings" testId="nav-settings">設定</TextLink>
                    <Button
                        variant="ghost"
                        size="sm"
                        onclick={logout}
                        loading={loggingOut}
                        testId="nav-logout"
                    >
                        ログアウト
                    </Button>
                {/if}
            </div>
        </div>
    </header>
    <main class="mx-auto w-full max-w-6xl flex-1 px-8 py-8">
        {#if showEmailBanner}
            <div class="mb-6">
                <EmailVerificationBanner />
            </div>
        {/if}
        {@render children()}
    </main>
</div>

```

### 参照 aigenba AppLayout.svelte（移植元・構造の正）

```
<script lang="ts">
import { router, page } from '@inertiajs/svelte';
import {
  BookOpen, History, Building2, Menu, X,
  Users, FolderKanban, UserPlus,
  ChevronUp, ChevronRight, ChevronLeft,
  Plus, House, CreditCard, KeyRound, ClipboardList, Check,
  CircleCheckBig, CircleX, TriangleAlert, Info, X as CloseIcon,
} from '@lucide/svelte';
import { onMount, onDestroy } from 'svelte';
import type { Component } from 'svelte';
import { createToastTimer } from '../../lib/toast-timer';
import { LAST_ORGANIZATION_SLUG_COOKIE, escapeCookieNameForRegExp } from '@/constants/cookies';
import { nextFlashToPresent } from '@/lib/flash/present-flash';
import type { FlashMessages } from '@/types/Flash';
import type { BillingFlashMessage } from '../../types/Billing';
import type { AuthUser } from '../../types/api';
import EmailVerificationBanner from '../features/auth/EmailVerificationBanner.svelte';
import QuotaExceededModal from '../features/billing/QuotaExceededModal.svelte';
import QuotaWarningBanner from '../features/billing/QuotaWarningBanner.svelte';
import BrandLogo from '../molecules/BrandLogo.svelte';
import SidebarNavItems from './_helpers/SidebarNavItems.svelte';
import SidebarUserMenu from './_helpers/SidebarUserMenu.svelte';

interface Props {
  children: import('svelte').Snippet;
}

// T660: is_personal 撤去
interface OrganizationItem {
  id: number;
  name: string;
  slug: string;
}

let { children }: Props = $props();

const auth = $derived(page.props.auth as { user: AuthUser | null });
const organizations = $derived(page.props.organizations as OrganizationItem[]);
const canCreateOrganization = $derived(page.props.canCreateOrganization as boolean);
const canViewCurrentBilling = $derived(page.props.canViewCurrentBilling as boolean | null);
const canManageCurrentApiKeys = $derived(page.props.canManageCurrentApiKeys as boolean | null);
const canManageCurrentScenarios = $derived(page.props.canManageCurrentScenarios as boolean | null);
const canManageCurrentMembers = $derived(page.props.canManageCurrentMembers as boolean | null);
const billingFlash = $derived((page.props.billingFlash as BillingFlashMessage | null | undefined) ?? null);
const currentPath = $derived(page.url);

const hasManagementRole = $derived(
  auth.user?.roles?.some((role: string) =>
    ['organization_owner', 'organization_administrator', 'project_tutor'].includes(role)
  ) ?? false
);

const isInOrganization = $derived(currentPath.startsWith('/organizations/'));
const organizationSlug = $derived(() => {
  if (!isInOrganization) return null;
  const match = currentPath.match(/^\/organizations\/([^\/]+)/);
  return match ? match[1] : null;
});

// /profile などの組織配下でない URL でも最後に選択した組織コンテキストを保持し、
// サイドバーのナビ・左下ボタン・組織設定リンクが空にならないようにするためのフォールバック。
// `last_organization_slug` cookie は handleOrganizationSelect / OrganizationController で
// 管理されているのでそれを再利用する。
let fallbackOrgSlug = $state<string | null>(null);

const currentOrganization = $derived(() => {
  const slug = organizationSlug() ?? fallbackOrgSlug;
  if (slug) {
    const found = organizations.find(org => org.slug === slug);
    if (found) return found;
  }
  // /profile などの組織非依存ページで、URL からも cookie からも組織が決まらない
  // ケースのフォールバック。管理ロール持ちなら 1 件目を仮 current として扱い、
  // サイドバーが空 / 学習側メニューに陥らないようにする。
  return organizations?.[0] ?? null;
});

let sidebarOpen = $state(true);
let mobileMenuOpen = $state(false);
let userMenuOpen = $state(false);
let detailsOpen = $state(false);
let orgSearchQuery = $state('');
let showToast = $state(false);
let toastMessage = $state('');
type ToastType = 'success' | 'error' | 'warning' | 'info';
let toastType = $state<ToastType>('success');
// F-5-01: toast の背景色 / icon を三項ネストでなく Record で一元管理 (canonical 4 値)。
const TOAST_BG: Record<ToastType, string> = {
  success: 'bg-success',
  error: 'bg-danger',
  warning: 'bg-warning',
  info: 'bg-primary',
};
const TOAST_ICON: Record<ToastType, Component> = {
  success: CircleCheckBig,
  error: CircleX,
  warning: TriangleAlert,
  info: Info,
};
// toast 自動消去タイマー。reactive 不要なため非 $state (= handleOrganizationSelect の Date と
// 同じ非追跡ローカル方針、svelte/prefer-svelte-reactivity 警告回避)。連続 flash で先行
// タイマーを clear してから張り直し、新 toast の早期消去を防ぐ (T786 Codex Suggestion)。
const toastTimer = createToastTimer();

function showFlashToast(message: string, type: ToastType): void {
  toastMessage = message;
  toastType = type;
  showToast = true;
  toastTimer.schedule(() => {
    showToast = false;
  });
}

function dismissToast(): void {
  toastTimer.clear();
  showToast = false;
}

// F-6-01: flash → toast 提示を Inertia navigation 完了イベント駆動にし、
// $effect の参照同一性依存をやめる。dedup key (toastId 優先) は本変数に閉じる。
// success イベント引数型はリポジトリ既存パターン (inertia-last-visit-tracker.ts) で導出。
type SuccessEvent = Parameters<Parameters<typeof router.on<'success'>>[1]>[0];
let lastPresentedFlashKey: string | null = null;

function presentFlash(flash: FlashMessages | undefined): void {
  const next = nextFlashToPresent(flash, lastPresentedFlashKey);
  if (next === null) return;
  lastPresentedFlashKey = next.key;
  showFlashToast(next.message, next.type);
}

let offSuccess: (() => void) | null = null;

onDestroy(() => {
  offSuccess?.();
  toastTimer.clear();
});

onMount(() => {
  const saved = localStorage.getItem('sidebarOpen');
  if (saved !== null) {
    sidebarOpen = saved === 'true';
  }

  const cookieRegExp = new RegExp(
    `(?:^|;\\s*)${escapeCookieNameForRegExp(LAST_ORGANIZATION_SLUG_COOKIE)}=([^;]+)`,
  );
  const cookieMatch = document.cookie.match(cookieRegExp);
  if (cookieMatch) {
    fallbackOrgSlug = decodeURIComponent(cookieMatch[1]);
  }

  // 初回着地 (直リンク / SSR) の flash をグローバル page から 1 回提示。
  presentFlash(page.props.flash as FlashMessages | undefined);
  // 以降の navigation (新規 / replay / partial reload) は当該レスポンス由来 page から提示。
  offSuccess = router.on('success', (event: SuccessEvent) => {
    presentFlash(event.detail.page.props.flash as FlashMessages | undefined);
  });
});

const filteredOrganizations = $derived(() => {
  if (!orgSearchQuery.trim()) {
    return organizations;
  }
  const query = orgSearchQuery.toLowerCase();
  return organizations.filter(org => org.name.toLowerCase().includes(query));
});

function toggleSidebar() {
  sidebarOpen = !sidebarOpen;
  localStorage.setItem('sidebarOpen', String(sidebarOpen));
  if (!sidebarOpen) {
    closeUserMenu();
  }
}

function toggleMobileMenu() {
  mobileMenuOpen = !mobileMenuOpen;
}

function closeMobileMenu() {
  mobileMenuOpen = false;
  closeUserMenu();
}

function toggleUserMenu() {
  userMenuOpen = !userMenuOpen;
  if (!userMenuOpen) {
    detailsOpen = false;
    orgSearchQuery = '';
  }
}

function closeUserMenu() {
  userMenuOpen = false;
  detailsOpen = false;
  orgSearchQuery = '';
}

function toggleDetails() {
  detailsOpen = !detailsOpen;
}

const handleLogout = () => {
  router.post('/logout');
};

const isActive = (path: string) => {
  const org = currentOrganization();

  if (org && path === `/organizations/${org.slug}`) {
    return currentPath === path;
  }

  if (org && path === `/organizations/${org.slug}/projects`) {
    return currentPath === path ||
           currentPath.match(/^\/organizations\/[^\/]+\/teams\/\d+\/projects/);
  }

  if (org && path === `/organizations/${org.slug}/teams`) {
    return (currentPath === path || currentPath.match(/^\/organizations\/[^\/]+\/teams\/\d+$/));
  }

  return currentPath === path || currentPath.startsWith(path + '/');
};

const handleOrganizationSelect = (slug: string) => {
  // function-local Date for Cookie expiration (not reactive, not tracked by Svelte). T368 設計判断。
  // eslint-disable-next-line svelte/prefer-svelte-reactivity
  const expires = new Date();
  expires.setDate(expires.getDate() + 30);
  document.cookie = `${LAST_ORGANIZATION_SLUG_COOKIE}=${slug}; path=/; expires=${expires.toUTCString()}`;
  fallbackOrgSlug = slug;

  router.visit(`/organizations/${slug}`);
  closeUserMenu();
  closeMobileMenu();
};

const handleCreateOrganization = () => {
  closeUserMenu();
  closeMobileMenu();
  router.visit('/organizations/create');
};

// グローバルメニュー (プロフィール/設定はポップアップへ移動)
const globalNavItems = [
  { href: '/courses', label: 'コース', icon: BookOpen },
  { href: '/mypage/history', label: '履歴', icon: History },
];

// 組織メニュー: 概要 / メンバー / チーム / プロジェクト / シナリオ / API キー / 請求
// 組織設定はポップアップに移動 (頻度が低くサイドバーから外す方針)。
const organizationNavItems = $derived(() => {
  const org = currentOrganization();
  if (!org) return [];

  const items = [
    { href: `/organizations/${org.slug}`, label: '概要', icon: House },
  ];

  // メンバー (ロスター) は manage_organization_members (Owner/Admin) のみ。
  // project_tutor は hasManagementRole で組織メニュー自体は見えるが、このリンクは出さない
  // (server 側 403 と一致させる)。表示順は従来どおり概要の直後を維持する。
  if (canManageCurrentMembers === true) {
    items.push({ href: `/organizations/${org.slug}/members`, label: 'メンバー', icon: UserPlus });
  }

  items.push(
    { href: `/organizations/${org.slug}/teams`, label: 'チーム', icon: Users },
    { href: `/organizations/${org.slug}/projects`, label: 'プロジェクト', icon: FolderKanban },
  );

  if (canManageCurrentScenarios === true) {
    items.push({ href: `/organizations/${org.slug}/scenarios`, label: 'シナリオ', icon: ClipboardList });
  }

  if (canManageCurrentApiKeys === true) {
    items.push({ href: `/organizations/${org.slug}/api-keys`, label: 'API キー', icon: KeyRound });
  }

  if (canViewCurrentBilling === true) {
    items.push({ href: `/organizations/${org.slug}/billing`, label: '請求', icon: CreditCard });
  }

  return items;
});

const navItems = $derived(hasManagementRole && currentOrganization() ? organizationNavItems() : globalNavItems);

const onboardingOrgSlug = $derived(() => {
  const current = currentOrganization();
  if (current) return current.slug;
  return organizations?.[0]?.slug ?? null;
});

// CLI / MCP セットアップは OnboardingController が `ApiKeyPolicy::viewAny` で
// 認可されており、Trainee や `manage_organization_api_keys` 非保有メンバーは
// 踏むと 403 になる。サイドバーから到達できない原則に揃えるため、
// `canManageCurrentApiKeys` が true のときだけリンクを描画する。
const cliHref = $derived(() => {
  if (canManageCurrentApiKeys !== true) return null;
  const slug = onboardingOrgSlug();
  return slug ? `/organizations/${slug}/onboarding/cli` : null;
});

const mcpHref = $derived(() => {
  if (canManageCurrentApiKeys !== true) return null;
  const slug = onboardingOrgSlug();
  return slug ? `/organizations/${slug}/onboarding/mcp` : null;
});

// 組織設定 href (= 管理ロール + 組織がある時のみ。 SidebarUserMenu helper に値で渡す)
const settingsHref = $derived(() => {
  const org = currentOrganization();
  return hasManagementRole && org ? `/organizations/${org.slug}/settings` : null;
});

const userName = $derived(auth.user?.name ?? 'ユーザー');
const orgName = $derived(currentOrganization()?.name ?? '組織未選択');
</script>

<div class="min-h-screen bg-neutral">
  <!-- Mobile Top Bar -->
  <div class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-border bg-surface px-4 lg:hidden">
    <button
      onclick={toggleMobileMenu}
      class="flex h-10 w-10 items-center justify-center rounded-lg text-text-secondary transition-colors hover:bg-primary-soft hover:text-primary"
      type="button"
      aria-label="メニューを開く"
    >
      <Menu class="h-6 w-6" />
    </button>
    <a href="/dashboard" class="flex items-center gap-2">
      <BrandLogo size="sm" />
    </a>
  </div>

  <div class="flex">
    <!-- Desktop Sidebar -->
    <aside
      class="fixed top-0 left-0 z-40 hidden h-screen border-r border-border bg-surface transition-all duration-300 lg:flex lg:flex-col"
      style="width: {sidebarOpen ? '256px' : '64px'}"
      data-testid="app-sidebar"
    >
      <!-- Logo / Brand -->
      <div class="border-b border-border">
        <a
          href="/dashboard"
          class="group flex items-center gap-3 px-3 py-4 {sidebarOpen ? '' : 'justify-center'}"
        >
          <BrandLogo size="md" withName={sidebarOpen} class="gap-3" />
        </a>
      </div>

      <!-- Collapse Toggle: サイドバー右端の罫線に半分かぶる円形ボタン。
           Y 軸中心は logo ブロックと nav の間の border 罫線に合わせる。
           logo block 高さ = py-4 (16+16) + h-10 (40) + border-b (1) = 73px → top-[73px] -->
      <button
        type="button"
        onclick={toggleSidebar}
        aria-label={sidebarOpen ? 'サイドバーを折りたたむ' : 'サイドバーを開く'}
        class="absolute top-[73px] -right-3 z-50 hidden h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-surface text-text-secondary transition-colors hover:bg-neutral hover:text-text lg:flex"
      >
        {#if sidebarOpen}
          <ChevronLeft class="h-3.5 w-3.5" />
        {:else}
          <ChevronRight class="h-3.5 w-3.5" />
        {/if}
      </button>

      <!-- Nav -->
      <SidebarNavItems items={navItems} {isActive} showLabel={sidebarOpen} />

      <!-- User / Organization Menu (bottom) -->
      <div class="relative border-t border-border">
        {#if userMenuOpen}
          <button
            type="button"
            class="fixed inset-0 z-30 cursor-default"
            aria-label="メニューを閉じる"
            onclick={closeUserMenu}
          ></button>
        {/if}

        <button
          type="button"
          onclick={toggleUserMenu}
          data-testid="app-user-menu-toggle"
          class="relative z-40 flex w-full items-center gap-3 px-3 py-3 transition-colors hover:bg-neutral {sidebarOpen ? '' : 'justify-center'}"
          aria-haspopup="menu"
          aria-expanded={userMenuOpen}
          title={`${orgName} / ${userName}`}
        >
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-neutral">
            <Building2 class="h-5 w-5" />
          </div>
          {#if sidebarOpen}
            <div class="min-w-0 flex-1 text-left">
              <p class="truncate text-caption font-medium text-text">{orgName}</p>
              <p class="truncate text-caption text-text-secondary">{userName}</p>
            </div>
            <ChevronUp
              class="h-4 w-4 shrink-0 text-text-secondary transition-transform {userMenuOpen ? 'rotate-180' : ''}"
            />
          {/if}
        </button>

        {#if userMenuOpen}
          <div
            class="absolute bottom-full left-2 z-40 mb-2 max-h-[70vh] w-64 overflow-y-auto rounded-lg border border-border bg-surface py-2"
            data-testid="app-user-menu"
            role="menu"
          >
            <!-- Organization Switcher -->
            <div class="px-2">
              <p class="mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase">組織</p>
              {#if organizations.length > 5}
                <div class="mb-1 px-1">
                  <input
                    type="text"
                    bind:value={orgSearchQuery}
                    placeholder="組織を検索…"
                    class="w-full rounded-lg border border-border-strong px-3 py-1.5 text-caption focus:border-transparent focus:ring-2 focus:ring-primary"
                    onclick={(e) => e.stopPropagation()}
                  />
                </div>
              {/if}
              <div class="max-h-48 space-y-0.5 overflow-y-auto">
                {#if filteredOrganizations().length === 0}
                  <div class="px-2 py-2 text-center text-caption text-text-secondary">該当する組織が見つかりません</div>
                {:else}
                  {#each filteredOrganizations() as org (org.id)}
                    <button
                      type="button"
                      onclick={() => handleOrganizationSelect(org.slug)}
                      data-testid={`organization-switch-item-${org.id}`}
                      class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left transition-colors hover:bg-neutral"
                    >
                      <Building2 class="h-4 w-4 shrink-0 text-text-secondary" />
                      <span class="flex-1 truncate text-caption text-text">{org.name}</span>
                      {#if org.slug === currentOrganization()?.slug}
                        <Check class="h-4 w-4 shrink-0 text-primary" />
                      {/if}
                    </button>
                  {/each}
                {/if}
              </div>
              {#if canCreateOrganization}
                <button
                  type="button"
                  onclick={handleCreateOrganization}
                  class="mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-primary transition-colors hover:bg-primary-soft"
                >
                  <Plus class="h-4 w-4 shrink-0" />
                  <span class="text-caption font-medium">新しい組織を作成</span>
                </button>
              {/if}
            </div>

            <div class="my-2 border-t border-border"></div>

            <SidebarUserMenu
              cliHref={cliHref()}
              mcpHref={mcpHref()}
              settingsHref={settingsHref()}
              {detailsOpen}
              onToggleDetails={toggleDetails}
              onNavigate={closeUserMenu}
              onLogout={handleLogout}
              profileTestId="nav-profile"
              logoutTestId="logout-button"
            />
          </div>
        {/if}
      </div>
    </aside>

    <!-- Mobile Sidebar Overlay -->
    {#if mobileMenuOpen}
      <div
        class="fixed inset-0 z-40 bg-text/50 lg:hidden"
        onclick={closeMobileMenu}
        onkeydown={(e) => e.key === 'Enter' && closeMobileMenu()}
        role="button"
        tabindex="0"
        aria-label="メニューを閉じる"
      ></div>
    {/if}

    <!-- Mobile Sidebar -->
    <aside
      class="fixed top-0 left-0 z-50 flex h-full w-64 flex-col border-r border-border bg-surface transition-transform duration-300 lg:hidden {mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'}"
    >
      <!-- F-3-02-1: flex 圧縮時に header 行が潰れないよう shrink-0 を付与 -->
      <div class="flex shrink-0 items-center justify-between border-b border-border px-4 py-4">
        <a href="/dashboard" onclick={closeMobileMenu} class="flex items-center gap-2">
          <BrandLogo size="sm" />
        </a>
        <button
          onclick={closeMobileMenu}
          class="rounded-lg p-2 text-text-secondary transition-colors hover:bg-neutral hover:text-text"
          type="button"
          aria-label="メニューを閉じる"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <SidebarNavItems items={navItems} {isActive} showLabel onNavigate={closeMobileMenu} />

      <!-- F-3-02-2: 低 viewport で section を縮め (min-h-0) 内部スクロール (overflow-y-auto) させ、
           末尾のログアウトに常時到達できるようにする。高 viewport では自然高で見た目不変。 -->
      <!-- Mobile: User / Organization Section (常時展開) -->
      <div class="min-h-0 overflow-y-auto border-t border-border px-2 py-3">
        <div class="mb-2 flex items-center gap-2 px-2">
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-neutral">
            <Building2 class="h-5 w-5" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-caption font-medium text-text">{orgName}</p>
            <p class="truncate text-caption text-text-secondary">{userName}</p>
          </div>
        </div>

        <p class="mt-2 mb-1 px-2 text-caption font-medium tracking-wider text-text-secondary uppercase">組織</p>
        <div class="max-h-40 space-y-0.5 overflow-y-auto">
          {#each organizations as org (org.id)}
            <button
              type="button"
              onclick={() => handleOrganizationSelect(org.slug)}
              data-testid={`organization-switch-item-mobile-${org.id}`}
              class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left transition-colors hover:bg-neutral"
            >
              <Building2 class="h-4 w-4 shrink-0 text-text-secondary" />
              <span class="flex-1 truncate text-caption text-text">{org.name}</span>
              {#if org.slug === currentOrganization()?.slug}
                <Check class="h-4 w-4 shrink-0 text-primary" />
              {/if}
            </button>
          {/each}
        </div>
        {#if canCreateOrganization}
          <button
            type="button"
            onclick={handleCreateOrganization}
            class="mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-primary transition-colors hover:bg-primary-soft"
          >
            <Plus class="h-4 w-4 shrink-0" />
            <span class="text-caption font-medium">新しい組織を作成</span>
          </button>
        {/if}

        <div class="mt-2 border-t border-border pt-2">
          <SidebarUserMenu
            cliHref={cliHref()}
            mcpHref={mcpHref()}
            settingsHref={settingsHref()}
            {detailsOpen}
            onToggleDetails={toggleDetails}
            onNavigate={closeMobileMenu}
            onLogout={handleLogout}
            profileTestId="nav-profile-mobile"
            logoutTestId="logout-button-mobile"
            detailsToggleTestId="details-toggle-mobile"
          />
        </div>
      </div>
    </aside>

    <!-- Main Content -->
    <!-- T803 F-4-05: 旧 desktop(hidden + 旧 md ブレークポイント表示)/mobile(旧 md ブレークポイント非表示) の 2 div で
         children/banner を各 2 回描画していた (= page-level testid / banner が DOM 上 2 個 →
         strict-mode 不整合 + banner onMount 副作用 2 回) のを単一ラッパーに統合。
         margin-left は lg 未満で無し (= mobile/tablet 全幅) / lg 以上で sidebar 幅 (= sidebarOpen で 256/64px)。
         T846 F-3-01: 切替 breakpoint を md(768px) → lg(1024px) に引き上げ。768-1023px 帯で
         固定サイドバー(256px) が出てコンテンツ実効幅が 512px に圧縮され管理リスト第1列が
         潰れる退行を根治 (< lg はオフキャンバス + 全幅、≥ lg のみ固定サイドバー + 左 margin)。 -->
    <main class="w-full flex-1 transition-all duration-300">
      <div
        class="lg:[margin-left:var(--app-sidebar-w)]"
        style="--app-sidebar-w: {sidebarOpen ? '256px' : '64px'}; transition: margin-left 0.3s"
      >
        {#if auth.user && !auth.user.email_verified}
          <EmailVerificationBanner />
        {/if}
        <QuotaWarningBanner flash={billingFlash} />
        {@render children()}
      </div>
    </main>

    <QuotaExceededModal flash={billingFlash} />

    {#if showToast}
      {@const ToastIcon = TOAST_ICON[toastType]}
      <div class="fixed top-4 right-4 z-50 motion-safe:animate-slide-in">
        <div
          class="flex max-w-md min-w-80 items-center gap-3 rounded-lg px-4 py-3 {TOAST_BG[toastType]} text-white"
          data-testid="app-layout-toast"
          data-toast-type={toastType}
        >
          <ToastIcon class="h-5 w-5 shrink-0" aria-hidden="true" />
          <p class="flex-1 text-caption font-medium">{toastMessage}</p>
          <button
            onclick={dismissToast}
            class="shrink-0 rounded-sm p-1 transition-colors hover:bg-white/20"
          >
            <CloseIcon class="h-4 w-4" />
          </button>
        </div>
      </div>
    {/if}
  </div>
</div>

```

### AI-CUE shared-props.ts（CurrentOrganization 型 — 変更なし想定）

```
import type { FlashPayload } from "@/lib/stores/flash-to-toast";
import type { NotificationSharedProps } from "@/types/notification";

/**
 * HandleInertiaRequests が共有する props の型 (backend が真実)。
 * ページ側は `page.props as unknown as SharedProps` で参照する。
 */

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    emailVerified: boolean;
    twoFactorEnabled: boolean;
}

export interface OrganizationSummary {
    id: number;
    name: string;
    isPersonal: boolean;
}

/** OrganizationRole enum の value と 1:1 のユニオン (型の網羅性を上げる) */
export type OrganizationRoleValue =
    | "organization_owner"
    | "organization_admin"
    | "organization_member";

export interface CurrentOrganization {
    id: number;
    name: string;
    /** organizations.settings / api-keys.index ({organization:slug}) 用 */
    slug: string;
    role: OrganizationRoleValue | null;
    /** メンバー管理 (/manage/users) 導線の表示可否 (owner/admin) */
    canManageMembers: boolean;
    /** API キー画面 (organizations.api-keys.index) 導線の表示可否 */
    canManageApiKeys: boolean;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
    notifications: NotificationSharedProps;
    /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
    title: string;
}

```

### AI-CUE 現行 OrganizationSwitcher.svelte（退役対象・org 切替は id で POST）

```
<script lang="ts">
    import { Link, router } from "@inertiajs/svelte";
    import {
        Check,
        ChevronsUpDown,
        CreditCard,
        KeyRound,
        Plus,
        Settings,
        Tag,
        Users,
    } from "@lucide/svelte";
    import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";

    /**
     * 組織スイッチャー兼組織メニュー (disclosure パターン)。
     * 現在組織を表示するトリガー + 展開パネルで「組織切替」と「組織設定/メンバー/API キー/
     * 請求/料金」への恒常導線を提供する (North Star: 組織横断運用の到達導線を回復)。
     *
     * 純粋・テスト容易にするため shared prop は親 (AppLayout) が読んで props で渡す。
     * cross-org 防御は backend (currentOrganizationProp の isMemberOf + Policy 評価) が担い、
     * ここは受け取った権限フラグでリンクを出し分けるだけ (二重判定しない)。
     *
     * a11y: disclosure セマンティクス (aria-expanded/aria-controls。role=menu は付けない)。
     * Escape / outside pointerdown / focusout の 3 経路で閉じる。禁止事項 8 に従い現在組織項目や
     * リンクを disabled にしない (現在組織は非対話行、他は遷移)。
     *
     * 設計との差分 (意図的): 詳細設計 S3 は「内部は atoms(Button) を合成」と記したが、トリガー/
     * 切替行は Button atom の variant スタイル (枠線・padding ramp) と噛み合わない menu-item 表現が
     * 必要なため native <button> を採用する。Button atom は単機能ボタン用で、id/aria-expanded/
     * aria-controls を要する disclosure トリガーには過剰。DS token は同一 (rounded-md/border-border/
     * bg-surface/text-body)。Lucide のみ・SVG 直書きなしは維持する。
     */
    interface Props {
        /** 現在の組織 (未所属/未設定時は null → 「組織を作成」フォールバック) */
        currentOrganization: CurrentOrganization | null;
        /** 所属組織一覧 (切替候補。id で switch) */
        organizations: OrganizationSummary[];
    }

    let { currentOrganization, organizations }: Props = $props();

    let open = $state(false);
    let root = $state<HTMLDivElement | null>(null);
    let trigger = $state<HTMLButtonElement | null>(null);

    const triggerLabel = $derived(currentOrganization?.name ?? "組織を選択");
    // 切替候補セクションは 2 組織以上のときのみ (1 組織なら切替不要)
    const showSwitchSection = $derived(organizations.length > 1);

    function close(): void {
        open = false;
    }

    function toggle(): void {
        open = !open;
    }

    function switchTo(id: number): void {
        // Ziggy 未導入のため文字列パス直書きが既存標準 (cf. Admin/Users.svelte)。
        router.post(`/organizations/${id}/switch`);
        close();
    }

    // focusout: Tab 等でルート外へ focus が抜けたら閉じる (focus 系は静的要素でも a11y 上許容)
    function onFocusOut(event: FocusEvent): void {
        const next = event.relatedTarget;
        if (next instanceof Node && root?.contains(next)) {
            return;
        }
        close();
    }

    // open の間だけ document へ pointerdown / keydown を張り、outside クリックと Escape で閉じる。
    // keydown を静的な wrapper div に載せると a11y_no_static_element_interactions になるため
    // document スコープに寄せる (disclosure の open 中のみ有効化)。
    $effect(() => {
        if (!open) {
            return;
        }
        function onPointerDown(event: PointerEvent): void {
            const target = event.target;
            if (target instanceof Node && root?.contains(target)) {
                return;
            }
            close();
        }
        function onKeydown(event: KeyboardEvent): void {
            if (event.key === "Escape") {
                close();
                trigger?.focus();
            }
        }
        document.addEventListener("pointerdown", onPointerDown);
        document.addEventListener("keydown", onKeydown);
        return () => {
            document.removeEventListener("pointerdown", onPointerDown);
            document.removeEventListener("keydown", onKeydown);
        };
    });
</script>

<div class="relative shrink-0" bind:this={root} onfocusout={onFocusOut}>
    <button
        type="button"
        id="org-switcher-trigger"
        class="inline-flex shrink-0 items-center gap-2 rounded-md border border-border
            bg-surface px-3 py-1.5 text-body text-text hover:bg-neutral"
        aria-expanded={open}
        aria-controls="org-switcher-panel"
        onclick={toggle}
        bind:this={trigger}
        data-testid="org-switcher-trigger"
    >
        <span class="max-w-40 truncate">{triggerLabel}</span>
        <ChevronsUpDown class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
    </button>

    {#if open}
        <div
            id="org-switcher-panel"
            class="absolute right-0 z-20 mt-1 w-64 rounded-md border border-border bg-surface py-1"
            aria-labelledby="org-switcher-trigger"
        >
            {#if currentOrganization != null}
                {#if showSwitchSection}
                    <p class="px-3 py-1 text-caption text-text-secondary">組織を切り替え</p>
                    {#each organizations as org (org.id)}
                        {#if org.id === currentOrganization.id}
                            <div
                                class="flex items-center gap-2 px-3 py-2 text-body text-text"
                                aria-current="true"
                                data-testid="org-current-{org.id}"
                            >
                                <Check
                                    class="size-4 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
                                {#if org.isPersonal}
                                    <span class="text-caption text-text-secondary">個人</span>
                                {/if}
                                <span class="text-caption text-text-secondary">現在の組織</span>
                            </div>
                        {:else}
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left
                                    text-body text-text hover:bg-neutral"
                                onclick={() => switchTo(org.id)}
                                data-testid="org-switch-{org.id}"
                            >
                                <span class="size-4 shrink-0" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
                                {#if org.isPersonal}
                                    <span class="text-caption text-text-secondary">個人</span>
                                {/if}
                            </button>
                        {/if}
                    {/each}
                    <div class="my-1 border-t border-border" role="separator"></div>
                {/if}

                <Link
                    href={`/organizations/${currentOrganization.slug}/settings`}
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-settings"
                >
                    <Settings class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    組織設定
                </Link>
                {#if currentOrganization.canManageMembers}
                    <Link
                        href="/manage/users"
                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                        onclick={close}
                        data-testid="org-link-members"
                    >
                        <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                        メンバー管理
                    </Link>
                {/if}
                {#if currentOrganization.canManageApiKeys}
                    <Link
                        href={`/organizations/${currentOrganization.slug}/api-keys`}
                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                        onclick={close}
                        data-testid="org-link-api-keys"
                    >
                        <KeyRound class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                        API キー
                    </Link>
                {/if}
                <Link
                    href="/billing"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-billing"
                >
                    <CreditCard class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    請求
                </Link>
                <Link
                    href="/pricing"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-pricing"
                >
                    <Tag class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    料金
                </Link>
            {:else}
                <Link
                    href="/organizations/create"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-create"
                >
                    <Plus class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    組織を作成
                </Link>
            {/if}
        </div>
    {/if}
</div>

```
