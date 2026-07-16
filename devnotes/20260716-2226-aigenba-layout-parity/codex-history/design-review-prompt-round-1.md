## 使命/禁止事項/ツール制限
AI-CUE(現場SOP起点の動画マニュアル)。テストなし完了/PHPStan widen/既存テスト削除/無駄な独自実装/後方互換並走 禁止。
コマンド実行・書き込み禁止、テキスト分析に集中(読み込み可)。
---
あなたは Laravel+Svelte アプリ改善の詳細設計レビュアー(アーキテクト)。前提 Svelte5+Inertia+TS/ds-purity/atomic-import-graph/
svg-inline-allowlist テスト強制。観点: 正確性/既存整合/テスト網羅/後退リスク/波及変更網羅/セキュリティ/DESIGN.md token/
Atomic Design(層責務・単方向import・lucide)。本件は概念 Round3 APPROVED の aigenba 外枠 parity(primitive移植+24ページ移行+
AdminMenuNav撤去)。BE変更なし想定。出力: 施策毎 APPROVE/REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion]、全体判定、日本語。
---
## 詳細設計書
# 詳細設計: aigenba-layout-parity（認証後ページ外枠を aigenba に完全一致）

## 使命・制約（絶対遵守）
- 使命: AI-CUE は現場 SOP 起点に AI 設計の動画シナリオをスマホ(PWA)でナビ撮影し標準マニュアル動画を作る。
- 禁止事項: テストなし完了 / PHPStan widen / 既存テスト削除 / `response()->json()` 直書き / 無駄な独自実装 /
  後方互換の並走を残さない。
- コーディング: PHPStan level 10（本件 BE 変更なし）。フロントは Svelte 5 runes + DS token のみ（ds-purity）、
  単方向 import（atomic-import-graph）、アイコン lucide、SVG 直書きは atoms/icons 例外のみ（svg-inline-allowlist）。
  検証: `pnpm lint/typecheck/test/build` + `composer test/phpstan` + `vendor/bin/pint --test`。

## 概念設計リファレンス
`devnotes/20260716-2226-aigenba-layout-parity/conceptual-design.md`（Round 3 APPROVED）

## 施策一覧

| # | 施策 | 変更ファイル | 優先度 |
|---|---|---|---|
| S1 | primitive 移植 + PageContent 是正 | `templates/PageContainer.svelte`(新), `templates/PageContent.svelte`(改), `molecules/{PageHeaderSection,PageHeader,Breadcrumb}.svelte`(新), `types/components.ts`(新) + 各単体テスト | 高 |
| S2 | AppLayout padding 移譲 | `templates/AppLayout.svelte` | 高 |
| S3 | 24 認証ページ外枠移行 + Architecture テスト | `resources/js/pages/**`(24), `tests/js/architecture/page-content-usage.test.ts`(改) | 高 |
| S4 | AdminMenuNav 撤去 + カテゴリ導線 | `Admin/{Users,Categories}.svelte`, `features/admin/AdminMenuNav.svelte`(削除), `Projects/Show.svelte`, 関連テスト | 高 |

**実装順序（1 PR 内・混在期間なし）**: S1(primitive) → S2(AppLayout padding 撤去) → S3(全ページ移行) → S4。

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
- `PageContent.test`（作り直し）: `mx-auto max-w-7xl` を持ち children 描画。**maxWidth prop を受けない**（型/構造）。
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
- AppLayout.test: `<main>` に padding utility が付かないこと（or PageContainer 側で担保）を確認。既存 nav/menu/
  drawer テストは不変で green 維持。

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

### Architecture テスト（`page-content-usage.test.ts` を拡張）
既存 T070 テストを aigenba パターンへ拡張（識別子ベース・コメント除去は踏襲）:
- AppLayout を import するページ（allowlist 除く）について:
  (1) `PageContainer` を import かつ使用、(2) `PageHeader` または `PageHeaderSection` を import かつ使用、
  (3) `PageContent` を import かつ使用。いずれも識別子ベースでタグ境界検査。
- **`PageContainer` を `padding={false}` で使わない**ことを検査（負マージン契約保護。`<Ident ... padding={false}` を fail）。
- allowlist（`{path, reason}`、reason 必須）: `Capture/Show.svelte`（PageContent 必須の除外。撮影レコーダー全幅）。
  ※ Capture/Show も PageContainer/PageHeader は必須（allowlist は PageContent 必須のみ除外）。
- **`AdminMenuNav` が pages から import されない**ことを検査（撤去の構造保証）。

### テスト計画（red → green）
- 先に拡張 arch テストを置き fail 確認（24 ページ未移行 + AdminMenuNav 使用で赤）→ 移行 + S4 で green。
- 各ページ既存テストは testid/振る舞い不変で green 維持。

### リスク
- 移行漏れ → arch テストが検出。Capture/Show の全幅は目視（verify）で確認。

---

## S4: AdminMenuNav 撤去 + カテゴリ導線

### 変更箇所
- 削除: `features/admin/AdminMenuNav.svelte`（+ 専用テストあれば削除）。
- `Admin/Users.svelte`・`Admin/Categories.svelte`: `<aside md:w-56>` + AdminMenuNav の 2 カラムを廃し、標準外枠
  （PageContainer/PageHeader/PageContent 全幅）へ。`categoriesUrl`/`usersUrl` prop は不要になれば除去
  （sidebar「メンバー」で users 到達、categories は下記導線）。
- `Projects/Show.svelte`: **カテゴリ管理へのリンクを 1 箇所追加**（project `update` 権限時。既存 project prop で
  出し分け。href=`/projects/{project.id}/categories`）。配置は Projects/Show のプロジェクト操作/情報セクション。

