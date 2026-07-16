## 使命(North Star)
AI-CUE は現場 SOP 起点に AI 設計の動画シナリオをスマホ(PWA)でナビ撮影し標準マニュアル動画を作る(思考ゼロ・編集ゼロ)。
## 禁止事項(核)
PHPStan無視 / テストなし完了 / 既存テスト削除 / response()->json()直書き / オーバーエンジニアリング(独自実装を無駄に足さない)。
## 思考原則・ツール制限
先人の知恵(参照アプリ aigenba)に寄せる。今必要なものだけ。コマンド実行・書き込み禁止、テキスト分析に集中(読み込み可)。
---
あなたは Web アプリ(Laravel+Svelte)改善の概念設計レビュアー。観点: 1.使命整合 2.禁止事項 3.実現可能性(Svelte5+Inertia)
4.期待効果 5.リスク(後退) 6.スコープ適切さ 7.型安全性。
本件背景: 監査(devnotes/20260716-2204-aigenba-layout-audit)が AI-CUE ログイン後レイアウトと参照 aigenba の構造乖離を確定。
これを完全一致させる parity 作業(primitive 移植 + 24 ページ外枠移行 + 独自要素 AdminMenuNav/maxWidth prop 撤去)。
真の外部ブロッカーは無し(全て aigenba から移植可能)。フロント規約: ds-purity/atomic-import-graph/svg-inline-allowlist テスト強制。
出力: 全体判定 APPROVED/CHANGES_REQUESTED、各観点 [Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、日本語。
---
## 概念設計
# 概念設計: aigenba-layout-parity（ログイン後レイアウトを aigenba に完全一致）

## 背景・課題

監査 `devnotes/20260716-2204-aigenba-layout-audit/`(9 スライス並列 / 69 findings)が、AI-CUE のログイン後
レイアウトと参照アプリ aigenba の構造レベルの乖離を確定した。ユーザー方針は「UI は aigenba に完全一致・
無駄な独自実装をしない」。T069/T070 で独自実装(PageContent の maxWidth prop、各ページ narrow 幅の温存、
AdminMenuNav の温存)を重ねた結果、aigenba の統一外枠から外れている。

**真の外部ブロッカーは無い**(監査結論): aigenba にあり AI-CUE に無いものは全て aigenba から移植可能な
primitive/コンポーネント。よって本件は「移植 + 全ページ外枠移行 + 独自要素撤去」の parity 作業。

## 改善アイデア（aigenba の統一外枠へ完全一致）

aigenba の全認証ページ外枠は統一パターン:
```
<AppLayout>
  <PageContainer>                          <!-- padding: px-4 py-8 sm:px-6 lg:px-8 -->
    <PageHeader title description icon testId />        <!-- root 画面 -->
      または <PageHeaderSection ...>{actions}</PageHeaderSection>  <!-- 詳細画面: breadcrumbs/actions -->
    <PageContent>                          <!-- mx-auto max-w-7xl 固定・prop 無し -->
      ...本文...
    </PageContent>
  </PageContainer>
</AppLayout>
```
これに AI-CUE を一致させる。

### P1: primitive 移植 + PageContent 是正
aigenba から DS-pure な layout primitive を移植する（コードは aigenba とほぼ verbatim、AI-CUE の型/import 規約に合わせる）:
- `templates/PageContainer.svelte`（`padding?` prop, `px-4 py-8 sm:px-6 lg:px-8`）
- `molecules/PageHeaderSection.svelte`（icon/title/description/breadcrumbs/actions。**負マージン全幅バー契約**
  `-mx-4 -mt-8 … px-4 sm:-mx-6 … lg:-mx-8`。ロゴブロックと同じ 73px 高）
- `molecules/PageHeader.svelte`（root 画面 shorthand → PageHeaderSection を呼ぶ）
- `molecules/Breadcrumb.svelte` + `types` の `BreadcrumbItem`（PageHeaderSection の依存）
- `molecules/BrandLogo.svelte`（インライン SVG ロゴ。ただし AI-CUE のブランド SVG を使う。svg-inline-allowlist
  テストの `atoms/icons/` 例外規約に従い配置。ロゴ SVG が用意できない場合は暫定でテキスト wordmark を
  BrandLogo 内に閉じる = 呼び出し側は BrandLogo に統一）
- **`PageContent.svelte` 是正**: T070 の独自 `maxWidth`/`testId` prop を撤去し、aigenba 同一の
  `<div class="mx-auto max-w-7xl">{children}</div>`（prop 無し）に戻す。

アイコン型は Svelte 5 の `Component`（lucide 互換）を使う（aigenba PageHeader の旧 `ComponentType` は使わない）。

### P2: AppLayout shell の padding 責務移譲
AppLayout `<main>` 内 div の直接 padding（`px-4 py-6 lg:px-8`）を**撤去**し、padding 責務を PageContainer
（`px-4 py-8 sm:px-6 lg:px-8`）へ移す。これは PageHeaderSection の負マージン全幅バーが PageContainer padding を
前提にするため**必須の前提**(移行順序の制約)。`{appName}` テキストロゴ（AppLayout 3 箇所 + Guest/Auth）を
`BrandLogo` に置換。

### P3: 全 24 認証ページの外枠移行
AppLayout を使う全 24 ページを `AppLayout > PageContainer > PageHeader(Section) > PageContent` に統一:
- 生 `<h1 class="text-h2">` + inline description → `<PageHeader title description icon />`（詳細/一覧で
  PageHeader or PageHeaderSection を使い分け。actions/breadcrumbs のあるページは PageHeaderSection）
- 独自 narrow 幅（maxWidth prop）→ 撤去（PageContent が一律 max-w-7xl 中央寄せ）
- Workflow で並列移行（各ページ独立ファイル）。testid/フォーム/ロジックは不変（外枠と幅のみ変更）

### P4: AdminMenuNav 撤去 + nav 項目化
`Admin/Users`・`Admin/Categories` の独自二次左メニュー `AdminMenuNav`（`<aside>`）を撤去し、両ページを
標準外枠（PageContainer/PageHeader/PageContent 全幅）に。ユーザー管理・カテゴリ管理は**左サイドバーの
top-level nav 項目**に追加（aigenba のメンバー等と同じ扱い。可視条件はサーバの既存認可 = manage 権限）。
`AdminMenuNav.svelte` は退役（削除）。

### P5: 個別
- **Onboarding/Cli・Mcp**: 外枠を標準化。aigenba の `OnboardingGuide` 相当への寄せは、AI-CUE 側の機能構造
  （CodeSnippet 等）と整合する範囲で行い、過度な独自実装を足さない（詳細設計で確定）。
- **Capture/Index**: 標準外枠を適用。**Capture/Show**: 2 カラム grid の撮影レコーダー面 = **max-width 非制約**
  （PageContent で幅を絞らない）。外枠(PageContainer/PageHeader)は適用可、本文幅のみ非制約。機能本体は
  AI-CUE 固有として維持（aigenba に対応機能が無い唯一の面）。

## スコープ外（今回の parity には含めない = 低優先、別途）
- 層再分類（Modal: organisms→molecules、CodeSnippet: molecules→atoms）
- `OrgCard`/`Tooltip` の移植（該当ページ実装時に必要になれば）
- AI-CUE 独自 molecule（FormField/Tabs/Divider/DangerZone/Pagination/NotificationBell）の aigenba パターン置換
- `AuthHeroSection`（ゲスト/認証面 = ログイン後レイアウト parity のスコープ外）
- bug-hunt の「aigenba 参照 視覚比較」レーン追加（P6 として別途）

## 期待効果

- **UI 完全一致（確実）**: ログイン後の全認証ページが aigenba と同一外枠・同一幅・同一見出し・同一 nav 構造に。
  T069/T070 の独自実装(maxWidth prop, narrow 幅, AdminMenuNav)を全撤去し「無駄な独自実装をしない」方針に一致。
- **保守性**: 共通 primitive（PageContainer/PageHeader/PageContent）+ Architecture テストで aigenba パターンを
  構造保証し、以後の新規ページも自動的に一致。aigenba のテンプレ更新も取り込みやすくなる。

## 制約・前提

- フロントは Svelte 5 runes + DS token/ramp のみ（ds-purity テスト）。移植 primitive は DS-pure（aigenba も
  同 DS のため基本適合。hex 直書き・非 token 色を持ち込まない）。アイコンは lucide、SVG 直書きは
  `atoms/icons/` 例外規約（svg-inline-allowlist テスト）に従う（BrandLogo）。
- component 階層の単方向 import（atomic-import-graph テスト）。layout primitive は templates 層、見出し系は
  molecules 層（aigenba と同じ配置）。
- バックエンド変更なし想定（純フロント）。nav 項目化(P4)は既存 shared prop の認可フラグで出し分け。
- 既存の各ページ testid / フォーム / ロジックは不変（外枠・幅・見出しの構造変更のみ）。
- **移行順序の制約**: P2（padding を PageContainer へ移譲）を P3（PageHeaderSection 利用）より先に確定する。

## テスト計画（概要・テストファースト）

- 移植 primitive の単体テスト（PageContainer padding / PageHeader→PageHeaderSection / PageHeaderSection の
  icon/title/description/breadcrumbs/actions / Breadcrumb / BrandLogo）。
- Architecture テスト更新: 既存 `page-content-usage` を「AppLayout ページは PageContainer + PageHeader(Section)
  + PageContent を使う」へ拡張（aigenba パターンの構造保証）。allowlist（Capture/Show の max-width 非制約 等）。
- 各ページ移行は既存ページテスト green を回帰維持（testid/振る舞い不変）。nav 項目化(P4)の可視条件テスト。
- 実ブラウザ確認（verify）: 代表ページが aigenba 同等の外枠(全幅見出しバー + max-w-7xl 中央寄せ)になること。
