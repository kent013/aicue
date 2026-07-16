## 使命/禁止事項/ツール制限
AI-CUE(現場SOP起点の動画マニュアル)。テストなし完了/PHPStan widen/既存テスト削除/無駄な独自実装/後方互換並走 禁止。
コマンド実行・書き込み禁止、テキスト分析に集中(読み込み可)。
---
あなたはコードレビュアー(Laravel+Svelte)。観点: 設計一致/正確性/PHPStan/DTO-JsonResource/テスト網羅/セキュリティ/
DESIGN.md token/Atomic Design(層責務・単方向import・lucide・SVG直書き)。本件は概念/詳細 APPROVED の aigenba 外枠 parity:
S1 primitive移植(PageContainer/PageHeader/PageHeaderSection/Breadcrumb)+PageContent是正(独自maxWidth prop撤去) /
S2 AppLayout padding移譲 / S3 24ページを AppLayout>PageContainer>PageHeader(Section)>PageContent へ移行(Workflow並列)+
arch テスト2本(page-shell-structure/deprecated-imports) / S4 AdminMenuNav撤去+不要Inertia prop除去(BEロジック不変)+
Projects/Show カテゴリ導線(canManage=viewAny≡update)。差分は代表抜粋。
出力: ファイル毎 APPROVE/REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion]、全体判定、日本語。
---
## 詳細設計書
# 詳細設計: aigenba-layout-parity（認証後ページ外枠を aigenba に完全一致）

## 使命・制約（絶対遵守）
- 使命: AI-CUE は現場 SOP 起点に AI 設計の動画シナリオをスマホ(PWA)でナビ撮影し標準マニュアル動画を作る。
- 禁止事項: テストなし完了 / PHPStan widen / 既存テスト削除 / `response()->json()` 直書き / 無駄な独自実装 /
  後方互換の並走を残さない。
- コーディング: PHPStan level 10（本件は BE ロジック変更なし・不要 Inertia prop の整理あり）。フロントは Svelte 5 runes + DS token のみ（ds-purity）、
  単方向 import（atomic-import-graph）、アイコン lucide、SVG 直書きは atoms/icons 例外のみ（svg-inline-allowlist）。
  検証: `pnpm lint/typecheck/test/build` + `composer test/phpstan` + `vendor/bin/pint --test`。

## 概念設計リファレンス
`devnotes/20260716-2226-aigenba-layout-parity/conceptual-design.md`（Round 3 APPROVED）

## 施策一覧

| # | 施策 | 変更ファイル | 優先度 |
|---|---|---|---|
| S1 | primitive 移植 + PageContent 是正 | `templates/PageContainer.svelte`(新), `templates/PageContent.svelte`(改), `molecules/{PageHeaderSection,PageHeader,Breadcrumb}.svelte`(新), `types/components.ts`(新) + 各単体テスト | 高 |
| S2 | AppLayout padding 移譲 | `templates/AppLayout.svelte`, `AppLayout.test.ts` | 高 |
| S3 | 24 認証ページ外枠移行 + Architecture テスト | `resources/js/pages/**`(24), `page-content-usage.test.ts`→**`page-shell-structure.test.ts`(リネーム)**, `deprecated-imports.test.ts`(新) | 高 |
| S4 | AdminMenuNav 撤去 + カテゴリ導線 + BE 不要 prop 除去 | `Admin/{Users,Categories}.svelte`, `features/admin/AdminMenuNav.svelte`(削除), `Projects/Show.svelte`, `{Organization,Category,Admin/UserManagement}Controller.php`(不要 prop 除去), 関連 Feature/JS テスト更新 | 高 |

**実装順序（1 PR 内・混在期間なし）**: S1(primitive) → S2(AppLayout padding 撤去) → S3(全ページ移行) → S4。

### 対象 24 ページ（AppLayout 使用・固定リスト。移行漏れ防止）
Dashboard, Notifications/Index, Invitations/Accept, Settings/Index, Settings/Security, Billing/Index,
Billing/PurchaseTickets, Projects/Index, Projects/Show, Projects/Create, Projects/Edit, Manuals/Show,
Manuals/Create, Manuals/Edit, Organizations/ApiKeys/Index, Organizations/ApiKeys/Sessions,
Organizations/Create, Organizations/Settings, Organizations/Onboarding/Cli, Organizations/Onboarding/Mcp,
Admin/Users, Admin/Categories, Capture/Index, **Capture/Show（PageContent 除外の allowlist・外枠は適用）**。

### テストファースト チェックリスト（red → green）
1. S1: 各 primitive 単体テスト（PageContainer/PageContent/Breadcrumb/PageHeaderSection/PageHeader）を先に書き
   **red 確認** → primitive 実装 → **green**。
2. S3: `page-shell-structure.test.ts`（+ `deprecated-imports.test.ts`）を先に置き **red 確認**
   （24 ページ未移行 + AdminMenuNav 使用で fail）→ S3 移行 + S4 撤去 → **green**。
