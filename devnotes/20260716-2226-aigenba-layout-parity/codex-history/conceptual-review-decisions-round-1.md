# 対応マトリクス: conceptual-review Round 1（CHANGES_REQUESTED, 全 Warning/Suggestion）

## [Warning] P2 BrandLogo が Guest/Auth まで含みスコープ逸脱 / [Warning][スコープ] Guest/Auth・情報設計混入
- 判断: 対応する（スコープを認証後シェルに限定。BrandLogo は本 parity から除外）
- 対応: 本 parity のスコープを **primitive 移植(PageContainer/PageHeader/PageHeaderSection/Breadcrumb+型) +
  PageContent 是正 + AppLayout padding 移譲 + 24 認証ページ外枠統一 + AdminMenuNav 撤去** に閉じる。
  **BrandLogo は除外**（AI-CUE 用ブランド SVG が別途必要 = 単なる layout でなくアセット依存。aigenba の SVG を
  機械移植すると別ブランドになる）→ 別タスク。Guest/Auth のロゴ/hero も別タスク。AppLayout は現行の
  `{appName}` テキスト wordmark のまま（本 parity ではロゴ化しない）。

## [Suggestion] DoD を DOM 構造 3 契約で明文化
- 判断: 対応する
- 対応: 完了条件(DoD)を明記: (1) 全認証ページが `<PageContainer>` を採用、(2) 見出しは
  `<PageHeader>`/`<PageHeaderSection>` を採用（生 `<h1>` 直書きを撤去）、(3) `<PageContent>` は prop 無し
  `mx-auto max-w-7xl` 固定。Capture/Show のみ (3) の例外。

## [Suggestion] テストファースト（先に fail を作る）
- 判断: 対応する
- 対応: 実装前に失敗テストを先置き: (a) PageContent が独自 prop を持たない（型/構造）、(b) AppLayout ページは
  PageContainer + PageHeader(Section) + PageContent を使う（Architecture テスト）、(c) `AdminMenuNav` 不使用。

## [Warning] P4 top-level nav 項目化は nav 生成契約の変更（shared prop / active 判定 / モバイル反映）
- 判断: 対応する（認可契約を確定。新規 shared prop は不要と確認）
- 根拠(サーバ確認済): ユーザー管理 `/manage/users` は org-scoped で `manageMembers` ゲート。**AppLayout の
  サイドバーは既に「メンバー」→ `/manage/users` を `currentOrganization.canManageMembers` で出している** ため、
  AdminMenuNav の「ユーザー管理」リンクは冗長。撤去してもサイドバー経由で到達可、**新規 shared prop 不要**。
  一方 カテゴリ管理 `/projects/{project}/categories` は **project-scoped**（`update` on project ゲート）で、
  グローバルサイドバーの top-level 項目には馴染まない。
- 対応(P4 改訂): (1) `AdminMenuNav`(二次左メニュー)を撤去し両ページを標準外枠へ。(2) ユーザー管理は既存
  サイドバー「メンバー」で到達（nav 変更なし・active 判定は既存 isActive）。(3) カテゴリ管理は **プロジェクト
  文脈(Projects/Show or Edit)から到達するリンク**を設ける（project-scoped のためグローバル nav に出さない）。
  Inertia shared data の追加は不要（既存 canManageMembers + project prop で完結）。

## [Warning] Capture/Show の「全ページ統一」と「allowlist 例外」の二重化
- 判断: 対応する
- 対応: Capture/Show を **単一の明示例外**（PageContent の max-width 非制約 = PageContent で幅を絞らない。
  外枠 PageContainer/PageHeader は適用）と 1 行で固定し、Architecture テストの allowlist に同名で反映する。

## [Warning] PageContent の testId 撤去と「既存 testid 不変」の両立
- 判断: 対応する
- 対応: PageContent の `testId`/`data-testid="page-content"` は T070 で私が足した独自 hook。**撤去する**。
  これに依存するテスト（T070 の PageContent.test / page-content-usage arch テスト / 一部ページ）は本 parity で
  作り直す（arch テストは PageContainer/PageHeader/PageContent の構造利用を検査する形へ更新。ページ側の
  振る舞いテストは PageContent の testid に依存しない = 回帰なし）。「既存 testid 不変」はページ固有要素に限る。

## [Warning] padding 移譲の途中状態で全ページ一時崩れ / 混在期間
- 判断: 対応する
- 対応: 実装を **1 PR 内の固定シーケンス**にする: ① AppLayout の `<main>` padding 撤去 + PageContainer 導入
  （primitive 追加）→ ② PageContent 是正 + PageHeader/PageHeaderSection/Breadcrumb 導入 → ③ 24 ページ外枠移行
  （Workflow 並列。各ページ内で旧外枠→新外枠へ原子的に置換）→ ④ AdminMenuNav 撤去 + カテゴリ導線。
  混在状態(旧 padding と PageHeaderSection 負マージンの併存)を残さず、全テスト green で 1 PR。

## [Warning] BrandLogo の svg-inline-allowlist / atomic import 規約
- 判断: 対応する（BrandLogo をスコープ外にしたため解消）
- 対応: BrandLogo を本 parity から除外（上記）。将来 BrandLogo を作る場合は「生 SVG は atoms/icons/ 配下、
  BrandLogo はそれを合成する薄い component」を規約とする旨だけ別タスクに申し送る。

## [Suggestion] BreadcrumbItem 型の確定
- 判断: 対応する
- 対応: `BreadcrumbItem = { label: string; href?: string }`（href 省略 = 現在位置。icon は持たせない）。
  aigenba の Breadcrumb と一致。24 ページ移行時の分岐増殖を防ぐ。

## [Suggestion] Onboarding の寄せ範囲を 1 文で限定
- 判断: 対応する
- 対応: Onboarding/Cli・Mcp は **外枠標準化のみ**（PageContainer/PageHeader/PageContent）。本文は AI-CUE 現行
  （Card + CodeSnippet 構成）を維持し、aigenba の `OnboardingGuide` 全面移植はしない（独自整形の再発も過剰移植も避ける）。

## [Suggestion] 効果に「新規ページの逸脱自動検出」を前面化 / 型安全性
- 反映: 期待効果に Architecture テストによる逸脱自動検出（保守コスト削減）を明記。