### 波及
- サーバ: `usersUrl`/`categoriesUrl` を渡していた箇所（OrganizationController/CategoryController/
  UserManagementController）は、AdminMenuNav 撤去で不要になる prop を整理（**BE ロジック変更なし・prop 受け渡しの
  除去のみ**。認可判定自体は不変）。Projects/Show が categories link を出すための権限フラグは既存 project 認可で足りるか
  確認（無ければ最小の prop 追加。§実装で確定）。

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
| 根拠 | primitive 導入 + shell 変更 + 24 ページ横断 + AdminMenuNav 撤去は密結合の 1 PR。BE 変更なしで独立。 |
| 競合 | 低（純フロント）。全認証ページに触れるため他 UI 変更とのマージ順に注意。 |

## 移植 primitive コード(実装予定・aigenba 準拠)
### components.ts
```
/** UI コンポーネント共通の型 (aigenba types/components.ts 準拠)。 */
export interface BreadcrumbItem {
    label: string;
    /** 省略時は現在位置 (リンクにしない)。 */
    href?: string;
}
```
### PageContainer.svelte
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
### PageContent.svelte
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
### Breadcrumb.svelte
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
### PageHeaderSection.svelte
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
### PageHeader.svelte
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
## 関連現行コード
### AppLayout.svelte の <main> ラッパ(S2 対象)
```svelte
                    />
                </div>
            </div>
        </aside>
    {/if}

    <!-- Main Content -->
    <main
        class="w-full flex-1 transition-all duration-300"
        style="--app-sidebar-w: {showAccountNav ? (sidebarOpen ? '256px' : '64px') : '0px'};"
    >
        <div class="transition-[margin-left] duration-300 lg:[margin-left:var(--app-sidebar-w)]">
            {#if showEmailBanner}
                <div class="px-4 pt-4 lg:px-8">
                    <EmailVerificationBanner />
                </div>
            {/if}
            <div class="px-4 py-6 lg:px-8" data-testid="app-main">
                {@render children()}
            </div>
        </div>
    </main>
</div>
```
### 既存 page-content-usage.test.ts(S3 で拡張する対象)
```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-content-usage — 認証ページのコンテンツ幅統一を構造保証する Architecture テスト。
 *
 * 契約: `AppLayout` を import するページ (= ログイン後シェルを使う認証ページ) は、本文を layout primitive
 * `PageContent` で包む (import かつ使用) こと。これにより本文幅の中央寄せ/max-width 制御が PageContent に
 * 一元化され、T069 で発生したような「各ページが独自 max-width を左寄せ」ドリフトを構造的に防ぐ。
 *
 * 運用規約 (機械強制ではない・レビュー観点):
 *  - 認証ページ本文の標準幅は maxWidth="2xl"。例外 (3xl/4xl/7xl 等) は各ページで理由をもって指定する。
 *  - ALLOWLIST への追加は理由コメント必須 (無理由追加禁止)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");

/**
 * max-width 非制約 allowlist (PageContent を課さないページ)。
 * 追加は `{ path, reason }` で行い、reason(理由)必須 = 空文字は機械的に fail する(無理由追加禁止)。
 */
const ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason: "2 カラム grid の撮影レコーダー面。カメラ/カット一覧をワイドに使うため max-width 非制約。",
    },
];
const ALLOWLIST_PATHS: ReadonlySet<string> = new Set(ALLOWLIST.map((e) => e.path));

const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** HTML コメントと JS/TS コメントを除去 (コメント内の import / <PageContent> 誤認を防ぐ)。 */
function stripComments(src: string): string {
    return src
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/(^|[^:])\/\/[^\n]*/g, "$1");
}

async function sveltePages(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) {
            out.push(path.join(e.parentPath, e.name));
        }
    }
    return out;
}

const importsAppLayout = (src: string): boolean =>
    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);

/** PageContent の default import 識別子を返す (別名 import 対応)。無ければ null。 */
function pageContentIdentifier(src: string): string | null {
    const m = src.match(/import\s+(\w+)\s+from\s+["']@\/components\/templates\/PageContent\.svelte["']/);
    return m ? m[1] : null;
}

describe("architecture/page-content-usage", () => {
    it("allowlist の各エントリは理由(reason)必須 (無理由追加禁止を機械強制)", () => {
        for (const entry of ALLOWLIST) {
            expect(entry.reason.trim(), `allowlist "${entry.path}" は理由(reason)必須`).not.toBe("");
        }
    });

    it("AppLayout を使う認証ページ (allowlist 除く) は PageContent を import かつ使用する", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingImport: string[] = [];
        const unused: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            if (ALLOWLIST_PATHS.has(rel)) continue;

            const raw = await fs.readFile(file, "utf8");
            const src = stripComments(raw);
            if (!importsAppLayout(src)) continue;

            const ident = pageContentIdentifier(src);
            if (!ident) {
                missingImport.push(rel);
                continue;
            }
            // 開始タグをタグ名境界まで検査 (接頭辞一致 <PageContentPreview> 等を排除)。
            const usage = new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`);
            if (!usage.test(src)) unused.push(rel);
        }

        expect(
            { missingImport, unused },
            [
                missingImport.length
                    ? `PageContent import 不足 (本文を <PageContent> で包むこと):\n  - ${missingImport.join("\n  - ")}`
                    : "",
                unused.length
                    ? `PageContent を import しているが未使用 (dead import。本文を <PageContent> で包むこと):\n  - ${unused.join("\n  - ")}`
                    : "",
            ]
                .filter(Boolean)
                .join("\n\n"),
        ).toEqual({ missingImport: [], unused: [] });
    });
});
```