3. S4: Projects/Show カテゴリリンク表示テスト・BE の prop 不存在 assert・`canManage`(=viewAny)ユーザーの
   categories 到達 Feature テストを先に **red 確認** → S4 実装 → **green**。
4. 各ページ既存テストは testid/振る舞い不変で **green 維持**（回帰）。

---

## S1: primitive 移植 + PageContent 是正

### 変更後コード（aigenba verbatim を AI-CUE の `@/` import / Svelte5 `Component` 型へ）

`types/components.ts`（新）:
```ts
export interface BreadcrumbItem { label: string; href?: string; } // href 省略=現在位置
```

`molecules/Breadcrumb.svelte`（新）: lucide `ChevronRight` のみ依存。href ありはリンク、無しは現在位置 span。
`templates/PageContainer.svelte`（新）: `padding?: boolean`（既定 true）→ `<div class="w-full {padding ? 'px-4 py-8 sm:px-6 lg:px-8' : ''}">`。
`templates/PageContent.svelte`（改）: **maxWidth/testId prop を撤去**し `<div class="mx-auto max-w-7xl">{children}</div>`（prop 無し）。
`molecules/PageHeaderSection.svelte`（新）: `title/breadcrumbs/description/icon(Component)/testId/children(actions Snippet)`。
負マージン全幅バー `-mx-4 -mt-8 … sm:-mx-6 lg:-mx-8`。`showBreadcrumbs = breadcrumbs.length > 1`。actions は children Snippet。
`molecules/PageHeader.svelte`（新）: root shorthand。`title/description/icon/testId` を `PageHeaderSection` へ委譲。
（実コードは scratchpad に用意済み。aigenba と差分は `@/` import・`size-*` utility・`Component` 型のみ）

### 波及変更
- TypeScript 型: `BreadcrumbItem` を `@/types/components` に新設。icon 型は lucide 互換 `Component`（`Component<any>` にしない）。
- 既存 PageContent 利用（T070 の `<PageContent maxWidth=...>`）は S3 で全て `<PageContent>` へ書き換え（prop 撤去に伴う破壊は S3 で解消）。
- PageContent の `testId`/`data-testid="page-content"` に依存する T070 テスト（PageContent.test / page-content-usage）は本 PR で更新。

### 階層・DS
- `PageContainer`/`PageContent` = templates 層 layout primitive、`PageHeader(Section)`/`Breadcrumb` = molecules 層
  （aigenba と同配置）。`PageHeaderSection`→`Breadcrumb` は molecule→molecule（DAG, atomic-import-graph 適合）。
- DS-pure（layout/typography/border token のみ・任意色/hex なし）。

### テスト計画（テストファースト: red → green）
- `PageContainer.test`: padding 既定で `px-4 py-8 sm:px-6 lg:px-8`、`padding={false}` で padding class 無し、children 描画。
- `PageContent.test`（作り直し）: `mx-auto max-w-7xl` を持ち children 描画。**maxWidth prop を受けないことは
  `pnpm typecheck` で保証**（ランタイム単体テストでは Props 型の不受理を保証できないため、型チェック + 構造テストで担保）。
- `Breadcrumb.test`: href あり=リンク/無し=span、区切り ChevronRight（>1 件で表示）。
- `PageHeaderSection.test`: title(h1 text-h2)/icon/description/actions(children)描画、breadcrumbs は >1 件のみ Breadcrumb 表示、testId 反映。
- `PageHeader.test`: title/description/icon/testId を PageHeaderSection へ委譲（h1 + description 描画）。

### リスク
- 低（新規 primitive・純表示）。PageContent 是正の破壊は S3 で全ページ移行して解消（混在させない）。

---

## S2: AppLayout padding 移譲

### 変更箇所
- `templates/AppLayout.svelte` の `<main>` 内 content ラッパの直接 padding（`px-4 py-6 lg:px-8`）を**撤去**。
  padding は各ページの `PageContainer` が担う（`px-4 py-8 sm:px-6 lg:px-8`）。`lg:[margin-left:var(--app-sidebar-w)]`
  と `transition-[margin-left]` は維持。EmailVerificationBanner は現行位置維持（PageContainer の外＝shell 直下、
  もしくは PageContainer 導入後の各ページ外。詳細: banner は AppLayout が描画し PageContainer より外）。

### 波及
- 全認証ページの padding が PageContainer 依存になるため、**S2 と S3 は同一 PR で連続実施**（padding 撤去だけの
  中間状態を残さない）。テストは S3 完了時点の green で担保。

### テスト計画
- AppLayout.test（AppLayout 側で担保）: `<main>` の content ラッパ（`data-testid="app-main"` は**維持**）に
  padding utility（`px-*`/`py-*`）が付かないことを確認。既存 nav/menu/drawer テストは不変で green 維持。

---

## S3: 24 認証ページ外枠移行 + Architecture テスト

