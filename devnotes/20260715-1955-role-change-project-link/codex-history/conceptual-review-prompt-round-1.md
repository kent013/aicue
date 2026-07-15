【使命】AI-CUE: SOP 起点に AI が動画シナリオ生成、PWA 撮影で標準化マニュアル動画。思考ゼロ・編集ゼロ。単一 Default Project。
【禁止事項】1 テストなし完了禁止 / 2 PHPStan widen 禁止 / 4 response()->json 直書き禁止 / 8 必須条件未充足でボタン disabled 禁止(押下時エラー表示)。フロントは Svelte5 runes + DS token、アイコンは Lucide のみ。
【ツール制限】コマンド実行・書き込み禁止。読み込み可。
---
あなたは Laravel + Svelte 改善の概念設計レビュアーです。観点: 1 使命整合 2 禁止事項違反 3 実現可能性 4 期待効果 5 リスク 6 スコープ 7 型安全性。出力: 全体判定 APPROVED/CHANGES_REQUESTED、[Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、日本語。
---

## 概念設計
# 概念設計: role-change-project-link

## 背景・課題

`resources/js/pages/Admin/Users.svelte`(管理メニュー > ユーザー管理)では、
Default Project が存在しない (`!hasDefaultProject`) とき、メンバー一覧の上部に注記
(`data-testid="no-project-note"`)を表示する:

> プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。

同じ条件下で編集者/撮影者ロールを割り当てようとすると、サーバは 422 + 同文言を
error bag に載せて拒否する (T033)。しかし注記からは**プロジェクト作成画面
(projects.create) への導線が無く**、ユーザーは自分でグローバルナビ等から
「プロジェクト」→「作成」と辿る必要があり、導線がワンホップ遠い。

## 改善アイデア

当該注記に **projects.create への文脈リンク/ボタン**を追加し、1 ホップで
プロジェクト作成画面へ進めるようにする。注記が出る条件 (`!hasDefaultProject`) と
同じブロック内に、既存の「プロジェクトを作成」CTA と同じ流儀
(`<Button href="/projects/create" inertia>`)でリンクを置く。

## 期待効果

- 使命への貢献: 「思考ゼロ」で運用したい管理者が、ロール割当の詰まり
  (プロジェクト不在)に気づいた瞬間、その場から 1 ホップで作成画面へ進める。
  詰み→解決の動線を最短化し、初期セットアップの離脱を減らす。
- 既存の dead-end 気味な注記を actionable にする (UX 一貫性)。

## 実装方針（概要）

- `resources/js/pages/Admin/Users.svelte`: `{#if !hasDefaultProject}` の注記ブロックに
  「プロジェクトを作成」リンクを追加する。
  - 既存 CTA 流儀を踏襲: `Projects/Index.svelte` / `Dashboard.svelte` が用いる
    `<Button href="/projects/create" inertia ...>` (Button atom の href+inertia モード =
    Inertia `Link` の SPA 遷移)。アイコン/DS token は既存 Button に委譲。
  - 既存 `data-testid="no-project-note"` の注記 `<p>` はテキスト・testid とも維持し、
    リンクを兄弟要素として追加 (`data-testid="create-project-link"`)。
  - 禁止事項#8: disabled 化しない。リンクは注記表示条件下でのみ**表示**する
    (条件外は非表示 = 存在しない)。
- **純フロント**。controller / route / DTO / Props 型の変更なし
  (href は既存 `/projects/create` を直書き。同 URL は `Projects/Index.svelte` L41 でも直書き実績)。

## 制約・前提

- 権限・組織スコープ: この画面 (`/manage/users`) は `Gate::authorize('manageMembers')`
  (owner/admin) のみ閲覧可。projects.create の作成権限も `create` policy =
  owner/admin (`canManage()`) で**同一集合**のため、注記を見るユーザーは常に
  プロジェクト作成権限を持つ (導線の権限不整合は生じない)。projects.create route 自身も
  `Gate::authorize('create', [Project::class, $organization])` でサーバ側認可を行う
  (リンクは UI 導線であり認可の代替ではない)。current org スコープはサーバが解決。
- DESIGN.md: 既存 Button atom (DS token) を使い hex 直書きを増やさない。
- Atomic Design: pages 層から atoms(Button) の利用 = 単方向 import で整合。
- 既存 vitest (`AdminUsers.test.ts`) の `no-project-note` アサートを壊さない。

## スコープ外

- ロール変更 422 の error bag 文言・サーバ挙動 (T033) の変更
- projects.create 画面自体の変更
- Default Project 自動作成等のフロー変更
- 招待フォーム側 (別 Card) への導線追加 (注記のある「メンバー一覧」Card に限定)
