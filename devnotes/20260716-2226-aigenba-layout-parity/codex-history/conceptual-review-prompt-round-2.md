## Round 2: Round 1 指摘への対応

Round 1 の全 Warning/Suggestion に対応し概念設計を改訂しました。再評価をお願いします。

- [Warning] スコープ逸脱(BrandLogo/Guest/Auth) → **スコープを認証後シェルに限定**。BrandLogo は除外(AI-CUE 用
  ブランド SVG アセットが別途必要 = 別タスク)、Guest/Auth も別。AppLayout は現行 {appName} テキストのまま。
- [Suggestion] DoD → DOM 構造 3 契約を明文化(PageContainer 採用 / PageHeader(Section) 採用・生 h1 撤去 /
  PageContent は prop 無し max-w-7xl 固定、Capture/Show のみ例外)。
- [Suggestion] テストファースト → 先置き fail テスト 3 種(PageContent 独自 prop 無し / Architecture: 外枠統一 /
  AdminMenuNav 不使用)を明記。
- [Warning] P4 nav 契約 → サーバ確認済: ユーザー管理は既にサイドバー「メンバー」で到達(canManageMembers,
  新規 prop 不要)。カテゴリ管理は project-scoped のためプロジェクト文脈から到達(グローバル nav に出さない)。
  Inertia shared data 追加なし。
- [Warning] Capture/Show 二重化 → 単一の明示例外(PageContent max-width 非制約、外枠は適用)と固定し arch
  allowlist に同名反映。
- [Warning] PageContent testId 撤去 → 撤去する(T070 で私が足した独自 hook)。依存テストは本 parity で更新。
- [Warning] 混在期間 → 1 PR 内固定シーケンス ①AppLayout padding撤去+PageContainer ②PageContent是正+見出し
  primitive ③24ページ移行 ④AdminMenuNav撤去+カテゴリ導線、を明記。
- [Suggestion] BreadcrumbItem 型 → { label; href? }(href 省略=現在位置)に確定。
- [Suggestion] Onboarding → 外枠標準化のみ、本文は現行維持(OnboardingGuide 全面移植しない)。

---
## 改訂後 概念設計（全文）
# 概念設計: aigenba-layout-parity（ログイン後レイアウトを aigenba に完全一致）

> Round 1 レビュー反映済み（スコープを認証後シェルに限定 / BrandLogo・Guest・Auth 除外 / DoD 3 契約 /
> P4 認可契約の確定 / Capture/Show 単一例外 / 実装順序固定 / PageContent testId 撤去）。

## 背景・課題

監査 `devnotes/20260716-2204-aigenba-layout-audit/`(9 スライス並列 / 69 findings)が、AI-CUE のログイン後
レイアウトと参照アプリ aigenba の構造レベルの乖離を確定。T069/T070 で私が独自実装（PageContent の maxWidth
prop、各ページ narrow 幅、AdminMenuNav 温存）を重ねた結果、aigenba の統一外枠から外れている。ユーザー方針は
「UI は aigenba に完全一致・無駄な独自実装をしない」。**真の外部ブロッカーは無い**（全て aigenba から移植可能）。

## スコープ（認証後シェルに限定）

**含む**: primitive 移植（PageContainer / PageHeader / PageHeaderSection / Breadcrumb + 型）/ PageContent 是正 /
AppLayout の padding 責務移譲 / 24 認証ページの外枠統一 / AdminMenuNav 撤去（+ カテゴリ導線の移設）。

**含まない（別タスク）**: BrandLogo 化（AI-CUE 用ブランド SVG アセットが別途必要）/ Guest・Auth レイアウト /
AuthHeroSection / OrgCard・Tooltip 移植 / 層再分類（Modal・CodeSnippet）/ 独自 molecule の置換 /
Onboarding 本文の OnboardingGuide 全面移植 / bug-hunt の aigenba 視覚比較レーン。

## 完了条件（DoD — DOM 構造 3 契約）

1. 全認証ページ（AppLayout 使用）が `<PageContainer>` を採用する。
2. 各ページ見出しは `<PageHeader>`（root）/ `<PageHeaderSection>`（breadcrumbs/actions 有）を採用し、
   生 `<h1 class="text-h2">` 直書きを撤去する。
3. `<PageContent>` は **prop 無し `mx-auto max-w-7xl` 固定**。例外は Capture/Show のみ（下記）。

aigenba の統一外枠:
```
<AppLayout>
  <PageContainer>                          <!-- padding: px-4 py-8 sm:px-6 lg:px-8 -->
    <PageHeader title description icon testId />        <!-- or <PageHeaderSection ...>{actions} -->
    <PageContent>                          <!-- mx-auto max-w-7xl, prop 無し -->
      ...本文...
    </PageContent>
  </PageContainer>
</AppLayout>
```

## 実装方針（順序固定 = 1 PR 内シーケンス、混在期間を作らない）