### 移行パターン（各ページ共通）
```svelte
<!-- before (T070 後) -->
<AppLayout {appName}>
    <PageContent maxWidth="2xl">
        <h1 class="text-h2">タイトル</h1>
        <p class="mt-1 text-caption text-text-secondary">説明</p>
        <div class="mt-6 ...">…本文…</div>
    </PageContent>
</AppLayout>

<!-- after (aigenba パターン) -->
<script>… import PageContainer/PageHeader(Section)/PageContent …</script>
<AppLayout {appName}>
    <PageContainer>
        <PageHeader title="タイトル" description="説明" icon={SomeIcon} testId="…-heading" />
        <PageContent>
            <div class="mt-6 ...">…本文…</div>   <!-- maxWidth prop 撤去、生 h1/description は PageHeader へ -->
        </PageContent>
    </PageContainer>
</AppLayout>
```
- actions（ボタン等ページ上部操作）や breadcrumbs があるページは `<PageHeaderSection ...>{actions}</PageHeaderSection>`。
- icon は各ページの主題に合う lucide アイコン（既存で使っていればそれ、無ければ代表アイコン）。
- **機能・操作対象の既存 testid は維持**。PageHeader の testId は見出し用（`*-heading`）。
- Workflow で並列移行（各ページ独立ファイル）。

### 例外: Capture/Show
`AppLayout > PageContainer > PageHeader > (本文を PageContent で包まず全幅)`。PageContent 必須契約から除外
（下記 Architecture テストの allowlist）。

### Architecture テスト（責務別に 2 本へ分割・T070 の page-content-usage をリネーム）
T070 の `page-content-usage.test.ts` を **`page-shell-structure.test.ts` にリネーム**し責務を明確化。
AdminMenuNav 検査は別テストに分離（Codex R1 対応）。**検査対象は「通常の開始タグ利用」に限定**し、移植 primitive は
全ページで通常タグ使用（`<svelte:component>` / 動的コンポーネントは使わない）を前提とする。

**`tests/js/architecture/page-shell-structure.test.ts`**（layout 構造契約）:
- 走査前に HTML/JS コメント除去（誤検知回避）。各 primitive の **default import 識別子を capture**（alias 対応）。
- AppLayout を import するページ（allowlist 除く）について、resolved 識別子の**通常開始タグ**
  `new RegExp('<' + escapeRegExp(ident) + '(?:\\s|/?>)')` の出現で「import かつ使用」を検査:
  (1) `PageContainer` 使用、(2) `PageHeader` **または** `PageHeaderSection` 使用、(3) `PageContent` 使用
  （allowlist 除く）。
- **padding={false} 禁止**: PageContainer の識別子 `PC` について
  `new RegExp('<' + escapeRegExp(PC) + '\\b[^>]*\\bpadding=\\{false\\}')` を検査し fail（識別子も escapeRegExp を
  通す。負マージン契約保護。属性は開始タグ内 `>` まで）。
- 失敗分類: 「PageContainer 不足/未使用 / PageHeader(Section) 不足/未使用 / PageContent 不足/未使用 / padding={false} 使用」。
- allowlist（`{ path, reason }`、reason 非空必須・機械強制）: `Capture/Show.svelte`（**PageContent 必須の除外のみ**。
  撮影レコーダー全幅。PageContainer/PageHeader は Capture/Show も必須）。

**`tests/js/architecture/deprecated-imports.test.ts`**（廃止 import）:
- **`resources/js` 全体**（pages 限定でなく別層からの再導入も防止）が `@/components/features/admin/AdminMenuNav.svelte` を import しないことを検査（撤去の構造保証）。将来の廃止 import 規約もここに集約。

### テスト計画（red → green）
- 先に `page-shell-structure.test.ts` + `deprecated-imports.test.ts` を置き fail 確認（24 ページ未移行 +
  AdminMenuNav 使用で赤）→ 移行 + S4 で green。
- 各ページ既存テストは testid/振る舞い不変で green 維持。

### リスク
- 移行漏れ → arch テストが検出。Capture/Show の全幅は目視（verify）で確認。

---

## S4: AdminMenuNav 撤去 + カテゴリ導線

### PR 契約（Codex R2 対応 — FE + BE 不要 prop 除去。後方互換の並走を残さない）
本 PR は FE 中心だが、**AdminMenuNav 撤去で不要になる Inertia prop を BE からも同一 PR で除去**する
（**認可ロジックは一切変更しない**＝ prop 受け渡しの整理のみ）。旧契約(不要 prop)を並走させない。

### 変更箇所
- 削除: `features/admin/AdminMenuNav.svelte`。
- `Admin/Users.svelte`・`Admin/Categories.svelte`: `<aside md:w-56>` + AdminMenuNav の 2 カラムを廃し、標準外枠
  （PageContainer/PageHeader/PageContent 全幅）へ。`usersUrl`/`categoriesUrl` prop を props 定義から除去。
  ユーザー管理は既存サイドバー「メンバー」で到達。
- BE: `OrganizationController@settings` / `CategoryController@index` / `Admin/UserManagementController@index` の
  `Inertia::render` から**不要になった `usersUrl` / `categoriesUrl` を除去**（`Gate::allows(...)` の評価自体を削除。
  認可判定ロジック・route・他 prop は不変）。他に当該 prop を使う画面が無いことを確認済み（AdminMenuNav 専用）。
