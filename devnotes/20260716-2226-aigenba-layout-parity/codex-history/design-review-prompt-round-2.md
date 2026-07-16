## Round 2: Round 1 指摘への対応

- S3 [Critical] regex 誤検知 → 検査対象を「通常の開始タグ利用」に限定明記。移植 primitive は全ページ通常タグ使用
  (svelte:component/動的は使わない)前提で、resolved 識別子の通常開始タグ出現のみ検査。
- S3 [Critical] padding={false} の alias → PageContainer の default import 識別子を capture し
  `<Ident\b[^>]*\bpadding=\{false\}` を検査(開始タグ内)。
- S3 [Warning] 責務混在 → arch テストを 2 本に分割: page-shell-structure(構造契約) と deprecated-imports
  (AdminMenuNav 禁止)。T070 の page-content-usage は page-shell-structure にリネーム(旧名残さない)。
- S4 [Critical] BE 契約揺れ → **完全 FE のみ・BE 変更なし**に固定。旧 usersUrl/categoriesUrl prop は BE から
  渡され続けても害無しでそのまま残す(ページ側は消費停止)。
- S4 [Warning] カテゴリリンク条件 → categories ページは viewAny 認可、旧 AdminMenuNav は update ゲート。
  update⊆viewAny のため既存 canManage(=update)で出せば 403 なし・旧挙動保存(新規 prop 不要・FE 完結)。
- S4 [Suggestion] 位置 → Projects/Show の primary actions 末尾に固定。
- 横断 [Critical] テストファースト → red→green チェックリスト明記(S1 単体 / S3 arch)。
- 横断 [Warning] 名称乖離 → page-content-usage → page-shell-structure リネーム。
- 横断 [Suggestion] 24 ページ固定リスト → 添付。
- S1 [Warning] description truncate → aigenba parity 優先で許容。S2 → app-main test contract は AppLayout.test で担保と明記。

上記反映で APPROVE 相当と考えます。更新後 詳細設計(全文)を再掲します。

---
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
3. 各ページ既存テストは testid/振る舞い不変で **green 維持**（回帰）。

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
- **padding={false} 禁止**: PageContainer の識別子 `PC` について `new RegExp('<' + PC + '\\b[^>]*\\bpadding=\\{false\\}')`
  を検査し fail（負マージン契約保護。属性は開始タグ内 `>` まで）。
- 失敗分類: 「PageContainer 不足/未使用 / PageHeader(Section) 不足/未使用 / PageContent 不足/未使用 / padding={false} 使用」。
- allowlist（`{ path, reason }`、reason 非空必須・機械強制）: `Capture/Show.svelte`（**PageContent 必須の除外のみ**。
  撮影レコーダー全幅。PageContainer/PageHeader は Capture/Show も必須）。

**`tests/js/architecture/deprecated-imports.test.ts`**（廃止 import）:
- pages が `@/components/features/admin/AdminMenuNav.svelte` を import しないことを検査（撤去の構造保証）。
  将来の廃止 import 規約もここに集約。

### テスト計画（red → green）
- 先に `page-shell-structure.test.ts` + `deprecated-imports.test.ts` を置き fail 確認（24 ページ未移行 +
  AdminMenuNav 使用で赤）→ 移行 + S4 で green。
- 各ページ既存テストは testid/振る舞い不変で green 維持。

### リスク
- 移行漏れ → arch テストが検出。Capture/Show の全幅は目視（verify）で確認。

---

## S4: AdminMenuNav 撤去 + カテゴリ導線

### PR 契約（Codex R1 対応 — 完全 FE のみ・BE 変更なしに固定）
本 PR は **完全 FE のみ**。Inertia props の追加/削除・コントローラ変更は**行わない**。

### 変更箇所
- 削除: `features/admin/AdminMenuNav.svelte`（+ 専用テストあれば削除）。
- `Admin/Users.svelte`・`Admin/Categories.svelte`: `<aside md:w-56>` + AdminMenuNav の 2 カラムを廃し、標準外枠
  （PageContainer/PageHeader/PageContent 全幅）へ。ページ側は `usersUrl`/`categoriesUrl` prop の**消費をやめる**
  （BE は触らず、渡され続けても害なし = そのまま残す）。ユーザー管理は既存サイドバー「メンバー」で到達。
- `Projects/Show.svelte`: **カテゴリ管理リンクを primary actions セクション末尾に 1 箇所追加**。
  href=`/projects/{project.id}/categories`、表示条件は**既存 `canManage` prop**（= `can('update', $project)`）。
  - 根拠: categories ページは `viewAny Category` 認可。旧 AdminMenuNav の categoriesUrl は `update` ゲートだった。
    `update ⊆ viewAny` のため既存 `canManage` で出せば 403 は起きず旧挙動を保存（FE 完結・新規 prop なし）。

### 波及
- BE 変更なし（上記契約）。新規 shared prop・controller 変更なし。`canManage`・`project` は Projects/Show の既存 prop。

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
