## Round 3: Round 2 指摘への対応(残 2 内部矛盾 + 文言)

- [Warning] testid 矛盾 → 「機能・操作対象 testid は維持、T070 で足した PageContent 外枠 testid のみ撤去」に限定。
- [Warning] Capture/Show 曖昧 → **PageContent を使わない唯一の例外**に固定。arch テストは「PageContent 必須契約の
  除外(allowlist)」として定義(max-width 例外 prop は作らない)。外枠 PageContainer/PageHeader は適用。
- [Warning] PageContainer padding={false} → prop は残すが arch テストで認証ページからの padding={false} を禁止。
- [Suggestion] 効果文言 → 「認証後ページ外枠の構造 parity」に限定(BrandLogo スコープ外)。
- [Suggestion] 詳細設計送り明記 → actions=children Snippet / icon=lucide Component / カテゴリ導線= Projects/Show 1 箇所。

S1..の主方針は Round 2 で解消済み。上記反映で APPROVED になると考えます。改訂後 概念設計(全文)を再掲します。

---
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

1. 全認証ページ（AppLayout 使用）が `<PageContainer>`（既定 padding。`padding={false}` は禁止）を採用する。
2. 各ページ見出しは `<PageHeader>`（root）/ `<PageHeaderSection>`（breadcrumbs/actions 有）を採用し、
   生 `<h1 class="text-h2">` 直書きを撤去する。
3. `<PageContent>`（prop 無し `mx-auto max-w-7xl` 固定）を採用する。**Capture/Show のみ PageContent を使わない
   唯一の例外**（2 カラム grid の撮影レコーダー面 = 全幅。外枠 PageContainer/PageHeader は適用）。

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
- `<PageContainer>` で包む。**機能・操作対象の既存 testid は維持**（T070 で私が足した PageContent 外枠用
  testid のみ撤去し依存テストを更新）。フォーム/ロジックは不変（外枠・幅・見出しの構造変更のみ）。
- **例外 Capture/Show**: 撮影レコーダー面は **PageContent を使わない唯一の例外**（外枠 PageContainer/PageHeader
  は適用し、本文は PageContent なしで全幅）。Architecture テストは「PageContent 必須契約の**除外**（allowlist）」
  として定義（max-width 例外 prop は作らない）。

### ④ AdminMenuNav 撤去 + カテゴリ導線移設
- `features/admin/AdminMenuNav.svelte`（独自二次左メニュー）を**退役（削除）**。`Admin/Users`・`Admin/Categories`
  を標準外枠（`<aside>` 2 カラムを廃し全幅）に。
- ユーザー管理 `/manage/users` は **既存サイドバー「メンバー」項目で到達**（`currentOrganization.canManageMembers`
  ゲート。nav 変更・新規 shared prop 不要）。
- カテゴリ管理 `/projects/{project}/categories` は **project-scoped** のため、**プロジェクト詳細
  `Projects/Show` から到達するリンク**を設ける（1 箇所に確定。グローバルサイドバーには出さない）。既存 project
  prop（`update` 権限）で完結。

> 詳細設計で確定する事項: (1) `PageHeaderSection` の actions は **children Snippet**（aigenba 準拠。旧 slot API を
> 混入させない）、(2) `PageHeaderSection`/`PageHeader` の `icon` 型は **lucide の `Component`**（`Component<any>`
> への後退を避ける）、(3) カテゴリ導線の具体的配置（Projects/Show のどのセクションに置くか）。

## テスト計画（テストファースト: 先に fail）

- 先置き fail テスト: (a) `PageContent` が独自 prop（maxWidth/testId）を持たない、(b) **Architecture テスト**
  「AppLayout 使用ページは PageContainer（`padding={false}` 禁止）+ PageHeader(Section) を使い、PageContent 必須
  （Capture/Show のみ allowlist 除外）」（未移行で fail）、(c) `AdminMenuNav` が pages から参照されない（撤去後 green）。
- 移植 primitive の単体テスト（PageContainer padding on/off / PageHeader→PageHeaderSection / PageHeaderSection の
  icon/title/description/breadcrumbs(>1件のみ表示)/actions / Breadcrumb / max-w-7xl 固定）。
- 各ページ移行は既存ページテスト green を回帰維持（testid/振る舞い不変）。カテゴリ導線リンクの表示テスト。
- 実ブラウザ確認（verify）: 代表ページが aigenba 同等（全幅見出しバー + max-w-7xl 中央寄せ + 二次メニュー無し）。

## 期待効果

- **認証後ページ外枠の構造 parity（確実）**: 全認証ページが aigenba と同一外枠・幅・見出しに（BrandLogo は
  スコープ外のため「nav 構造まで含む完全一致」ではなく「外枠構造 parity」と限定表現）。T069/T070 の独自実装
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