- `Projects/Show.svelte`: **カテゴリ管理リンクを primary actions セクション末尾に 1 箇所追加**。
  href=`/projects/{project.id}/categories`、表示条件は**既存 `canManage` prop**（= `can('update', $project)`）。
  - **根拠（コード確認済・推定でない）**: `CategoryPolicy::viewAny(user, project)` は
    `projectPolicy->update(user, project)` を返す = **categories の viewAny ≡ project の update**（完全一致）。
    よって categories ページ authz（`viewAny Category`）と `canManage`（update）は同一権限であり、`canManage` で
    リンクを出すのは厳密に正しく 403 は皆無。旧 AdminMenuNav の categoriesUrl も update ゲートで挙動保存。

### 波及・テスト
- BE: 上記 3 controller の Feature テストは `usersUrl`/`categoriesUrl` prop を assert していれば除去し、代わりに
  **当該 prop が存在しないことを明示 assert**（旧契約の再混入防止。Codex R3）。認可の Feature テストは不変（ロジック不変）。
- JS: `tests/js/pages/AdminUsers.test.ts` / `AdminCategories.test.ts` は AdminMenuNav（`admin-nav-users`/
  `admin-nav-categories` testid）への assertion を**後継契約へ更新**（標準外枠が描画される・二次メニューが無い）。
  **テストは削除せず更新**（既存テスト削除禁止）。
- 新規: `deprecated-imports.test.ts`（AdminMenuNav 不使用）、Projects/Show のカテゴリリンク表示テスト
  （`canManage` 時に出る/非権限で出ない）、Feature テスト「`canManage`(=update) ユーザーが categories に到達可
  （viewAny≡update の回帰固定）」。

### テスト計画
- `Admin/Users`/`Admin/Categories` の既存テスト: AdminMenuNav（`admin-nav-users`/`admin-nav-categories` testid）
  依存を撤去し、標準外枠 + 本文が残ることを確認。
- `Projects/Show`: カテゴリ管理リンクが権限時に出る/非権限で出ない負例。
- arch テスト: `AdminMenuNav` 不使用（S3 の検査で担保）。

### リスク
- カテゴリ到達性の回帰 → Projects/Show リンクのテストで担保。users 到達は sidebar「メンバー」（既存）。

## 実装モード
| 項目 | 内容 |
|---|---|
| 推奨 | standalone |
| 根拠 | primitive 導入 + shell 変更 + 24 ページ横断 + AdminMenuNav 撤去は密結合の 1 PR。BE ロジック変更なし(不要 prop 整理のみ)で独立。 |
| 競合 | 低（BE ロジック不変・整理のみ）。全認証ページに触れるため他 UI 変更とのマージ順に注意。 |

## テスト結果
- typecheck/ESLint/pint/phpstan(0 errors)/build OK。arch 30/30(page-shell-structure/deprecated-imports 含む)。
- JS 87 files 790 tests passed / PHP 1797 tests 1795 passed 0 failed。primitive 単体 9 tests。
- Workflow: 24ページ移行 24 done/0 error。lucide unscoped 5件は @lucide/svelte へ是正済み。