### ① AppLayout padding 移譲 + PageContainer 導入
`templates/PageContainer.svelte`（aigenba verbatim, `padding?` prop, `px-4 py-8 sm:px-6 lg:px-8`）を移植。
AppLayout `<main>` 内 div の直接 padding（`px-4 py-6 lg:px-8`）を**撤去**（padding は PageContainer が担う）。
※ PageHeaderSection の負マージン全幅バー（`-mx-4 -mt-8 … sm:-mx-6 lg:-mx-8`）は PageContainer padding を前提に
するため、この移譲を先に確定する（移行順序の制約）。

### ② PageContent 是正 + 見出し primitive 導入
- `PageContent.svelte`: T070 の独自 `maxWidth`/`testId` prop を**撤去**し、aigenba 同一の
  `<div class="mx-auto max-w-7xl">{children}</div>`（prop 無し）に戻す。testId hook 撤去（依存テストは③で更新）。
- `molecules/PageHeaderSection.svelte`（icon/title/description/breadcrumbs/actions、負マージン全幅バー契約）、
  `molecules/PageHeader.svelte`（root shorthand）、`molecules/Breadcrumb.svelte` + `types` の
  `BreadcrumbItem = { label: string; href?: string }`（href 省略 = 現在位置）を移植。アイコン型は Svelte 5 の
  `Component`（lucide 互換。旧 `ComponentType` は使わない）。

### ③ 24 認証ページの外枠移行
AppLayout を使う全 24 ページを DoD の外枠へ統一（Workflow で並列。各ページ内で旧外枠→新外枠へ原子的に置換）:
- 生 `<h1 class="text-h2">` + inline description → `<PageHeader title description icon />`
  （actions/breadcrumbs のあるページは `<PageHeaderSection>` + actions children）。
- 独自 narrow 幅（`<PageContent maxWidth=...>`）→ `<PageContent>`（prop 無し・一律 max-w-7xl 中央寄せ）。
- `<PageContainer>` で包む。testid/フォーム/ロジックは不変（外枠・幅・見出しの構造変更のみ）。
- **例外 Capture/Show**: 2 カラム grid の撮影レコーダー面 = **PageContent の max-width 非制約**（本文を PageContent
  で幅制約しない。外枠 PageContainer/PageHeader は適用）。Architecture テストの allowlist に同名で反映。

### ④ AdminMenuNav 撤去 + カテゴリ導線移設
- `features/admin/AdminMenuNav.svelte`（独自二次左メニュー）を**退役（削除）**。`Admin/Users`・`Admin/Categories`
  を標準外枠（`<aside>` 2 カラムを廃し全幅）に。
- ユーザー管理 `/manage/users` は **既存サイドバー「メンバー」項目で到達**（`currentOrganization.canManageMembers`
  ゲート。nav 変更・新規 shared prop 不要）。
- カテゴリ管理 `/projects/{project}/categories` は **project-scoped** のため、**プロジェクト文脈（Projects/Show
  or Edit）から到達するリンク**を設ける（グローバルサイドバーには出さない）。既存 project prop で完結。

## テスト計画（テストファースト: 先に fail）

- 先置き fail テスト: (a) `PageContent` が独自 prop（maxWidth/testId）を持たない、(b) **Architecture テスト**
  「AppLayout 使用ページは PageContainer + PageHeader(Section) + PageContent を使う」（未移行で fail）、
  (c) `AdminMenuNav` が pages から参照されない（撤去後 green）。
- 移植 primitive の単体テスト（PageContainer padding on/off / PageHeader→PageHeaderSection / PageHeaderSection の
  icon/title/description/breadcrumbs(>1件のみ表示)/actions / Breadcrumb / max-w-7xl 固定）。
- 各ページ移行は既存ページテスト green を回帰維持（testid/振る舞い不変）。カテゴリ導線リンクの表示テスト。
- 実ブラウザ確認（verify）: 代表ページが aigenba 同等（全幅見出しバー + max-w-7xl 中央寄せ + 二次メニュー無し）。

## 期待効果

- **UI 完全一致（確実）**: 全認証ページが aigenba と同一外枠・幅・見出し・nav 構造に。T069/T070 の独自実装
  （maxWidth prop / narrow 幅 / AdminMenuNav）を全撤去 → 「無駄な独自実装をしない」方針に一致。
- **保守性・逸脱の自動検出**: 共通 primitive + Architecture テストで aigenba パターンを構造保証し、**新規認証
  ページ追加時の逸脱を自動検出**（見た目合わせでなく保守コスト削減）。aigenba のテンプレ更新も取り込みやすい。

## 制約・前提

- フロントは Svelte 5 runes + DS token/ramp のみ（ds-purity）。移植 primitive は DS-pure（aigenba も同 DS）。
  hex 直書き・非 token 色を持ち込まない。単方向 import（atomic-import-graph）: layout primitive=templates 層、
  見出し系=molecules 層（aigenba と同配置）。
- バックエンド変更なし（純フロント）。P4 は既存認可フラグ（canManageMembers / project update）で完結、
  **新規 shared prop 追加なし**。
- 既存の各ページ testid / フォーム / ロジックは不変（外枠・幅・見出しの構造変更のみ）。