## 新規 primitive
### resources/js/types/components.ts
```
/** UI コンポーネント共通の型 (aigenba types/components.ts 準拠)。 */
export interface BreadcrumbItem {
    label: string;
    /** 省略時は現在位置 (リンクにしない)。 */
    href?: string;
}
```
### resources/js/components/templates/PageContainer.svelte
```
<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * PageContainer — page 内側の薄い padding wrapper (layout primitive, aigenba 準拠)。
     * 認証ページの外周 padding を担う (AppLayout <main> は padding を持たない)。
     * padding=false は PageHeaderSection の負マージン全幅バー契約を壊すため、認証ページからは使わない
     * (Architecture テスト page-content-usage が padding={false} を禁止)。
     */
    interface Props {
        padding?: boolean;
        children?: Snippet;
    }

    let { padding = true, children }: Props = $props();
</script>

<div class="w-full {padding ? 'px-4 py-8 sm:px-6 lg:px-8' : ''}">
    {#if children}
        {@render children()}
    {/if}
</div>
```
### resources/js/components/templates/PageContent.svelte
```
<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * PageContent — 認証ページ本文の中央寄せ max-width wrapper (layout primitive, aigenba 準拠)。
     * prop を持たず常に mx-auto max-w-7xl 中央寄せ (T070 の独自 maxWidth/testId prop は撤去済み)。
     */
    interface Props {
        children: Snippet;
    }

    let { children }: Props = $props();
</script>

<div class="mx-auto max-w-7xl">
    {@render children()}
</div>
```
### resources/js/components/molecules/Breadcrumb.svelte
```
<script lang="ts">
    import { ChevronRight } from "@lucide/svelte";
    import type { BreadcrumbItem } from "@/types/components";

    /**
     * パンくず navigation molecule (aigenba 準拠)。atom 非依存 (@lucide/svelte の ChevronRight のみ)。
     * href 省略の項目は現在位置としてリンクにしない。
     */
    interface Props {
        items: BreadcrumbItem[];
    }

    let { items }: Props = $props();
</script>

<nav class="flex" aria-label="Breadcrumb">
    <ol class="flex items-center">
        {#each items as item, index (item.href ?? item.label)}
            <li class="flex items-center">
                {#if index > 0}
                    <ChevronRight class="mx-1 size-4 text-text-secondary" aria-hidden="true" />
                {/if}
                {#if item.href}
                    <a href={item.href} class="text-caption text-text-secondary hover:text-primary">
                        {item.label}
                    </a>
                {:else}
                    <span class="text-caption text-text">{item.label}</span>
                {/if}
            </li>
        {/each}
    </ol>
</nav>
```
### resources/js/components/molecules/PageHeaderSection.svelte
```
<script lang="ts">
    import type { Component, Snippet } from "svelte";
    import type { BreadcrumbItem } from "@/types/components";
    import Breadcrumb from "./Breadcrumb.svelte";

    /**
     * 詳細画面用 PageHeader (breadcrumbs / description / icon / actions(children) を持つ full feature、
     * aigenba 準拠)。root 画面 shorthand は PageHeader.svelte 経由。
     * サイドバーのロゴブロックと同じ 73px 高。全幅バーは PageContainer padding を前提とした負マージン契約。
     * actions は children Snippet で渡す (旧 slot API は使わない)。icon は lucide 互換の Component。
     */
    interface Props {
        title: string;
        breadcrumbs?: BreadcrumbItem[];
        description?: string;
        icon?: Component;
        testId?: string;
        children?: Snippet;
    }

    let { title, breadcrumbs = [], description, icon, testId, children }: Props = $props();

    // ルート画面 (パンくずが 1 件のみ) はパンくずを出さない (h1 と二重提示になるため)。
    const showBreadcrumbs = $derived(breadcrumbs.length > 1);
</script>

<div
    class="-mx-4 -mt-8 border-b border-border bg-surface px-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
>
    <div class="py-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                {#if icon}
                    {@const Icon = icon}
                    <Icon class="size-10 shrink-0 text-primary" aria-hidden="true" />
                {/if}
                <h1 class="truncate text-h2 text-text" data-testid={testId}>{title}</h1>
            </div>
            {#if children}
                <div class="flex min-w-0 shrink flex-wrap justify-end gap-2">
                    {@render children()}
                </div>
            {/if}
        </div>
    </div>
</div>

{#if showBreadcrumbs || description}
    <div
        class="-mx-4 mb-6 border-b border-border bg-surface px-4 py-2 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
    >
        {#if showBreadcrumbs}
            <Breadcrumb items={breadcrumbs} />
        {/if}
        {#if description}
            <p class="truncate text-caption text-text-secondary">{description}</p>
        {/if}
    </div>
{:else}
    <div class="mb-8"></div>
{/if}
```
### resources/js/components/molecules/PageHeader.svelte
```
<script lang="ts">
    import type { Component } from "svelte";
    import PageHeaderSection from "./PageHeaderSection.svelte";

    /**
     * ルート画面用の薄い見出しラッパー (breadcrumbs/actions 無しの shorthand、aigenba 準拠)。
     * breadcrumbs/actions を使う詳細画面は PageHeaderSection を直接使う。
     */
    interface Props {
        title: string;
        description?: string;
        icon?: Component;
        testId?: string;
    }

    let { title, description, icon, testId }: Props = $props();
</script>

<PageHeaderSection {title} {description} {icon} {testId} />
```
## arch テスト
### page-shell-structure.test.ts
```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-shell-structure — 認証ページ外枠の aigenba parity を構造保証する Architecture テスト。
 *
 * 契約: `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、aigenba の統一外枠
 *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
 * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
 * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
 *
 * 運用規約(機械強制でない・レビュー観点): 本文標準は上記外枠。ALLOWLIST 追加は理由必須。
 * (旧 page-content-usage.test.ts をリネーム。AdminMenuNav 等の廃止 import は deprecated-imports.test.ts。)
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");

/** PageContent 必須契約の除外 allowlist (PageContainer/PageHeader は必須)。追加は理由必須(reason 非空)。 */
const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
    },
];
const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));

const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

function stripComments(src: string): string {
    return src
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/(^|[^:])\/\/[^\n]*/g, "$1");
}

async function sveltePages(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

const importsAppLayout = (src: string): boolean =>
    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);

/** 指定 primitive path の default import 識別子を返す (alias 対応)。無ければ null。 */
function importIdentifier(src: string, importPath: string): string | null {
    const re = new RegExp(`import\\s+(\\w+)\\s+from\\s+["']${escapeRegExp(importPath)}["']`);
    const m = src.match(re);
    return m ? m[1] : null;
}

/** 識別子の通常開始タグが使われているか (タグ名境界まで)。 */
const usesTag = (src: string, ident: string): boolean =>
    new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`).test(src);

describe("architecture/page-shell-structure", () => {
    it("PAGECONTENT_ALLOWLIST の各エントリは理由(reason)必須", () => {
        for (const e of PAGECONTENT_ALLOWLIST) {
            expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
        }
    });

    it("AppLayout ページは PageContainer + PageHeader(Section) + PageContent を使い、padding={false} を使わない", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingContainer: string[] = [];
        const missingHeader: string[] = [];
        const missingContent: string[] = [];
        const paddingFalse: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            const src = stripComments(await fs.readFile(file, "utf8"));
            if (!importsAppLayout(src)) continue;

            // PageContainer 必須 + padding={false} 禁止
            const pc = importIdentifier(src, "@/components/templates/PageContainer.svelte");
            if (!pc || !usesTag(src, pc)) missingContainer.push(rel);
            else if (new RegExp(`<${escapeRegExp(pc)}\\b[^>]*\\bpadding=\\{false\\}`).test(src))
                paddingFalse.push(rel);

            // PageHeader または PageHeaderSection 必須
            const ph = importIdentifier(src, "@/components/molecules/PageHeader.svelte");
            const phs = importIdentifier(src, "@/components/molecules/PageHeaderSection.svelte");
            const hasHeader = (ph && usesTag(src, ph)) || (phs && usesTag(src, phs));
            if (!hasHeader) missingHeader.push(rel);

            // PageContent 必須 (allowlist 除く)
            if (!PAGECONTENT_ALLOWLIST_PATHS.has(rel)) {
                const pcnt = importIdentifier(src, "@/components/templates/PageContent.svelte");
                if (!pcnt || !usesTag(src, pcnt)) missingContent.push(rel);
            }
        }

        const msg = [
            missingContainer.length && `PageContainer 不足/未使用:\n  - ${missingContainer.join("\n  - ")}`,
            missingHeader.length && `PageHeader(Section) 不足/未使用:\n  - ${missingHeader.join("\n  - ")}`,
            missingContent.length && `PageContent 不足/未使用:\n  - ${missingContent.join("\n  - ")}`,
            paddingFalse.length && `PageContainer padding={false} は禁止:\n  - ${paddingFalse.join("\n  - ")}`,
        ].filter(Boolean).join("\n\n");
        expect(
            { missingContainer, missingHeader, missingContent, paddingFalse },
            msg,
        ).toEqual({ missingContainer: [], missingHeader: [], missingContent: [], paddingFalse: [] });
    });
});
```
### deprecated-imports.test.ts
```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * deprecated-imports — 廃止したコンポーネントが resources/js のどこからも再導入されないことを保証する。
 * T071 で AdminMenuNav(独自二次左メニュー)を退役。別層からの再導入も防ぐため resources/js 全体を走査する。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const RESOURCES_JS = path.resolve(HERE, "../../../resources/js");

/** 廃止 import: { spec, reason }。追加時は理由必須。 */
const DEPRECATED: ReadonlyArray<{ spec: string; reason: string }> = [
    {
        spec: "@/components/features/admin/AdminMenuNav.svelte",
        reason: "T071: aigenba に無い独自二次左メニュー。標準サイドバー nav + プロジェクト文脈導線へ移行し退役。",
    },
];

async function sourceFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && /\.(svelte|ts)$/.test(e.name)) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/deprecated-imports", () => {
    it("廃止エントリは理由必須", () => {
        for (const d of DEPRECATED) expect(d.reason.trim(), d.spec).not.toBe("");
    });

    it("resources/js は廃止コンポーネントを import しない", async () => {
        const files = await sourceFiles(RESOURCES_JS);
        const violations: string[] = [];
        for (const file of files) {
            const src = await fs.readFile(file, "utf8");
            for (const d of DEPRECATED) {
                if (src.includes(d.spec)) {
                    violations.push(`${path.relative(RESOURCES_JS, file)} → ${d.spec}`);
                }
            }
        }
        expect(violations, `廃止 import 検出:\n  - ${violations.join("\n  - ")}`).toEqual([]);
    });
});
```
## 差分(代表)
### AppLayout.svelte(S2 padding 移譲)
```diff
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index 8da62f6..3c1e006 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -465,11 +465,12 @@
     >
         <div class="transition-[margin-left] duration-300 lg:[margin-left:var(--app-sidebar-w)]">
             {#if showEmailBanner}
-                <div class="px-4 pt-4 lg:px-8">
+                <div class="px-4 pt-4 sm:px-6 lg:px-8">
                     <EmailVerificationBanner />
                 </div>
             {/if}
-            <div class="px-4 py-6 lg:px-8" data-testid="app-main">
+            <!-- padding は各ページの PageContainer が担う (aigenba parity, T071)。ここでは付けない。 -->
+            <div data-testid="app-main">
                 {@render children()}
             </div>
         </div>
```
### CategoryController.php + UserManagementController.php(S4 不要 prop 除去)
```diff
diff --git a/app/Http/Controllers/Admin/UserManagementController.php b/app/Http/Controllers/Admin/UserManagementController.php
index 3e5b08f..6c4e23a 100644
--- a/app/Http/Controllers/Admin/UserManagementController.php
+++ b/app/Http/Controllers/Admin/UserManagementController.php
@@ -75,11 +75,6 @@ public function index(Request $request, DefaultProjectResolver $defaultProjects)
             'members' => $members,         // list<MemberRowData>
             'invitations' => $invitations, // list<InvitationRowData>
             'hasDefaultProject' => $project !== null,
-            // 管理メニュー nav: カテゴリ管理リンク (can 連動 + project 不在は非表示)。
-            // URL は route helper で生成 (route 名変更耐性)
-            'categoriesUrl' => $project !== null && $user->can('update', $project)
-                ? route('projects.categories.index', $project)
-                : null,
         ]);
     }
 }
diff --git a/app/Http/Controllers/Projects/CategoryController.php b/app/Http/Controllers/Projects/CategoryController.php
index c04aa5e..c73c7d0 100644
--- a/app/Http/Controllers/Projects/CategoryController.php
+++ b/app/Http/Controllers/Projects/CategoryController.php
@@ -11,7 +11,6 @@
 use App\Http\Requests\Projects\UpdateCategoryRequest;
 use App\Models\Category;
 use App\Models\Project;
-use App\Models\User;
 use App\Services\Manual\CategoryService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -43,9 +42,6 @@ public function index(Request $request, Project $project): Response
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('viewAny', [Category::class, $project]);
 
-        $user = $request->user();
-        Assert::isInstanceOf($user, User::class);
-
         return Inertia::render('Admin/Categories', [
             'project' => ['id' => $project->id, 'name' => $project->name],
             // sort_order 順 (▲▼ の表示順 = 動画一覧の並び順と同一規約)
@@ -55,8 +51,6 @@ public function index(Request $request, Project $project): Response
                     'name' => $category->name,
                 ])
                 ->all()),
-            // 管理メニュー nav: ユーザー管理リンク (org 管理者のみ。can 連動。route helper で生成)
-            'usersUrl' => $user->can('manageMembers', $organization) ? route('manage.users.index') : null,
         ]);
     }
 
```
### Projects/Show.svelte(PageHeaderSection + カテゴリ導線)
```diff
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index d583b87..bb7c474 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { page, router, useForm } from "@inertiajs/svelte";
+    import { FolderKanban } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -11,10 +12,12 @@
     import DangerZone from "@/components/molecules/DangerZone.svelte";
     import EmptyState from "@/components/molecules/EmptyState.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
+    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
     import Pagination from "@/components/molecules/Pagination.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import type {
@@ -293,14 +296,13 @@
 </script>
 
 <AppLayout {appName}>
-    <PageContent maxWidth="2xl">
-        <div class="flex items-start justify-between gap-4">
-            <div class="min-w-0">
-                <h1 class="truncate text-h2">{project.name}</h1>
-                {#if project.description}
-                    <p class="mt-1 text-body text-text-secondary">{project.description}</p>
-                {/if}
-            </div>
+    <PageContainer>
+        <PageHeaderSection
+            title={project.name}
+            description={project.description ?? undefined}
+            icon={FolderKanban}
+            testId="project-show-heading"
+        >
             {#if canManage}
                 <Button
                     variant="ghost"
@@ -310,10 +312,19 @@
                 >
                     編集
                 </Button>
+                <!-- カテゴリ管理 (project-scoped)。CategoryPolicy::viewAny ≡ project update = canManage -->
+                <Button
+                    variant="ghost"
+                    href={`/projects/${project.id}/categories`}
+                    inertia
+                    testId="manage-categories-link"
+                >
+                    カテゴリ管理
+                </Button>
             {/if}
-        </div>
-
-        <div class="mt-6 flex flex-col gap-10">
+        </PageHeaderSection>
+        <PageContent>
+            <div class="mt-6 flex flex-col gap-10">
             <Card padding="lg">
                 <div class="flex items-start justify-between gap-4">
                     <h2 class="text-h3">動画マニュアル</h2>
@@ -777,4 +788,5 @@
             testId="delete-project-dialog"
         />
     </PageContent>
+    </PageContainer>
 </AppLayout>
```
### Admin/Users.svelte(AdminMenuNav aside 撤去 + 標準外枠)
```diff
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 8ffcb74..59fd677 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
     import { tick } from "svelte";
     import { page, router, useForm } from "@inertiajs/svelte";
+    import { Users } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -9,11 +10,12 @@
     import Select from "@/components/atoms/Select.svelte";
     import EmptyState from "@/components/molecules/EmptyState.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
-    import AdminMenuNav from "@/components/features/admin/AdminMenuNav.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
@@ -31,11 +33,9 @@
         members: MemberRow[];
         invitations: InvitationRow[];
         hasDefaultProject: boolean;
-        categoriesUrl: string | null;
     }
 
-    let { organizationSlug, members, invitations, hasDefaultProject, categoriesUrl }: Props =
-        $props();
+    let { organizationSlug, members, invitations, hasDefaultProject }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -251,17 +251,14 @@
 </script>
 
 <AppLayout {appName}>
-    <PageContent maxWidth="7xl">
-        <h1 class="text-h2">ユーザー管理</h1>
-        <p class="mt-1 text-caption text-text-secondary">
-            組織のメンバーと招待を管理します。ロールは「管理者・編集者・撮影者」から選択します。
-        </p>
-
-        <div class="mt-6 flex flex-col gap-6 md:flex-row md:items-start">
-            <aside class="w-full shrink-0 md:w-56">
-                <AdminMenuNav active="users" usersUrl="/manage/users" {categoriesUrl} />
-            </aside>
-
+    <PageContainer>
+        <PageHeader
+            title="ユーザー管理"
+            description="組織のメンバーと招待を管理します。ロールは「管理者・編集者・撮影者」から選択します。"
+            icon={Users}
+            testId="users-heading"
+        />
+        <PageContent>
             <div class="flex min-w-0 grow flex-col gap-10">
                 <Card padding="lg">
                     <h2 class="text-h3">メンバー一覧</h2>
@@ -458,7 +455,6 @@
                     {/if}
                 </Card>
             </div>
-        </div>
 
         <ConfirmDialog
             bind:open={removeDialogOpen}
@@ -528,5 +524,6 @@
             canSatisfy={recentAuthStatus?.canSatisfy ?? true}
             onConfirmed={resumePendingAction}
         />
-    </PageContent>
+        </PageContent>
+    </PageContainer>
 </AppLayout>
```
### Feature テスト更新(prop 不存在を明示 assert)
```diff
diff --git a/tests/Feature/Admin/UserManagementPageTest.php b/tests/Feature/Admin/UserManagementPageTest.php
index 3864f78..4a07e39 100644
--- a/tests/Feature/Admin/UserManagementPageTest.php
+++ b/tests/Feature/Admin/UserManagementPageTest.php
@@ -30,7 +30,8 @@
         ->where('invitations.0.email', 'pending-editor@example.com')
         ->where('invitations.0.roleState', 'editor')
         ->where('hasDefaultProject', false)
-        ->where('categoriesUrl', null));
+        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い categoriesUrl prop は廃止 → 存在しない
+        ->missing('categoriesUrl'));
 });
 
 test('org Admin も閲覧できる (200)', function (): void {
@@ -109,18 +110,19 @@
     });
 });
 
-test('categoriesUrl: project があり org admin なら URL・project 不在なら null', function (): void {
+test('categoriesUrl prop は撤去済み (T071: カテゴリ導線はプロジェクト詳細へ移設)。hasDefaultProject は維持', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
 
+    // AdminMenuNav 撤去に伴い categoriesUrl prop は存在しない (project 有無に関わらず)
     $this->actingAs($owner)->get('/manage/users')
-        ->assertInertia(fn ($page) => $page->where('categoriesUrl', null)->where('hasDefaultProject', false));
+        ->assertInertia(fn ($page) => $page->missing('categoriesUrl')->where('hasDefaultProject', false));
 
-    $project = Project::factory()->forOrganization($organization)->create();
+    Project::factory()->forOrganization($organization)->create();
 
     $this->actingAs($owner)->get('/manage/users')
         ->assertInertia(fn ($page) => $page
             ->where('hasDefaultProject', true)
-            ->where('categoriesUrl', route('projects.categories.index', $project)));
+            ->missing('categoriesUrl'));
 });
 
 test('招待一覧は active のみ (失効・受諾済・取消済は出ない)', function (): void {
diff --git a/tests/Feature/Projects/CategoryIndexPageTest.php b/tests/Feature/Projects/CategoryIndexPageTest.php
index 511d8d2..4e36026 100644
--- a/tests/Feature/Projects/CategoryIndexPageTest.php
+++ b/tests/Feature/Projects/CategoryIndexPageTest.php
@@ -24,21 +24,22 @@
     $response->assertInertia(fn ($page) => $page
         ->component('Admin/Categories')
         ->where('project.id', $project->id)
-        ->where('usersUrl', route('manage.users.index')));
+        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い usersUrl prop は廃止 → 存在しない
+        ->missing('usersUrl'));
 });
 
-test('project_admin (編集者。org は Member) も 200 で閲覧でき usersUrl は null', function (): void {
+test('project_admin (編集者。project update 可) も 200 で閲覧できる (viewAny≡update の回帰。usersUrl prop なし)', function (): void {
     [$organization] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
     $editor = attachOrganizationMember($organization);
     attachProjectMember($project, $editor, ProjectRole::Admin);
     $editor->forceFill(['current_organization_id' => $organization->id])->save();
 
+    // CategoryPolicy::viewAny ≡ projectPolicy->update。project を update できる編集者は categories に 200 到達する。
     $response = $this->actingAs($editor)->get("/projects/{$project->id}/categories");
 
     $response->assertOk();
-    // 編集者は org メンバー管理権限を持たない → ユーザー管理導線は非表示 (null)
-    $response->assertInertia(fn ($page) => $page->where('usersUrl', null));
+    $response->assertInertia(fn ($page) => $page->missing('usersUrl'));
 });
 
 test('project_member (撮影者) は 403', function (): void {
```
